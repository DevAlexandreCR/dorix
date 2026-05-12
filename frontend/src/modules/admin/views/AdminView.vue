<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import EmptyState from '../../../components/ui/EmptyState.vue';
import ForbiddenState from '../../../components/ui/ForbiddenState.vue';
import FormField from '../../../components/ui/FormField.vue';
import InlineAlert from '../../../components/ui/InlineAlert.vue';
import LoadingState from '../../../components/ui/LoadingState.vue';
import StatusBadge from '../../../components/ui/StatusBadge.vue';
import SurfaceCard from '../../../components/ui/SurfaceCard.vue';
import { useNavigationAccess } from '../../../composables/useNavigationAccess';
import { useSession } from '../../../composables/useSession';
import { useTenantSelection } from '../../../composables/useTenantSelection';
import { eventPreview, formatBytes } from '../../../lib/formatters';
import {
  createTenant,
  createTenantUser,
  createWhatsAppLine,
  deleteLineAgentConfig,
  deleteLineToolConfig,
  deleteTenantUser,
  deleteWhatsAppLine,
  fetchAdminOverview,
  fetchAdminTenants,
  retryDataSourceImport,
  updateLineAgentConfig,
  updateLineToolConfig,
  updateTenant,
  updateTenantAgentConfig,
  updateTenantToolConfig,
  updateTenantUser,
  updateWhatsAppLine,
  uploadDataSource,
  upsertCredential,
} from '../api';
import AdminSectionTabs from '../components/AdminSectionTabs.vue';
import { isLegacyAdminPanel, normalizeAdminPanel, type AdminPanelKey } from '../presentation';
import type {
  AdminOverview,
  AgentConfigRecord,
  AgentModelOption,
  DataSourceRecord,
  TenantRecord,
  ToolConfigRecord,
  WhatsAppLineRecord,
} from '../types';

type AgentConfigForm = {
  name: string;
  modelKey: string;
  promptVersion: string;
  agentPackKey: string;
  isActive: boolean;
  automationEnabled: boolean;
  systemPrompt: string;
  handoffCustomerMessage: string;
};

type ToolConfigForm = {
  enabled: boolean;
  timeoutSeconds: string;
  dataSourceId: string;
};

type LineForm = {
  name: string;
  phoneNumberId: string;
  displayPhoneNumber: string;
  wabaId: string;
  status: string;
  isEnabled: boolean;
};

type UsageSummary = {
  tenantUsesSource: boolean;
  inheritedLines: WhatsAppLineRecord[];
  customizedLines: WhatsAppLineRecord[];
};

const toolCopyKeys: Record<string, { title: string; description: string }> = {
  search_knowledge: {
    title: 'admin.dataSources.toolLabels.search_knowledge.title',
    description: 'admin.dataSources.toolLabels.search_knowledge.description',
  },
  search_inventory: {
    title: 'admin.dataSources.toolLabels.search_inventory.title',
    description: 'admin.dataSources.toolLabels.search_inventory.description',
  },
};

const { t, locale } = useI18n();
const route = useRoute();
const router = useRouter();
const { refreshSession } = useSession();
const { selectedMembership, selectedTenantId } = useTenantSelection();
const {
  canAccessAdmin,
  canManageAgentConfig,
  canManagePlatform,
  canManageTenant,
  canManageTenantUsers,
} = useNavigationAccess(selectedMembership);

const adminLoading = ref(false);
const adminSaving = ref(false);
const adminError = ref<string | null>(null);
const adminSuccess = ref<string | null>(null);
const adminOverview = ref<AdminOverview | null>(null);
const adminTenants = ref<TenantRecord[]>([]);

const tenantForm = ref({
  name: '',
  slug: '',
  status: 'active',
});
const createTenantForm = ref({
  name: '',
  slug: '',
  status: 'active',
});
const newTenantUserForm = ref({
  name: '',
  email: '',
  password: '',
  role: 'operator',
});
const tenantUserRoles = ref<Record<number, string>>({});
const lineDrafts = ref<Record<number, LineForm>>({});
const newLineForm = ref<LineForm>(defaultLineForm());
const tenantAgentConfigForm = ref<AgentConfigForm>(defaultAgentConfigForm());
const lineAgentConfigDrafts = ref<Record<number, AgentConfigForm>>({});
const tenantToolDrafts = ref<Record<string, ToolConfigForm>>({});
const lineToolDrafts = ref<Record<string, ToolConfigForm>>({});
const credentialForm = ref({
  scopeType: 'tenant',
  whatsappLineId: '',
  provider: 'whatsapp_meta',
  credentialKey: 'access_token',
  secret: '',
});
const uploadDataSourceName = ref('');
const uploadDataSourceFile = ref<File | null>(null);

const panelOptions = computed(() => [
  { key: 'tenant', label: t('admin.panels.tenant') },
  { key: 'users', label: t('admin.panels.users') },
  { key: 'lines', label: t('admin.panels.lines') },
  { key: 'agent', label: t('admin.panels.agent') },
  { key: 'sources', label: t('admin.panels.sources') },
  { key: 'credentials', label: t('admin.panels.credentials') },
  { key: 'logs', label: t('admin.panels.logs') },
]);

function routeString(key: string): string {
  const value = route.query[key];

  if (typeof value === 'string') {
    return value;
  }

  if (Array.isArray(value) && typeof value[0] === 'string') {
    return value[0];
  }

  return '';
}

const activePanel = computed<AdminPanelKey>({
  get: () => normalizeAdminPanel(routeString('panel')),
  set: (value) => {
    void router.replace({
      query: {
        ...route.query,
        panel: value,
      },
    });
  },
});

const agentPackOptions = computed(() => adminOverview.value?.available_agent_packs ?? []);
const modelOptions = computed(() => adminOverview.value?.available_models ?? []);
const bindingTools = computed(() => {
  const overview = adminOverview.value;

  if (!overview) {
    return [];
  }

  const supportedToolNames = overview.available_tools
    .filter((tool) => tool.supports_data_source_binding)
    .map((tool) => tool.name);

  return overview.binding_tools.filter((toolName) => supportedToolNames.includes(toolName));
});
const readyDataSources = computed(() =>
  (adminOverview.value?.data_sources ?? []).filter((source) => source.status === 'ready'),
);

function defaultLineForm(): LineForm {
  return {
    name: '',
    phoneNumberId: '',
    displayPhoneNumber: '',
    wabaId: '',
    status: 'inactive',
    isEnabled: false,
  };
}

function preferredModelKey(options: AgentModelOption[] = []): string {
  return options.find((option) => option.recommended)?.key ?? options[0]?.key ?? 'balanced';
}

function findModelOption(modelKey: string): AgentModelOption | null {
  return modelOptions.value.find((option) => option.key === modelKey) ?? null;
}

function defaultAgentConfigForm(
  name = '',
  agentPackKey = 'sales_support_v1',
  modelKey = 'balanced',
): AgentConfigForm {
  return {
    name,
    modelKey,
    promptVersion: 'v1',
    agentPackKey,
    isActive: true,
    automationEnabled: true,
    systemPrompt: '',
    handoffCustomerMessage: '',
  };
}

function defaultToolConfigForm(record?: ToolConfigRecord | null): ToolConfigForm {
  return {
    enabled: record?.enabled ?? true,
    timeoutSeconds: record?.timeout_seconds ? String(record.timeout_seconds) : '',
    dataSourceId: record?.data_source_id ? String(record.data_source_id) : '',
  };
}

function agentConfigFormFromRecord(
  record: AgentConfigRecord | null | undefined,
  fallbackName: string,
  fallbackAgentPackKey = 'sales_support_v1',
  fallbackModelKey = preferredModelKey(),
): AgentConfigForm {
  if (!record) {
    return defaultAgentConfigForm(fallbackName, fallbackAgentPackKey, fallbackModelKey);
  }

  return {
    name: record.name,
    modelKey: record.model_key ?? record.effective_model_key ?? fallbackModelKey,
    promptVersion: record.prompt_version ?? 'v1',
    agentPackKey: record.agent_pack_key || fallbackAgentPackKey,
    isActive: record.is_active,
    automationEnabled: record.automation_enabled,
    systemPrompt: record.system_prompt,
    handoffCustomerMessage: record.handoff_customer_message,
  };
}

function resolveTenantAgentConfig(overview: AdminOverview): AgentConfigRecord | null {
  return overview.agent_configs.find((config) => config.scope_type === 'tenant') ?? null;
}

function resolveLineAgentConfig(overview: AdminOverview, lineId: number): AgentConfigRecord | null {
  return overview.agent_configs.find(
    (config) => config.scope_type === 'whatsapp_line' && config.whatsapp_line_id === lineId,
  ) ?? null;
}

function resolveEffectiveLineAgentConfig(overview: AdminOverview, lineId: number): AgentConfigRecord | null {
  return resolveLineAgentConfig(overview, lineId) ?? resolveTenantAgentConfig(overview);
}

function resolveTenantToolConfig(overview: AdminOverview, toolName: string): ToolConfigRecord | null {
  return overview.tool_configs.find(
    (config) => config.scope_type === 'tenant' && config.tool_name === toolName,
  ) ?? null;
}

function resolveLineToolConfig(overview: AdminOverview, lineId: number, toolName: string): ToolConfigRecord | null {
  return overview.tool_configs.find(
    (config) =>
      config.scope_type === 'whatsapp_line' &&
      config.whatsapp_line_id === lineId &&
      config.tool_name === toolName,
  ) ?? null;
}

function resolveEffectiveLineToolConfig(
  overview: AdminOverview,
  lineId: number,
  toolName: string,
): ToolConfigRecord | null {
  return resolveLineToolConfig(overview, lineId, toolName) ?? resolveTenantToolConfig(overview, toolName);
}

function toolDraftKey(lineId: number, toolName: string): string {
  return `${lineId}:${toolName}`;
}

function syncAdminForms(overview: AdminOverview): void {
  const fallbackAgentPackKey = overview.available_agent_packs[0]?.key ?? 'sales_support_v1';
  const fallbackModelKey = preferredModelKey(overview.available_models);

  tenantForm.value = {
    name: overview.tenant.name,
    slug: overview.tenant.slug,
    status: overview.tenant.status,
  };

  tenantUserRoles.value = Object.fromEntries(
    overview.tenant_users.map((membership) => [membership.id, membership.role ?? 'viewer']),
  );

  lineDrafts.value = Object.fromEntries(
    overview.whatsapp_lines.map((line) => [
      line.id,
      {
        name: line.name,
        phoneNumberId: line.phone_number_id,
        displayPhoneNumber: line.display_phone_number ?? '',
        wabaId: line.waba_id ?? '',
        status: line.status,
        isEnabled: line.is_enabled,
      },
    ]),
  );

  tenantAgentConfigForm.value = agentConfigFormFromRecord(
    resolveTenantAgentConfig(overview),
    `${overview.tenant.name} Assistant`,
    fallbackAgentPackKey,
    fallbackModelKey,
  );

  lineAgentConfigDrafts.value = Object.fromEntries(
    overview.whatsapp_lines.map((line) => [
      line.id,
      agentConfigFormFromRecord(
        resolveEffectiveLineAgentConfig(overview, line.id),
        line.name,
        fallbackAgentPackKey,
        fallbackModelKey,
      ),
    ]),
  );

  tenantToolDrafts.value = Object.fromEntries(
    overview.binding_tools.map((toolName) => [toolName, defaultToolConfigForm(resolveTenantToolConfig(overview, toolName))]),
  );

  lineToolDrafts.value = Object.fromEntries(
    overview.whatsapp_lines.flatMap((line) =>
      overview.binding_tools.map((toolName) => [
        toolDraftKey(line.id, toolName),
        defaultToolConfigForm(resolveEffectiveLineToolConfig(overview, line.id, toolName)),
      ]),
    ),
  );
}

function formatTimestamp(value: string | null): string {
  if (!value) {
    return t('common.noDate');
  }

  return new Intl.DateTimeFormat(locale.value, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(value));
}

function lineLabel(line: Pick<WhatsAppLineRecord, 'name' | 'display_phone_number'> | null): string {
  if (!line) {
    return t('admin.shared.generalLabel');
  }

  return `${line.name}${line.display_phone_number ? ` · ${line.display_phone_number}` : ''}`;
}

function translateRole(role: string | null | undefined): string {
  return t(`common.roles.${role ?? 'unknown'}`);
}

function translateDataSourceStatus(status: string): string {
  return t(`common.dataSourceStatuses.${status}`);
}

function translateLineStatus(isEnabled: boolean): string {
  return t(`common.lineStatus.${isEnabled ? 'enabled' : 'disabled'}`);
}

function translateCredentialStatus(hasSecret: boolean): string {
  return t(`common.credentialStatus.${hasSecret ? 'configured' : 'empty'}`);
}

function translateScope(scope: 'tenant' | 'whatsapp_line'): string {
  return t(`common.scopes.${scope}`);
}

function translateToolName(toolName: string): string {
  const entry = toolCopyKeys[toolName];

  return entry ? t(entry.title) : toolName;
}

function translateToolDescription(toolName: string): string {
  const entry = toolCopyKeys[toolName];

  return entry ? t(entry.description) : toolName;
}

function resolveErrorMessage(error: unknown, fallbackKey: string): string {
  return error instanceof Error && error.message !== '' ? error.message : t(fallbackKey);
}

function sourceStatusTone(status: string): 'danger' | 'neutral' | 'success' | 'warning' {
  switch (status) {
    case 'ready':
      return 'success';
    case 'failed':
      return 'danger';
    case 'pending':
    case 'importing':
      return 'warning';
    default:
      return 'neutral';
  }
}

function sourceStatusHeadline(source: DataSourceRecord): string {
  return t(`admin.dataSources.statusHeadlines.${source.status}`);
}

function sourceStatusDescription(source: DataSourceRecord): string {
  return t(`admin.dataSources.statusDescriptions.${source.status}`);
}

function latestSourceDate(source: DataSourceRecord): string | null {
  return source.last_synced_at ?? source.latest_upload?.created_at ?? source.latest_import?.finished_at ?? null;
}

function sourceCurrentFileLabel(source: DataSourceRecord): string {
  return source.latest_upload?.original_name || t('common.noFile');
}

function sourceSupportDetails(source: DataSourceRecord): string {
  const details = {
    import_status: source.latest_import?.status ?? null,
    raw_error: source.latest_import?.error_message ?? null,
    metadata: source.latest_import?.metadata ?? {},
  };

  return JSON.stringify(details, null, 2);
}

function hasSourceSupportDetails(source: DataSourceRecord): boolean {
  return Boolean(source.latest_import?.error_message) || Object.keys(source.latest_import?.metadata ?? {}).length > 0;
}

function resolveSourceName(dataSourceId: number | null): string {
  if (!dataSourceId) {
    return t('admin.dataSources.noSourceSelected');
  }

  const source = adminOverview.value?.data_sources.find((item) => item.id === dataSourceId);

  return source?.name ?? t('common.notAvailable');
}

function lineHasAgentCustomization(lineId: number): boolean {
  const overview = adminOverview.value;

  return overview ? resolveLineAgentConfig(overview, lineId) !== null : false;
}

function lineHasToolCustomization(lineId: number, toolName: string): boolean {
  const overview = adminOverview.value;

  return overview ? resolveLineToolConfig(overview, lineId, toolName) !== null : false;
}

function effectiveLineAgentConfig(lineId: number): AgentConfigRecord | null {
  const overview = adminOverview.value;

  return overview ? resolveEffectiveLineAgentConfig(overview, lineId) : null;
}

function effectiveLineToolConfig(lineId: number, toolName: string): ToolConfigRecord | null {
  const overview = adminOverview.value;

  return overview ? resolveEffectiveLineToolConfig(overview, lineId, toolName) : null;
}

function assistantModeLabel(lineId: number): string {
  return lineHasAgentCustomization(lineId)
    ? t('admin.agentConfig.customizationStatus.customized')
    : t('admin.agentConfig.customizationStatus.general');
}

function assistantEnabledLabel(record: AgentConfigRecord | null): string {
  return record?.is_active
    ? t('admin.agentConfig.statusLabels.active')
    : t('admin.agentConfig.statusLabels.paused');
}

function assistantAutomationLabel(record: AgentConfigRecord | null): string {
  return record?.automation_enabled
    ? t('admin.agentConfig.statusLabels.automaticOn')
    : t('admin.agentConfig.statusLabels.automaticOff');
}

function assistantModelSummary(record: AgentConfigRecord | null, customized: boolean): string {
  const modelLabel = record?.effective_model_label ?? findModelOption(preferredModelKey(modelOptions.value))?.label ?? '';

  return customized
    ? t('admin.agentConfig.summary.customized', { model: modelLabel })
    : t('admin.agentConfig.summary.general', { model: modelLabel });
}

function assistantSummary(lineId: number): string {
  const record = effectiveLineAgentConfig(lineId);
  const customized = lineHasAgentCustomization(lineId);

  return `${assistantModelSummary(record, customized)} · ${assistantAutomationLabel(record)} · ${assistantEnabledLabel(record)}`;
}

function toolSetupStatusLabel(toolName: string): string {
  const record = adminOverview.value ? resolveTenantToolConfig(adminOverview.value, toolName) : null;

  if (!record?.enabled) {
    return t('admin.dataSources.useStatuses.paused');
  }

  if (record?.data_source_id) {
    return t('admin.dataSources.useStatuses.ready');
  }

  return t('admin.dataSources.useStatuses.missingSource');
}

function lineToolModeLabel(lineId: number, toolName: string): string {
  return lineHasToolCustomization(lineId, toolName)
    ? t('admin.dataSources.customizationStatus.customized')
    : t('admin.dataSources.customizationStatus.general');
}

function lineToolSummary(lineId: number, toolName: string): string {
  const record = effectiveLineToolConfig(lineId, toolName);
  const sourceName = resolveSourceName(record?.data_source_id ?? null);

  return `${record?.enabled ? t('admin.dataSources.lineStatus.enabled') : t('admin.dataSources.lineStatus.disabled')} · ${t('admin.dataSources.currentSource', { sourceName })}`;
}

function usageSummary(sourceId: number, toolName: string): UsageSummary {
  const overview = adminOverview.value;

  if (!overview) {
    return {
      tenantUsesSource: false,
      inheritedLines: [],
      customizedLines: [],
    };
  }

  const tenantConfig = resolveTenantToolConfig(overview, toolName);
  const tenantUsesSource = tenantConfig?.data_source_id === sourceId;
  const inheritedLines: WhatsAppLineRecord[] = [];
  const customizedLines: WhatsAppLineRecord[] = [];

  overview.whatsapp_lines.forEach((line) => {
    const lineConfig = resolveLineToolConfig(overview, line.id, toolName);
    const effectiveConfig = lineConfig ?? tenantConfig;

    if (effectiveConfig?.data_source_id !== sourceId) {
      return;
    }

    if (lineConfig) {
      customizedLines.push(line);
      return;
    }

    inheritedLines.push(line);
  });

  return {
    tenantUsesSource,
    inheritedLines,
    customizedLines,
  };
}

function usageSummaryLabel(sourceId: number, toolName: string): string {
  const summary = usageSummary(sourceId, toolName);

  if (summary.tenantUsesSource || summary.inheritedLines.length > 0 || summary.customizedLines.length > 0) {
    return t('admin.dataSources.usageReady');
  }

  return t('admin.dataSources.usageIdle');
}

function formatLineList(lines: WhatsAppLineRecord[]): string {
  return lines.map((line) => lineLabel(line)).join(', ');
}

async function loadAdminWorkspace(): Promise<void> {
  if (!selectedTenantId.value || !canAccessAdmin.value) {
    adminOverview.value = null;
    adminTenants.value = [];
    return;
  }

  adminLoading.value = true;
  adminError.value = null;

  try {
    const payload = await fetchAdminOverview(selectedTenantId.value);
    adminOverview.value = payload.data;
    syncAdminForms(payload.data);
    adminTenants.value = canManagePlatform.value ? (await fetchAdminTenants()).data : [];
  } catch (error) {
    adminError.value = resolveErrorMessage(error, 'admin.loadFailed');
  } finally {
    adminLoading.value = false;
  }
}

async function withAdminAction(successMessage: string, action: () => Promise<void>): Promise<void> {
  adminSaving.value = true;
  adminError.value = null;
  adminSuccess.value = null;

  try {
    await action();
    adminSuccess.value = successMessage;
  } catch (error) {
    adminError.value = resolveErrorMessage(error, 'admin.actionFailed');
  } finally {
    adminSaving.value = false;
  }
}

async function saveTenantSettings(): Promise<void> {
  if (!selectedTenantId.value) {
    return;
  }

  const tenantId = selectedTenantId.value;

  await withAdminAction(t('admin.success.tenantUpdated'), async () => {
    await updateTenant(tenantId, {
      name: tenantForm.value.name.trim(),
      slug: tenantForm.value.slug.trim(),
      status: tenantForm.value.status.trim(),
    });

    await refreshSession();
    await loadAdminWorkspace();
  });
}

async function createNewTenant(): Promise<void> {
  await withAdminAction(t('admin.success.tenantCreated'), async () => {
    const response = await createTenant({
      name: createTenantForm.value.name.trim(),
      slug: createTenantForm.value.slug.trim(),
      status: createTenantForm.value.status.trim(),
    });

    createTenantForm.value = {
      name: '',
      slug: '',
      status: 'active',
    };

    await refreshSession();
    activePanel.value = 'tenant';
    await router.replace({
      query: {
        ...route.query,
        tenant: String(response.data.id),
        panel: 'tenant',
      },
    });
  });
}

async function addTenantUser(): Promise<void> {
  if (!selectedTenantId.value) {
    return;
  }

  const tenantId = selectedTenantId.value;

  await withAdminAction(t('admin.success.tenantUserAdded'), async () => {
    await createTenantUser(tenantId, {
      name: newTenantUserForm.value.name.trim(),
      email: newTenantUserForm.value.email.trim(),
      password: newTenantUserForm.value.password.trim() || undefined,
      role: newTenantUserForm.value.role,
    });

    newTenantUserForm.value = {
      name: '',
      email: '',
      password: '',
      role: 'operator',
    };

    await loadAdminWorkspace();
  });
}

async function saveTenantUserRole(tenantUserId: number): Promise<void> {
  if (!selectedTenantId.value) {
    return;
  }

  const tenantId = selectedTenantId.value;

  await withAdminAction(t('admin.success.roleUpdated'), async () => {
    await updateTenantUser(tenantId, tenantUserId, {
      role: tenantUserRoles.value[tenantUserId] ?? 'viewer',
    });

    await loadAdminWorkspace();
  });
}

async function removeTenantUser(tenantUserId: number): Promise<void> {
  if (!selectedTenantId.value) {
    return;
  }

  const tenantId = selectedTenantId.value;

  await withAdminAction(t('admin.success.tenantUserRemoved'), async () => {
    await deleteTenantUser(tenantId, tenantUserId);
    await loadAdminWorkspace();
  });
}

async function createLine(): Promise<void> {
  if (!selectedTenantId.value) {
    return;
  }

  const tenantId = selectedTenantId.value;

  await withAdminAction(t('admin.success.lineCreated'), async () => {
    await createWhatsAppLine(tenantId, {
      name: newLineForm.value.name.trim(),
      phone_number_id: newLineForm.value.phoneNumberId.trim(),
      display_phone_number: newLineForm.value.displayPhoneNumber.trim() || undefined,
      waba_id: newLineForm.value.wabaId.trim() || undefined,
      status: newLineForm.value.status.trim(),
      is_enabled: newLineForm.value.isEnabled,
    });

    newLineForm.value = defaultLineForm();
    await loadAdminWorkspace();
  });
}

async function saveLine(lineId: number): Promise<void> {
  if (!selectedTenantId.value) {
    return;
  }

  const tenantId = selectedTenantId.value;
  const draft = lineDrafts.value[lineId];

  if (!draft) {
    return;
  }

  await withAdminAction(t('admin.success.lineUpdated'), async () => {
    await updateWhatsAppLine(tenantId, lineId, {
      name: draft.name.trim(),
      phone_number_id: draft.phoneNumberId.trim(),
      display_phone_number: draft.displayPhoneNumber.trim() || undefined,
      waba_id: draft.wabaId.trim() || undefined,
      status: draft.status.trim(),
      is_enabled: draft.isEnabled,
    });

    await loadAdminWorkspace();
  });
}

async function removeLine(lineId: number): Promise<void> {
  if (!selectedTenantId.value) {
    return;
  }

  const tenantId = selectedTenantId.value;

  await withAdminAction(t('admin.success.lineDeleted'), async () => {
    await deleteWhatsAppLine(tenantId, lineId);
    await loadAdminWorkspace();
  });
}

async function saveTenantAgentSettings(): Promise<void> {
  if (!selectedTenantId.value) {
    return;
  }

  const tenantId = selectedTenantId.value;

  await withAdminAction(t('admin.success.tenantAssistantUpdated'), async () => {
    await updateTenantAgentConfig(tenantId, {
      name: tenantAgentConfigForm.value.name.trim(),
      model_key: tenantAgentConfigForm.value.modelKey || undefined,
      prompt_version: tenantAgentConfigForm.value.promptVersion.trim() || undefined,
      is_active: tenantAgentConfigForm.value.isActive,
      automation_enabled: tenantAgentConfigForm.value.automationEnabled,
      system_prompt: tenantAgentConfigForm.value.systemPrompt.trim() || undefined,
      agent_pack_key: tenantAgentConfigForm.value.agentPackKey.trim() || undefined,
      handoff_customer_message: tenantAgentConfigForm.value.handoffCustomerMessage.trim() || undefined,
    });

    await loadAdminWorkspace();
  });
}

async function saveLineAgentSettings(lineId: number): Promise<void> {
  if (!selectedTenantId.value) {
    return;
  }

  const tenantId = selectedTenantId.value;
  const draft = lineAgentConfigDrafts.value[lineId];

  if (!draft) {
    return;
  }

  await withAdminAction(
    lineHasAgentCustomization(lineId)
      ? t('admin.success.lineAssistantUpdated')
      : t('admin.success.lineAssistantCreated'),
    async () => {
      await updateLineAgentConfig(tenantId, lineId, {
        name: draft.name.trim(),
        model_key: draft.modelKey || undefined,
        prompt_version: draft.promptVersion.trim() || undefined,
        is_active: draft.isActive,
        automation_enabled: draft.automationEnabled,
        system_prompt: draft.systemPrompt.trim() || undefined,
        agent_pack_key: draft.agentPackKey.trim() || undefined,
        handoff_customer_message: draft.handoffCustomerMessage.trim() || undefined,
      });

      await loadAdminWorkspace();
    },
  );
}

async function removeLineAgentCustomization(lineId: number): Promise<void> {
  if (!selectedTenantId.value) {
    return;
  }

  const tenantId = selectedTenantId.value;

  await withAdminAction(t('admin.success.lineAssistantRemoved'), async () => {
    await deleteLineAgentConfig(tenantId, lineId);
    await loadAdminWorkspace();
  });
}

async function saveTenantToolBinding(toolName: string): Promise<void> {
  if (!selectedTenantId.value) {
    return;
  }

  const tenantId = selectedTenantId.value;
  const draft = tenantToolDrafts.value[toolName];

  if (!draft) {
    return;
  }

  await withAdminAction(
    t('admin.success.generalSourceUsageUpdated', { area: translateToolName(toolName) }),
    async () => {
      await updateTenantToolConfig(tenantId, toolName, {
        enabled: draft.enabled,
        timeout_seconds: draft.timeoutSeconds ? Number(draft.timeoutSeconds) : null,
        data_source_id: draft.dataSourceId ? Number(draft.dataSourceId) : null,
      });

      await loadAdminWorkspace();
    },
  );
}

async function saveLineToolBinding(lineId: number, toolName: string): Promise<void> {
  if (!selectedTenantId.value) {
    return;
  }

  const tenantId = selectedTenantId.value;
  const draft = lineToolDrafts.value[toolDraftKey(lineId, toolName)];

  if (!draft) {
    return;
  }

  await withAdminAction(
    lineHasToolCustomization(lineId, toolName)
      ? t('admin.success.lineSourceUsageUpdated', { area: translateToolName(toolName) })
      : t('admin.success.lineSourceUsageCreated', { area: translateToolName(toolName) }),
    async () => {
      await updateLineToolConfig(tenantId, lineId, toolName, {
        enabled: draft.enabled,
        timeout_seconds: draft.timeoutSeconds ? Number(draft.timeoutSeconds) : null,
        data_source_id: draft.dataSourceId ? Number(draft.dataSourceId) : null,
      });

      await loadAdminWorkspace();
    },
  );
}

async function removeLineToolCustomization(lineId: number, toolName: string): Promise<void> {
  if (!selectedTenantId.value) {
    return;
  }

  const tenantId = selectedTenantId.value;

  await withAdminAction(
    t('admin.success.lineSourceUsageRemoved', { area: translateToolName(toolName) }),
    async () => {
      await deleteLineToolConfig(tenantId, lineId, toolName);
      await loadAdminWorkspace();
    },
  );
}

async function saveCredential(): Promise<void> {
  if (!selectedTenantId.value) {
    return;
  }

  const tenantId = selectedTenantId.value;

  await withAdminAction(t('admin.success.credentialSaved'), async () => {
    await upsertCredential(tenantId, {
      scope_type: credentialForm.value.scopeType as 'tenant' | 'whatsapp_line',
      whatsapp_line_id:
        credentialForm.value.scopeType === 'whatsapp_line' && credentialForm.value.whatsappLineId
          ? Number(credentialForm.value.whatsappLineId)
          : null,
      provider: credentialForm.value.provider.trim(),
      credential_key: credentialForm.value.credentialKey.trim(),
      secret: credentialForm.value.secret,
    });

    credentialForm.value.secret = '';
    await loadAdminWorkspace();
  });
}

function onDataSourceFileChange(event: Event): void {
  const input = event.target as HTMLInputElement;
  uploadDataSourceFile.value = input.files?.[0] ?? null;
}

async function submitDataSource(): Promise<void> {
  if (!selectedTenantId.value || !uploadDataSourceFile.value) {
    return;
  }

  const tenantId = selectedTenantId.value;
  const file = uploadDataSourceFile.value;

  await withAdminAction(t('admin.success.dataSourceUploaded'), async () => {
    await uploadDataSource(tenantId, {
      name: uploadDataSourceName.value.trim(),
      file,
    });

    uploadDataSourceName.value = '';
    uploadDataSourceFile.value = null;
    await loadAdminWorkspace();
  });
}

async function retryImport(source: DataSourceRecord): Promise<void> {
  if (!selectedTenantId.value || !source.latest_import) {
    return;
  }

  const tenantId = selectedTenantId.value;
  const latestImport = source.latest_import;

  await withAdminAction(t('admin.success.importRetried'), async () => {
    await retryDataSourceImport(tenantId, source.id, latestImport.id);
    await loadAdminWorkspace();
  });
}

watch(
  () => routeString('panel'),
  async (panel) => {
    if (!isLegacyAdminPanel(panel)) {
      return;
    }

    await router.replace({
      query: {
        ...route.query,
        panel: 'sources',
      },
    });
  },
  { immediate: true },
);

watch(
  [selectedTenantId, () => canAccessAdmin.value],
  async () => {
    adminSuccess.value = null;
    adminError.value = null;
    await loadAdminWorkspace();
  },
  { immediate: true },
);
</script>

<template>
  <section v-if="!selectedMembership">
    <SurfaceCard>
      <EmptyState :title="t('states.noMembershipsTitle')" :description="t('admin.selectTenant')" />
    </SurfaceCard>
  </section>

  <section v-else-if="!canAccessAdmin">
    <SurfaceCard>
      <ForbiddenState :title="t('states.restrictedTitle')" :description="t('admin.noAccess')" />
    </SurfaceCard>
  </section>

  <section v-else-if="adminLoading && !adminOverview">
    <SurfaceCard>
      <LoadingState :label="t('admin.loading')" />
    </SurfaceCard>
  </section>

  <section v-else-if="adminOverview" class="space-y-5">
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
      <SurfaceCard padding="sm">
        <p class="text-xs uppercase tracking-[0.2em] text-[var(--text-muted)]">{{ t('admin.summary.tenant') }}</p>
        <strong class="mt-3 block text-xl">{{ adminOverview.tenant.name }}</strong>
        <p class="mt-2 text-sm text-[var(--text-muted)]">{{ adminOverview.tenant.status }}</p>
      </SurfaceCard>

      <SurfaceCard padding="sm">
        <p class="text-xs uppercase tracking-[0.2em] text-[var(--text-muted)]">{{ t('admin.summary.lines') }}</p>
        <strong class="mt-3 block text-xl">{{ adminOverview.whatsapp_lines.length }}</strong>
        <p class="mt-2 text-sm text-[var(--text-muted)]">
          {{ t('admin.summary.enabledLines', { count: adminOverview.whatsapp_lines.filter((line) => line.is_enabled).length }) }}
        </p>
      </SurfaceCard>

      <SurfaceCard padding="sm">
        <p class="text-xs uppercase tracking-[0.2em] text-[var(--text-muted)]">{{ t('admin.summary.knowledgeSources') }}</p>
        <strong class="mt-3 block text-xl">{{ adminOverview.data_sources.length }}</strong>
        <p class="mt-2 text-sm text-[var(--text-muted)]">
          {{ t('admin.summary.readySources', { count: adminOverview.data_sources.filter((source) => source.status === 'ready').length }) }}
        </p>
      </SurfaceCard>

      <SurfaceCard padding="sm">
        <p class="text-xs uppercase tracking-[0.2em] text-[var(--text-muted)]">{{ t('admin.summary.credentials') }}</p>
        <strong class="mt-3 block text-xl">{{ adminOverview.credential_metadata.length }}</strong>
        <p class="mt-2 text-sm text-[var(--text-muted)]">
          {{ t('admin.summary.configuredCredentials', { count: adminOverview.credential_metadata.filter((item) => item.has_secret).length }) }}
        </p>
      </SurfaceCard>
    </div>

    <SurfaceCard>
      <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
          <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-[var(--accent)]">
            {{ t('admin.tab') }}
          </p>
          <h2 class="mt-2 text-2xl font-semibold tracking-tight">{{ selectedMembership.tenant_name }}</h2>
        </div>

        <AdminSectionTabs v-model="activePanel" :options="panelOptions" />
      </div>

      <div class="mt-5 grid gap-3">
        <InlineAlert v-if="adminError" :message="adminError" tone="danger" />
        <InlineAlert v-if="adminSuccess" :message="adminSuccess" tone="success" />
      </div>
    </SurfaceCard>

    <SurfaceCard v-if="activePanel === 'tenant'" padding="lg">
      <div class="flex items-start justify-between gap-3">
        <div>
          <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-[var(--accent)]">{{ t('admin.tenant.eyebrow') }}</p>
          <h3 class="mt-2 text-xl font-semibold">{{ t('admin.tenant.title') }}</h3>
        </div>
        <StatusBadge :label="adminOverview.tenant.slug" tone="neutral" />
      </div>

      <form class="mt-6 grid gap-4" @submit.prevent="saveTenantSettings">
        <div class="grid gap-4 md:grid-cols-3">
          <FormField :label="t('admin.tenant.name')">
            <input v-model="tenantForm.name" class="input-base" type="text" :disabled="!canManageTenant || adminSaving" />
          </FormField>
          <FormField :label="t('admin.tenant.slug')">
            <input v-model="tenantForm.slug" class="input-base" type="text" :disabled="!canManageTenant || adminSaving" />
          </FormField>
          <FormField :label="t('admin.tenant.status')">
            <input v-model="tenantForm.status" class="input-base" type="text" :disabled="!canManageTenant || adminSaving" />
          </FormField>
        </div>

        <button class="btn-primary w-full justify-center md:w-auto" type="submit" :disabled="!canManageTenant || adminSaving">
          {{ t('admin.tenant.save') }}
        </button>
      </form>

      <div v-if="canManagePlatform" class="mt-8 rounded-[24px] border p-5" :style="{ borderColor: 'var(--border)' }">
        <div class="flex items-center justify-between gap-3">
          <strong>{{ t('admin.tenant.createTitle') }}</strong>
          <span class="text-sm text-[var(--text-muted)]">{{ t('admin.tenant.visible', { count: adminTenants.length }) }}</span>
        </div>

        <form class="mt-5 grid gap-4" @submit.prevent="createNewTenant">
          <div class="grid gap-4 md:grid-cols-3">
            <FormField :label="t('admin.tenant.name')">
              <input v-model="createTenantForm.name" class="input-base" type="text" :disabled="adminSaving" />
            </FormField>
            <FormField :label="t('admin.tenant.slug')">
              <input v-model="createTenantForm.slug" class="input-base" type="text" :disabled="adminSaving" />
            </FormField>
            <FormField :label="t('admin.tenant.status')">
              <input v-model="createTenantForm.status" class="input-base" type="text" :disabled="adminSaving" />
            </FormField>
          </div>

          <button class="btn-secondary w-full justify-center md:w-auto" type="submit" :disabled="adminSaving">
            {{ t('admin.tenant.create') }}
          </button>
        </form>
      </div>
    </SurfaceCard>

    <SurfaceCard v-else-if="activePanel === 'users'" padding="lg">
      <div>
        <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-[var(--accent)]">{{ t('admin.tenantUsers.eyebrow') }}</p>
        <h3 class="mt-2 text-xl font-semibold">{{ t('admin.tenantUsers.title') }}</h3>
      </div>

      <div class="mt-6 grid gap-4">
        <article v-for="membership in adminOverview.tenant_users" :key="membership.id" class="rounded-[24px] border p-4" :style="{ borderColor: 'var(--border)' }">
          <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
              <strong>{{ membership.user?.name || t('admin.tenantUsers.noProfile') }}</strong>
              <p class="mt-1 text-sm text-[var(--text-muted)]">{{ membership.user?.email }}</p>
            </div>
            <StatusBadge :label="translateRole(membership.role)" tone="neutral" />
          </div>

          <div class="mt-4 grid gap-4 lg:grid-cols-[minmax(0,280px)_auto_auto] lg:items-end">
            <FormField :label="t('admin.tenantUsers.role')">
              <select v-model="tenantUserRoles[membership.id]" class="input-base" :disabled="!canManageTenantUsers || adminSaving">
                <option v-for="role in adminOverview.available_roles" :key="role" :value="role">
                  {{ translateRole(role) }}
                </option>
              </select>
            </FormField>

            <button class="btn-secondary" type="button" :disabled="!canManageTenantUsers || adminSaving" @click="saveTenantUserRole(membership.id)">
              {{ t('admin.tenantUsers.saveRole') }}
            </button>

            <button class="btn-danger" type="button" :disabled="!canManageTenantUsers || adminSaving" @click="removeTenantUser(membership.id)">
              {{ t('admin.tenantUsers.remove') }}
            </button>
          </div>
        </article>
      </div>

      <form class="mt-8 rounded-[24px] border p-5" :style="{ borderColor: 'var(--border)' }" @submit.prevent="addTenantUser">
        <strong>{{ t('admin.tenantUsers.addTitle') }}</strong>

        <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
          <FormField :label="t('admin.tenant.name')">
            <input v-model="newTenantUserForm.name" class="input-base" type="text" :disabled="!canManageTenantUsers || adminSaving" />
          </FormField>
          <FormField :label="t('auth.email')">
            <input v-model="newTenantUserForm.email" class="input-base" type="email" :disabled="!canManageTenantUsers || adminSaving" />
          </FormField>
          <FormField :label="t('auth.password')">
            <input v-model="newTenantUserForm.password" class="input-base" type="password" :disabled="!canManageTenantUsers || adminSaving" />
          </FormField>
          <FormField :label="t('admin.tenantUsers.role')">
            <select v-model="newTenantUserForm.role" class="input-base" :disabled="!canManageTenantUsers || adminSaving">
              <option v-for="role in adminOverview.available_roles" :key="role" :value="role">
                {{ translateRole(role) }}
              </option>
            </select>
          </FormField>
        </div>

        <button class="btn-primary mt-5 w-full justify-center md:w-auto" type="submit" :disabled="!canManageTenantUsers || adminSaving">
          {{ t('admin.tenantUsers.addToTenant') }}
        </button>
      </form>
    </SurfaceCard>

    <SurfaceCard v-else-if="activePanel === 'lines'" padding="lg">
      <div>
        <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-[var(--accent)]">{{ t('admin.lines.eyebrow') }}</p>
        <h3 class="mt-2 text-xl font-semibold">{{ t('admin.lines.title') }}</h3>
      </div>

      <div class="mt-6 grid gap-4">
        <article v-for="line in adminOverview.whatsapp_lines" :key="line.id" class="rounded-[24px] border p-4" :style="{ borderColor: 'var(--border)' }">
          <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
              <strong>{{ line.name }}</strong>
              <p class="mt-1 text-sm text-[var(--text-muted)]">{{ line.display_phone_number || line.phone_number_id }}</p>
            </div>
            <StatusBadge :label="translateLineStatus(line.is_enabled)" :status="line.is_enabled ? 'BOT_ACTIVE' : 'CLOSED'" />
          </div>

          <div v-if="lineDrafts[line.id]" class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <FormField :label="t('admin.tenant.name')">
              <input v-model="lineDrafts[line.id].name" class="input-base" type="text" :disabled="!canManageTenant || adminSaving" />
            </FormField>
            <FormField :label="t('admin.lines.phoneNumberId')">
              <input v-model="lineDrafts[line.id].phoneNumberId" class="input-base" type="text" :disabled="!canManageTenant || adminSaving" />
            </FormField>
            <FormField :label="t('admin.lines.displayPhone')">
              <input v-model="lineDrafts[line.id].displayPhoneNumber" class="input-base" type="text" :disabled="!canManageTenant || adminSaving" />
            </FormField>
            <FormField :label="t('admin.lines.wabaId')">
              <input v-model="lineDrafts[line.id].wabaId" class="input-base" type="text" :disabled="!canManageTenant || adminSaving" />
            </FormField>
            <FormField :label="t('admin.tenant.status')">
              <input v-model="lineDrafts[line.id].status" class="input-base" type="text" :disabled="!canManageTenant || adminSaving" />
            </FormField>
            <label class="flex items-end gap-3 rounded-2xl border px-4 py-3 text-sm" :style="{ borderColor: 'var(--border)' }">
              <input v-model="lineDrafts[line.id].isEnabled" type="checkbox" class="h-4 w-4" :disabled="!canManageTenant || adminSaving" />
              <span>{{ t('admin.lines.automationEnabled') }}</span>
            </label>
          </div>

          <div class="mt-5 flex flex-wrap gap-3">
            <button class="btn-secondary" type="button" :disabled="!canManageTenant || adminSaving" @click="saveLine(line.id)">
              {{ t('admin.lines.save') }}
            </button>
            <button class="btn-danger" type="button" :disabled="!canManageTenant || adminSaving" @click="removeLine(line.id)">
              {{ t('admin.lines.delete') }}
            </button>
          </div>
        </article>
      </div>

      <form class="mt-8 rounded-[24px] border p-5" :style="{ borderColor: 'var(--border)' }" @submit.prevent="createLine">
        <strong>{{ t('admin.lines.createTitle') }}</strong>
        <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          <FormField :label="t('admin.tenant.name')">
            <input v-model="newLineForm.name" class="input-base" type="text" :disabled="!canManageTenant || adminSaving" />
          </FormField>
          <FormField :label="t('admin.lines.phoneNumberId')">
            <input v-model="newLineForm.phoneNumberId" class="input-base" type="text" :disabled="!canManageTenant || adminSaving" />
          </FormField>
          <FormField :label="t('admin.lines.displayPhone')">
            <input v-model="newLineForm.displayPhoneNumber" class="input-base" type="text" :disabled="!canManageTenant || adminSaving" />
          </FormField>
          <FormField :label="t('admin.lines.wabaId')">
            <input v-model="newLineForm.wabaId" class="input-base" type="text" :disabled="!canManageTenant || adminSaving" />
          </FormField>
          <FormField :label="t('admin.tenant.status')">
            <input v-model="newLineForm.status" class="input-base" type="text" :disabled="!canManageTenant || adminSaving" />
          </FormField>
          <label class="flex items-end gap-3 rounded-2xl border px-4 py-3 text-sm" :style="{ borderColor: 'var(--border)' }">
            <input v-model="newLineForm.isEnabled" type="checkbox" class="h-4 w-4" :disabled="!canManageTenant || adminSaving" />
            <span>{{ t('admin.lines.enabled') }}</span>
          </label>
        </div>

        <button class="btn-primary mt-5 w-full justify-center md:w-auto" type="submit" :disabled="!canManageTenant || adminSaving">
          {{ t('admin.lines.create') }}
        </button>
      </form>
    </SurfaceCard>

    <SurfaceCard v-else-if="activePanel === 'agent'" padding="lg">
      <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div>
          <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-[var(--accent)]">{{ t('admin.agentConfig.eyebrow') }}</p>
          <h3 class="mt-2 text-xl font-semibold">{{ t('admin.agentConfig.title') }}</h3>
          <p class="mt-2 max-w-3xl text-sm text-[var(--text-muted)]">{{ t('admin.agentConfig.description') }}</p>
        </div>
        <StatusBadge
          :label="tenantAgentConfigForm.isActive ? t('admin.agentConfig.statusLabels.active') : t('admin.agentConfig.statusLabels.paused')"
          :tone="tenantAgentConfigForm.isActive ? 'success' : 'warning'"
        />
      </div>

      <form class="mt-6 grid gap-4" @submit.prevent="saveTenantAgentSettings">
        <div class="grid gap-4 md:grid-cols-2">
          <label class="flex items-start gap-3 rounded-[24px] border px-4 py-4 text-sm" :style="{ borderColor: 'var(--border)' }">
            <input v-model="tenantAgentConfigForm.isActive" type="checkbox" class="mt-1 h-4 w-4" :disabled="!canManageAgentConfig || adminSaving" />
            <div>
              <strong class="block">{{ t('admin.agentConfig.activeLabel') }}</strong>
              <p class="mt-1 text-[var(--text-muted)]">{{ t('admin.agentConfig.activeHelp') }}</p>
            </div>
          </label>

          <label class="flex items-start gap-3 rounded-[24px] border px-4 py-4 text-sm" :style="{ borderColor: 'var(--border)' }">
            <input v-model="tenantAgentConfigForm.automationEnabled" type="checkbox" class="mt-1 h-4 w-4" :disabled="!canManageAgentConfig || adminSaving" />
            <div>
              <strong class="block">{{ t('admin.agentConfig.automationLabel') }}</strong>
              <p class="mt-1 text-[var(--text-muted)]">{{ t('admin.agentConfig.automationHelp') }}</p>
            </div>
          </label>
        </div>

        <FormField :label="t('admin.agentConfig.modelLabel')" :hint="t('admin.agentConfig.modelHelp')">
          <select v-model="tenantAgentConfigForm.modelKey" class="input-base" :disabled="!canManageAgentConfig || adminSaving">
            <option v-for="option in modelOptions" :key="option.key" :value="option.key">
              {{ option.label }}
            </option>
          </select>
        </FormField>

        <div
          v-if="findModelOption(tenantAgentConfigForm.modelKey)"
          class="rounded-[24px] border px-4 py-4"
          :style="{ borderColor: 'var(--border)' }"
        >
          <div class="flex flex-wrap items-center gap-2">
            <strong>{{ findModelOption(tenantAgentConfigForm.modelKey)?.label }}</strong>
            <StatusBadge
              v-if="findModelOption(tenantAgentConfigForm.modelKey)?.recommended"
              :label="t('admin.agentConfig.recommendedBadge')"
              tone="success"
            />
          </div>
          <p class="mt-2 text-sm text-[var(--text-muted)]">{{ findModelOption(tenantAgentConfigForm.modelKey)?.description }}</p>
        </div>

        <FormField :label="t('admin.agentConfig.systemPromptLabel')" :hint="t('admin.agentConfig.systemPromptHelp')">
          <textarea v-model="tenantAgentConfigForm.systemPrompt" class="input-base min-h-40 resize-y" rows="5" :disabled="!canManageAgentConfig || adminSaving" />
        </FormField>

        <FormField :label="t('admin.agentConfig.handoffLabel')" :hint="t('admin.agentConfig.handoffHelp')">
          <textarea v-model="tenantAgentConfigForm.handoffCustomerMessage" class="input-base min-h-28 resize-y" rows="4" :disabled="!canManageAgentConfig || adminSaving" />
        </FormField>

        <details class="rounded-[24px] border p-5" :style="{ borderColor: 'var(--border)' }">
          <summary class="cursor-pointer text-sm font-semibold">{{ t('admin.shared.advancedTitle') }}</summary>
          <p class="mt-2 text-sm text-[var(--text-muted)]">{{ t('admin.agentConfig.advancedDescription') }}</p>

          <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <FormField :label="t('admin.agentConfig.internalName')">
              <input v-model="tenantAgentConfigForm.name" class="input-base" type="text" :disabled="!canManageAgentConfig || adminSaving" />
            </FormField>
            <FormField :label="t('admin.agentConfig.modelIdLabel')" :hint="t('admin.agentConfig.modelIdHelp')">
              <input :value="findModelOption(tenantAgentConfigForm.modelKey)?.model_id ?? ''" class="input-base" type="text" disabled readonly />
            </FormField>
            <FormField :label="t('admin.agentConfig.promptVersionLabel')">
              <input v-model="tenantAgentConfigForm.promptVersion" class="input-base" type="text" :disabled="!canManageAgentConfig || adminSaving" />
            </FormField>
            <FormField :label="t('admin.agentConfig.agentPackLabel')">
              <select v-if="agentPackOptions.length > 0" v-model="tenantAgentConfigForm.agentPackKey" class="input-base" :disabled="!canManageAgentConfig || adminSaving">
                <option v-for="option in agentPackOptions" :key="option.key" :value="option.key">
                  {{ option.name }}
                </option>
              </select>
              <input v-else v-model="tenantAgentConfigForm.agentPackKey" class="input-base" type="text" :disabled="!canManageAgentConfig || adminSaving" />
            </FormField>
          </div>
        </details>

        <button class="btn-primary w-full justify-center md:w-auto" type="submit" :disabled="!canManageAgentConfig || adminSaving">
          {{ t('admin.agentConfig.saveTenant') }}
        </button>
      </form>

      <div class="mt-8">
        <div class="flex items-center justify-between gap-3">
          <div>
            <strong>{{ t('admin.agentConfig.linesTitle') }}</strong>
            <p class="mt-1 text-sm text-[var(--text-muted)]">{{ t('admin.agentConfig.linesDescription') }}</p>
          </div>
        </div>

        <div class="mt-5 grid gap-4">
          <article v-for="line in adminOverview.whatsapp_lines" :key="line.id" class="rounded-[24px] border p-5" :style="{ borderColor: 'var(--border)' }">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
              <div>
                <strong>{{ lineLabel(line) }}</strong>
                <p class="mt-1 text-sm text-[var(--text-muted)]">{{ assistantSummary(line.id) }}</p>
              </div>
              <StatusBadge :label="assistantModeLabel(line.id)" tone="neutral" />
            </div>

            <p class="mt-4 text-sm text-[var(--text-muted)]">{{ t('admin.agentConfig.lineHelper') }}</p>

            <div v-if="lineAgentConfigDrafts[line.id]" class="mt-5 grid gap-4">
              <div class="grid gap-4 md:grid-cols-2">
                <label class="flex items-start gap-3 rounded-[24px] border px-4 py-4 text-sm" :style="{ borderColor: 'var(--border)' }">
                  <input v-model="lineAgentConfigDrafts[line.id].isActive" type="checkbox" class="mt-1 h-4 w-4" :disabled="!canManageAgentConfig || adminSaving" />
                  <div>
                    <strong class="block">{{ t('admin.agentConfig.activeLabel') }}</strong>
                    <p class="mt-1 text-[var(--text-muted)]">{{ t('admin.agentConfig.activeHelp') }}</p>
                  </div>
                </label>

                <label class="flex items-start gap-3 rounded-[24px] border px-4 py-4 text-sm" :style="{ borderColor: 'var(--border)' }">
                  <input v-model="lineAgentConfigDrafts[line.id].automationEnabled" type="checkbox" class="mt-1 h-4 w-4" :disabled="!canManageAgentConfig || adminSaving" />
                  <div>
                    <strong class="block">{{ t('admin.agentConfig.automationLabel') }}</strong>
                    <p class="mt-1 text-[var(--text-muted)]">{{ t('admin.agentConfig.automationHelp') }}</p>
                  </div>
                </label>
              </div>

              <FormField :label="t('admin.agentConfig.systemPromptLabel')" :hint="t('admin.agentConfig.systemPromptHelp')">
                <textarea v-model="lineAgentConfigDrafts[line.id].systemPrompt" class="input-base min-h-32 resize-y" rows="4" :disabled="!canManageAgentConfig || adminSaving" />
              </FormField>

              <FormField :label="t('admin.agentConfig.modelLabel')" :hint="t('admin.agentConfig.modelHelp')">
                <select v-model="lineAgentConfigDrafts[line.id].modelKey" class="input-base" :disabled="!canManageAgentConfig || adminSaving">
                  <option v-for="option in modelOptions" :key="option.key" :value="option.key">
                    {{ option.label }}
                  </option>
                </select>
              </FormField>

              <div
                v-if="findModelOption(lineAgentConfigDrafts[line.id].modelKey)"
                class="rounded-[24px] border px-4 py-4"
                :style="{ borderColor: 'var(--border)' }"
              >
                <div class="flex flex-wrap items-center gap-2">
                  <strong>{{ findModelOption(lineAgentConfigDrafts[line.id].modelKey)?.label }}</strong>
                  <StatusBadge
                    v-if="findModelOption(lineAgentConfigDrafts[line.id].modelKey)?.recommended"
                    :label="t('admin.agentConfig.recommendedBadge')"
                    tone="success"
                  />
                </div>
                <p class="mt-2 text-sm text-[var(--text-muted)]">{{ findModelOption(lineAgentConfigDrafts[line.id].modelKey)?.description }}</p>
              </div>

              <FormField :label="t('admin.agentConfig.handoffLabel')" :hint="t('admin.agentConfig.handoffHelp')">
                <textarea v-model="lineAgentConfigDrafts[line.id].handoffCustomerMessage" class="input-base min-h-24 resize-y" rows="3" :disabled="!canManageAgentConfig || adminSaving" />
              </FormField>

              <details class="rounded-[24px] border p-5" :style="{ borderColor: 'var(--border)' }">
                <summary class="cursor-pointer text-sm font-semibold">{{ t('admin.shared.advancedTitle') }}</summary>
                <p class="mt-2 text-sm text-[var(--text-muted)]">{{ t('admin.agentConfig.advancedDescription') }}</p>

                <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                  <FormField :label="t('admin.agentConfig.internalName')">
                    <input v-model="lineAgentConfigDrafts[line.id].name" class="input-base" type="text" :disabled="!canManageAgentConfig || adminSaving" />
                  </FormField>
                  <FormField :label="t('admin.agentConfig.modelIdLabel')" :hint="t('admin.agentConfig.modelIdHelp')">
                    <input :value="findModelOption(lineAgentConfigDrafts[line.id].modelKey)?.model_id ?? ''" class="input-base" type="text" disabled readonly />
                  </FormField>
                  <FormField :label="t('admin.agentConfig.promptVersionLabel')">
                    <input v-model="lineAgentConfigDrafts[line.id].promptVersion" class="input-base" type="text" :disabled="!canManageAgentConfig || adminSaving" />
                  </FormField>
                  <FormField :label="t('admin.agentConfig.agentPackLabel')">
                    <select v-if="agentPackOptions.length > 0" v-model="lineAgentConfigDrafts[line.id].agentPackKey" class="input-base" :disabled="!canManageAgentConfig || adminSaving">
                      <option v-for="option in agentPackOptions" :key="option.key" :value="option.key">
                        {{ option.name }}
                      </option>
                    </select>
                    <input v-else v-model="lineAgentConfigDrafts[line.id].agentPackKey" class="input-base" type="text" :disabled="!canManageAgentConfig || adminSaving" />
                  </FormField>
                </div>
              </details>
            </div>

            <div class="mt-5 flex flex-wrap gap-3">
              <button class="btn-secondary" type="button" :disabled="!canManageAgentConfig || adminSaving" @click="saveLineAgentSettings(line.id)">
                {{ lineHasAgentCustomization(line.id) ? t('admin.agentConfig.saveCustomization') : t('admin.agentConfig.createCustomization') }}
              </button>
              <button v-if="lineHasAgentCustomization(line.id)" class="btn-danger" type="button" :disabled="!canManageAgentConfig || adminSaving" @click="removeLineAgentCustomization(line.id)">
                {{ t('admin.agentConfig.removeCustomization') }}
              </button>
            </div>
          </article>
        </div>
      </div>
    </SurfaceCard>

    <SurfaceCard v-else-if="activePanel === 'sources'" padding="lg">
      <div>
        <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-[var(--accent)]">{{ t('admin.dataSources.eyebrow') }}</p>
        <h3 class="mt-2 text-xl font-semibold">{{ t('admin.dataSources.title') }}</h3>
        <p class="mt-2 max-w-3xl text-sm text-[var(--text-muted)]">{{ t('admin.dataSources.description') }}</p>
      </div>

      <div class="mt-6 grid gap-4 xl:grid-cols-3">
        <article v-for="step in ['upload', 'review', 'use']" :key="step" class="rounded-[24px] border p-5" :style="{ borderColor: 'var(--border)' }">
          <span class="inline-flex h-8 w-8 items-center justify-center rounded-full border text-sm font-semibold" :style="{ borderColor: 'var(--border)' }">
            {{ step === 'upload' ? 1 : step === 'review' ? 2 : 3 }}
          </span>
          <strong class="mt-4 block">{{ t(`admin.dataSources.steps.${step}.title`) }}</strong>
          <p class="mt-2 text-sm text-[var(--text-muted)]">{{ t(`admin.dataSources.steps.${step}.description`) }}</p>
        </article>
      </div>

      <form class="mt-8 rounded-[24px] border p-5" :style="{ borderColor: 'var(--border)' }" @submit.prevent="submitDataSource">
        <div class="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <strong>{{ t('admin.dataSources.uploadTitle') }}</strong>
            <p class="mt-1 text-sm text-[var(--text-muted)]">{{ t('admin.dataSources.uploadDescription') }}</p>
          </div>
        </div>

        <div class="mt-5 grid gap-4 md:grid-cols-2">
          <FormField :label="t('admin.dataSources.visibleName')">
            <input v-model="uploadDataSourceName" class="input-base" type="text" :disabled="!canManageAgentConfig || adminSaving" />
          </FormField>
          <FormField :label="t('admin.dataSources.acceptedFiles')">
            <input class="input-base" type="file" accept=".pdf,.txt,.csv,.xlsx" :disabled="!canManageAgentConfig || adminSaving" @change="onDataSourceFileChange" />
          </FormField>
        </div>

        <button class="btn-primary mt-5 w-full justify-center md:w-auto" type="submit" :disabled="!canManageAgentConfig || adminSaving || !uploadDataSourceFile">
          {{ t('admin.dataSources.upload') }}
        </button>
      </form>

      <div class="mt-8 rounded-[24px] border p-5" :style="{ borderColor: 'var(--border)' }">
        <div>
          <strong>{{ t('admin.dataSources.usageTitle') }}</strong>
          <p class="mt-1 text-sm text-[var(--text-muted)]">{{ t('admin.dataSources.usageDescription') }}</p>
        </div>

        <InlineAlert v-if="readyDataSources.length === 0" class="mt-5" :message="t('admin.dataSources.noReadySources')" tone="info" />

        <div class="mt-5 grid gap-4 xl:grid-cols-2">
          <article v-for="toolName in bindingTools" :key="toolName" class="rounded-[24px] border p-5" :style="{ borderColor: 'var(--border)' }">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
              <div>
                <strong>{{ translateToolName(toolName) }}</strong>
                <p class="mt-1 text-sm text-[var(--text-muted)]">{{ translateToolDescription(toolName) }}</p>
              </div>
              <StatusBadge :label="toolSetupStatusLabel(toolName)" tone="neutral" />
            </div>

            <div v-if="tenantToolDrafts[toolName]" class="mt-5 grid gap-4">
              <label class="flex items-start gap-3 rounded-[24px] border px-4 py-4 text-sm" :style="{ borderColor: 'var(--border)' }">
                <input v-model="tenantToolDrafts[toolName].enabled" type="checkbox" class="mt-1 h-4 w-4" :disabled="!canManageAgentConfig || adminSaving" />
                <div>
                  <strong class="block">{{ t('admin.dataSources.enabledLabel') }}</strong>
                  <p class="mt-1 text-[var(--text-muted)]">{{ t('admin.dataSources.enabledHelp') }}</p>
                </div>
              </label>

              <FormField :label="t('admin.dataSources.generalSourceLabel')" :hint="t('admin.dataSources.generalSourceHelp')">
                <select v-model="tenantToolDrafts[toolName].dataSourceId" class="input-base" :disabled="!canManageAgentConfig || adminSaving">
                  <option value="">{{ t('admin.dataSources.noSourceSelected') }}</option>
                  <option v-for="source in readyDataSources" :key="source.id" :value="String(source.id)">
                    {{ source.name }}
                  </option>
                </select>
              </FormField>

              <details class="rounded-[24px] border p-4" :style="{ borderColor: 'var(--border)' }">
                <summary class="cursor-pointer text-sm font-semibold">{{ t('admin.shared.advancedTitle') }}</summary>

                <div class="mt-4">
                  <FormField :label="t('admin.dataSources.waitTimeLabel')" :hint="t('admin.dataSources.waitTimeHelp')">
                    <input v-model="tenantToolDrafts[toolName].timeoutSeconds" class="input-base" type="number" min="1" max="120" :disabled="!canManageAgentConfig || adminSaving" />
                  </FormField>
                </div>
              </details>

              <button class="btn-secondary w-full justify-center md:w-auto" type="button" :disabled="!canManageAgentConfig || adminSaving" @click="saveTenantToolBinding(toolName)">
                {{ t('admin.dataSources.saveGeneralUsage') }}
              </button>
            </div>

            <div class="mt-6">
              <strong>{{ t('admin.dataSources.byLineTitle') }}</strong>
              <p class="mt-1 text-sm text-[var(--text-muted)]">{{ t('admin.dataSources.byLineDescription') }}</p>

              <div class="mt-4 grid gap-3">
                <article v-for="line in adminOverview.whatsapp_lines" :key="toolDraftKey(line.id, toolName)" class="rounded-[20px] border p-4" :style="{ borderColor: 'var(--border)' }">
                  <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                      <strong>{{ lineLabel(line) }}</strong>
                      <p class="mt-1 text-sm text-[var(--text-muted)]">{{ lineToolSummary(line.id, toolName) }}</p>
                    </div>
                    <StatusBadge :label="lineToolModeLabel(line.id, toolName)" tone="neutral" />
                  </div>

                  <div v-if="lineToolDrafts[toolDraftKey(line.id, toolName)]" class="mt-4 grid gap-4">
                    <label class="flex items-start gap-3 rounded-[24px] border px-4 py-4 text-sm" :style="{ borderColor: 'var(--border)' }">
                      <input v-model="lineToolDrafts[toolDraftKey(line.id, toolName)].enabled" type="checkbox" class="mt-1 h-4 w-4" :disabled="!canManageAgentConfig || adminSaving" />
                      <div>
                        <strong class="block">{{ t('admin.dataSources.enabledLabel') }}</strong>
                        <p class="mt-1 text-[var(--text-muted)]">{{ t('admin.dataSources.lineEnabledHelp') }}</p>
                      </div>
                    </label>

                    <FormField :label="t('admin.dataSources.lineSourceLabel')" :hint="t('admin.dataSources.lineSourceHelp')">
                      <select v-model="lineToolDrafts[toolDraftKey(line.id, toolName)].dataSourceId" class="input-base" :disabled="!canManageAgentConfig || adminSaving">
                        <option value="">{{ t('admin.dataSources.useGeneralSource') }}</option>
                        <option v-for="source in readyDataSources" :key="source.id" :value="String(source.id)">
                          {{ source.name }}
                        </option>
                      </select>
                    </FormField>

                    <details class="rounded-[24px] border p-4" :style="{ borderColor: 'var(--border)' }">
                      <summary class="cursor-pointer text-sm font-semibold">{{ t('admin.shared.advancedTitle') }}</summary>

                      <div class="mt-4">
                        <FormField :label="t('admin.dataSources.waitTimeLabel')" :hint="t('admin.dataSources.waitTimeHelp')">
                          <input v-model="lineToolDrafts[toolDraftKey(line.id, toolName)].timeoutSeconds" class="input-base" type="number" min="1" max="120" :disabled="!canManageAgentConfig || adminSaving" />
                        </FormField>
                      </div>
                    </details>
                  </div>

                  <div class="mt-4 flex flex-wrap gap-3">
                    <button class="btn-secondary" type="button" :disabled="!canManageAgentConfig || adminSaving" @click="saveLineToolBinding(line.id, toolName)">
                      {{ lineHasToolCustomization(line.id, toolName) ? t('admin.dataSources.saveCustomization') : t('admin.dataSources.createCustomization') }}
                    </button>
                    <button v-if="lineHasToolCustomization(line.id, toolName)" class="btn-danger" type="button" :disabled="!canManageAgentConfig || adminSaving" @click="removeLineToolCustomization(line.id, toolName)">
                      {{ t('admin.dataSources.removeCustomization') }}
                    </button>
                  </div>
                </article>
              </div>
            </div>
          </article>
        </div>
      </div>

      <div class="mt-8 grid gap-4">
        <article v-for="source in adminOverview.data_sources" :key="source.id" class="rounded-[24px] border p-5" :style="{ borderColor: 'var(--border)' }">
          <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
              <strong>{{ source.name }}</strong>
              <p class="mt-1 text-sm text-[var(--text-muted)]">
                {{ sourceCurrentFileLabel(source) }} · {{ formatBytes(source.latest_upload?.size_bytes) }}
              </p>
            </div>
            <StatusBadge :label="translateDataSourceStatus(source.status)" :tone="sourceStatusTone(source.status)" />
          </div>

          <div class="mt-4 rounded-[20px] border px-4 py-4" :style="{ borderColor: 'var(--border)' }">
            <strong>{{ sourceStatusHeadline(source) }}</strong>
            <p class="mt-1 text-sm text-[var(--text-muted)]">{{ sourceStatusDescription(source) }}</p>

            <div class="mt-4 flex flex-wrap gap-4 text-sm text-[var(--text-muted)]">
              <span>{{ t('admin.dataSources.preparedContent', { count: source.chunk_count }) }}</span>
              <span>{{ t('admin.dataSources.updatedAt', { value: formatTimestamp(latestSourceDate(source)) }) }}</span>
              <span>{{ t('admin.dataSources.lastAttempt', { count: source.latest_import?.attempts_count ?? 0 }) }}</span>
            </div>
          </div>

          <InlineAlert
            v-if="source.status === 'failed'"
            class="mt-4"
            :message="t('admin.dataSources.importFailedHuman')"
            tone="danger"
          />

          <details v-if="hasSourceSupportDetails(source)" class="mt-4 rounded-[20px] border p-4" :style="{ borderColor: 'var(--border)' }">
            <summary class="cursor-pointer text-sm font-semibold">{{ t('admin.dataSources.supportDetailsTitle') }}</summary>
            <pre class="mt-3 whitespace-pre-wrap break-words text-xs text-[var(--text-muted)]">{{ sourceSupportDetails(source) }}</pre>
          </details>

          <div v-if="source.status === 'ready'" class="mt-6 rounded-[20px] border p-4" :style="{ borderColor: 'var(--border)' }">
            <strong>{{ t('admin.dataSources.whereUsedTitle') }}</strong>
            <p class="mt-1 text-sm text-[var(--text-muted)]">{{ t('admin.dataSources.whereUsedDescription') }}</p>

            <div class="mt-4 grid gap-3 xl:grid-cols-2">
              <article v-for="toolName in bindingTools" :key="`${source.id}:${toolName}`" class="rounded-[18px] border p-4" :style="{ borderColor: 'var(--border)' }">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                  <div>
                    <strong>{{ translateToolName(toolName) }}</strong>
                    <p class="mt-1 text-sm text-[var(--text-muted)]">{{ translateToolDescription(toolName) }}</p>
                  </div>
                  <StatusBadge :label="usageSummaryLabel(source.id, toolName)" tone="neutral" />
                </div>

                <div class="mt-4 grid gap-2 text-sm text-[var(--text-muted)]">
                  <p>
                    {{
                      usageSummary(source.id, toolName).tenantUsesSource
                        ? t('admin.dataSources.usedInGeneral')
                        : t('admin.dataSources.notUsedInGeneral')
                    }}
                  </p>
                  <p>
                    {{ t('admin.dataSources.inheritedLinesUsage', { count: usageSummary(source.id, toolName).inheritedLines.length }) }}
                  </p>
                  <p v-if="usageSummary(source.id, toolName).inheritedLines.length > 0">
                    {{ formatLineList(usageSummary(source.id, toolName).inheritedLines) }}
                  </p>
                  <p>
                    {{ t('admin.dataSources.customLinesUsage', { count: usageSummary(source.id, toolName).customizedLines.length }) }}
                  </p>
                  <p v-if="usageSummary(source.id, toolName).customizedLines.length > 0">
                    {{ formatLineList(usageSummary(source.id, toolName).customizedLines) }}
                  </p>
                </div>
              </article>
            </div>
          </div>

          <button class="btn-secondary mt-4" type="button" :disabled="!canManageAgentConfig || adminSaving || source.latest_import?.status !== 'failed'" @click="retryImport(source)">
            {{ t('admin.dataSources.retry') }}
          </button>
        </article>
      </div>
    </SurfaceCard>

    <SurfaceCard v-else-if="activePanel === 'credentials'" padding="lg">
      <div>
        <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-[var(--accent)]">{{ t('admin.credentials.eyebrow') }}</p>
        <h3 class="mt-2 text-xl font-semibold">{{ t('admin.credentials.title') }}</h3>
      </div>

      <div class="mt-6 grid gap-4">
        <article v-for="credential in adminOverview.credential_metadata" :key="credential.id" class="rounded-[24px] border p-4" :style="{ borderColor: 'var(--border)' }">
          <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
              <strong>{{ credential.provider }} / {{ credential.credential_key }}</strong>
              <p class="mt-1 text-sm text-[var(--text-muted)]">
                {{ credential.scope_type === 'tenant' ? t('common.scopes.tenant') : lineLabel(credential.whatsapp_line) }}
              </p>
            </div>
            <StatusBadge :label="translateCredentialStatus(credential.has_secret)" tone="neutral" />
          </div>

          <div class="mt-4 flex flex-wrap gap-4 text-sm text-[var(--text-muted)]">
            <span>{{ t('admin.credentials.lastUsed', { value: formatTimestamp(credential.last_used_at) }) }}</span>
            <span>{{ t('admin.credentials.updatedAt', { value: formatTimestamp(credential.updated_at) }) }}</span>
          </div>
        </article>
      </div>

      <form v-if="canManagePlatform" class="mt-8 rounded-[24px] border p-5" :style="{ borderColor: 'var(--border)' }" @submit.prevent="saveCredential">
        <strong>{{ t('admin.credentials.formTitle') }}</strong>

        <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
          <FormField :label="t('admin.credentials.scope')">
            <select v-model="credentialForm.scopeType" class="input-base" :disabled="adminSaving">
              <option value="tenant">{{ translateScope('tenant') }}</option>
              <option value="whatsapp_line">{{ translateScope('whatsapp_line') }}</option>
            </select>
          </FormField>

          <FormField v-if="credentialForm.scopeType === 'whatsapp_line'" :label="t('sandbox.line')">
            <select v-model="credentialForm.whatsappLineId" class="input-base" :disabled="adminSaving">
              <option value="">{{ t('common.selectLine') }}</option>
              <option v-for="line in adminOverview.whatsapp_lines" :key="line.id" :value="String(line.id)">
                {{ lineLabel(line) }}
              </option>
            </select>
          </FormField>

          <FormField :label="t('admin.credentials.provider')">
            <input v-model="credentialForm.provider" class="input-base" type="text" :disabled="adminSaving" />
          </FormField>

          <FormField :label="t('admin.credentials.credentialKey')">
            <input v-model="credentialForm.credentialKey" class="input-base" type="text" :disabled="adminSaving" />
          </FormField>
        </div>

        <FormField class="mt-5" :label="t('admin.credentials.secret')">
          <textarea v-model="credentialForm.secret" class="input-base min-h-32 resize-y" rows="3" :disabled="adminSaving" />
        </FormField>

        <button class="btn-primary mt-5 w-full justify-center md:w-auto" type="submit" :disabled="adminSaving">
          {{ t('admin.credentials.save') }}
        </button>
      </form>
    </SurfaceCard>

    <SurfaceCard v-else padding="lg">
      <div>
        <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-[var(--accent)]">{{ t('admin.logs.eyebrow') }}</p>
        <h3 class="mt-2 text-xl font-semibold">{{ t('admin.logs.title') }}</h3>
      </div>

      <div class="mt-6 grid gap-4 xl:grid-cols-3">
        <div class="rounded-[24px] border p-4" :style="{ borderColor: 'var(--border)' }">
          <strong>{{ t('admin.logs.agentEvents') }}</strong>
          <div class="mt-4 grid gap-3">
            <article v-for="event in adminOverview.logs.agent_events" :key="`agent-${event.id}`" class="rounded-[20px] border p-3" :style="{ borderColor: 'var(--border)' }">
              <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-[var(--text-muted)]">
                <span>{{ event.event_type }}</span>
                <span>{{ formatTimestamp(event.occurred_at) }}</span>
              </div>
              <p class="mt-2 text-sm">{{ eventPreview(event.payload) }}</p>
            </article>
          </div>
        </div>

        <div class="rounded-[24px] border p-4" :style="{ borderColor: 'var(--border)' }">
          <strong>{{ t('admin.logs.auditEvents') }}</strong>
          <div class="mt-4 grid gap-3">
            <article v-for="event in adminOverview.logs.audit_events" :key="`audit-${event.id}`" class="rounded-[20px] border p-3" :style="{ borderColor: 'var(--border)' }">
              <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-[var(--text-muted)]">
                <span>{{ event.event_type }}</span>
                <span>{{ formatTimestamp(event.occurred_at) }}</span>
              </div>
              <p class="mt-2 text-sm">{{ event.actor_user?.email || t('common.system') }} · {{ eventPreview(event.payload) }}</p>
            </article>
          </div>
        </div>

        <div class="rounded-[24px] border p-4" :style="{ borderColor: 'var(--border)' }">
          <strong>{{ t('admin.logs.toolExecutions') }}</strong>
          <div class="mt-4 grid gap-3">
            <article v-for="execution in adminOverview.logs.tool_executions" :key="`tool-${execution.id}`" class="rounded-[20px] border p-3" :style="{ borderColor: 'var(--border)' }">
              <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-[var(--text-muted)]">
                <span>{{ translateToolName(execution.tool_name) }} · {{ execution.status }}</span>
                <span>{{ formatTimestamp(execution.executed_at || execution.created_at) }}</span>
              </div>
              <p class="mt-2 text-sm">{{ execution.error_message || eventPreview(execution.output_summary) }}</p>
            </article>
          </div>
        </div>
      </div>
    </SurfaceCard>
  </section>
</template>
