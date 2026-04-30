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
import type {
  AdminOverview,
  AgentConfigRecord,
  DataSourceRecord,
  TenantRecord,
  ToolConfigRecord,
  WhatsAppLineRecord,
} from '../types';
import AdminSectionTabs from '../components/AdminSectionTabs.vue';

type AgentConfigForm = {
  name: string;
  model: string;
  promptVersion: string;
  isActive: boolean;
  automationEnabled: boolean;
  systemPrompt: string;
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
  { key: 'bindings', label: t('admin.panels.bindings') },
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

const activePanel = computed({
  get: () => routeString('panel') || 'tenant',
  set: (value: string) => {
    void router.replace({
      query: {
        ...route.query,
        panel: value,
      },
    });
  },
});

const bindingTools = computed(() => adminOverview.value?.binding_tools ?? []);
const readyDataSources = computed(() =>
  (adminOverview.value?.data_sources ?? []).filter(
    (source) => source.status === 'ready' || source.status === 'pending' || source.status === 'failed',
  ),
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

function defaultAgentConfigForm(name = ''): AgentConfigForm {
  return {
    name,
    model: 'gpt-5.1',
    promptVersion: 'v1',
    isActive: true,
    automationEnabled: true,
    systemPrompt: '',
  };
}

function defaultToolConfigForm(record?: ToolConfigRecord | null): ToolConfigForm {
  return {
    enabled: record?.enabled ?? true,
    timeoutSeconds: record?.timeout_seconds ? String(record.timeout_seconds) : '',
    dataSourceId: record?.data_source_id ? String(record.data_source_id) : '',
  };
}

function agentConfigFormFromRecord(record: AgentConfigRecord | null | undefined, fallbackName: string): AgentConfigForm {
  if (!record) {
    return defaultAgentConfigForm(fallbackName);
  }

  return {
    name: record.name,
    model: record.model ?? 'gpt-5.1',
    promptVersion: record.prompt_version ?? 'v1',
    isActive: record.is_active,
    automationEnabled: record.automation_enabled,
    systemPrompt: record.system_prompt,
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

function toolDraftKey(lineId: number, toolName: string): string {
  return `${lineId}:${toolName}`;
}

function syncAdminForms(overview: AdminOverview): void {
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

  tenantAgentConfigForm.value = agentConfigFormFromRecord(resolveTenantAgentConfig(overview), `${overview.tenant.name} Agent`);

  lineAgentConfigDrafts.value = Object.fromEntries(
    overview.whatsapp_lines.map((line) => [
      line.id,
      agentConfigFormFromRecord(resolveLineAgentConfig(overview, line.id), `${line.name} Override`),
    ]),
  );

  tenantToolDrafts.value = Object.fromEntries(
    overview.binding_tools.map((toolName) => [toolName, defaultToolConfigForm(resolveTenantToolConfig(overview, toolName))]),
  );

  lineToolDrafts.value = Object.fromEntries(
    overview.whatsapp_lines.flatMap((line) =>
      overview.binding_tools.map((toolName) => [
        toolDraftKey(line.id, toolName),
        defaultToolConfigForm(resolveLineToolConfig(overview, line.id, toolName)),
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
    return t('common.scopes.tenant');
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

function resolveErrorMessage(error: unknown, fallbackKey: string): string {
  return error instanceof Error && error.message !== '' ? error.message : t(fallbackKey);
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

  await withAdminAction(t('admin.success.tenantUpdated'), async () => {
    await updateTenant(selectedTenantId.value!, {
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

  await withAdminAction(t('admin.success.tenantUserAdded'), async () => {
    await createTenantUser(selectedTenantId.value!, {
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

  await withAdminAction(t('admin.success.roleUpdated'), async () => {
    await updateTenantUser(selectedTenantId.value!, tenantUserId, {
      role: tenantUserRoles.value[tenantUserId] ?? 'viewer',
    });

    await loadAdminWorkspace();
  });
}

async function removeTenantUser(tenantUserId: number): Promise<void> {
  if (!selectedTenantId.value) {
    return;
  }

  await withAdminAction(t('admin.success.tenantUserRemoved'), async () => {
    await deleteTenantUser(selectedTenantId.value!, tenantUserId);
    await loadAdminWorkspace();
  });
}

async function createLine(): Promise<void> {
  if (!selectedTenantId.value) {
    return;
  }

  await withAdminAction(t('admin.success.lineCreated'), async () => {
    await createWhatsAppLine(selectedTenantId.value!, {
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

  const draft = lineDrafts.value[lineId];
  if (!draft) {
    return;
  }

  await withAdminAction(t('admin.success.lineUpdated'), async () => {
    await updateWhatsAppLine(selectedTenantId.value!, lineId, {
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

  await withAdminAction(t('admin.success.lineDeleted'), async () => {
    await deleteWhatsAppLine(selectedTenantId.value!, lineId);
    await loadAdminWorkspace();
  });
}

async function saveTenantAgentSettings(): Promise<void> {
  if (!selectedTenantId.value) {
    return;
  }

  await withAdminAction(t('admin.success.tenantConfigUpdated'), async () => {
    await updateTenantAgentConfig(selectedTenantId.value!, {
      name: tenantAgentConfigForm.value.name.trim(),
      model: tenantAgentConfigForm.value.model.trim() || undefined,
      prompt_version: tenantAgentConfigForm.value.promptVersion.trim() || undefined,
      is_active: tenantAgentConfigForm.value.isActive,
      automation_enabled: tenantAgentConfigForm.value.automationEnabled,
      system_prompt: tenantAgentConfigForm.value.systemPrompt.trim() || undefined,
    });

    await loadAdminWorkspace();
  });
}

async function saveLineAgentSettings(lineId: number): Promise<void> {
  if (!selectedTenantId.value) {
    return;
  }

  const draft = lineAgentConfigDrafts.value[lineId];
  if (!draft) {
    return;
  }

  await withAdminAction(t('admin.success.lineOverrideUpdated'), async () => {
    await updateLineAgentConfig(selectedTenantId.value!, lineId, {
      name: draft.name.trim(),
      model: draft.model.trim() || undefined,
      prompt_version: draft.promptVersion.trim() || undefined,
      is_active: draft.isActive,
      automation_enabled: draft.automationEnabled,
      system_prompt: draft.systemPrompt.trim() || undefined,
    });

    await loadAdminWorkspace();
  });
}

async function saveTenantToolBinding(toolName: string): Promise<void> {
  if (!selectedTenantId.value) {
    return;
  }

  const draft = tenantToolDrafts.value[toolName];
  if (!draft) {
    return;
  }

  await withAdminAction(t('admin.success.tenantBindingUpdated', { toolName }), async () => {
    await updateTenantToolConfig(selectedTenantId.value!, toolName, {
      enabled: draft.enabled,
      timeout_seconds: draft.timeoutSeconds ? Number(draft.timeoutSeconds) : null,
      data_source_id: draft.dataSourceId ? Number(draft.dataSourceId) : null,
    });

    await loadAdminWorkspace();
  });
}

async function saveLineToolBinding(lineId: number, toolName: string): Promise<void> {
  if (!selectedTenantId.value) {
    return;
  }

  const draft = lineToolDrafts.value[toolDraftKey(lineId, toolName)];
  if (!draft) {
    return;
  }

  await withAdminAction(t('admin.success.lineBindingUpdated', { toolName }), async () => {
    await updateLineToolConfig(selectedTenantId.value!, lineId, toolName, {
      enabled: draft.enabled,
      timeout_seconds: draft.timeoutSeconds ? Number(draft.timeoutSeconds) : null,
      data_source_id: draft.dataSourceId ? Number(draft.dataSourceId) : null,
    });

    await loadAdminWorkspace();
  });
}

async function saveCredential(): Promise<void> {
  if (!selectedTenantId.value) {
    return;
  }

  await withAdminAction(t('admin.success.credentialSaved'), async () => {
    await upsertCredential(selectedTenantId.value!, {
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

  await withAdminAction(t('admin.success.dataSourceUploaded'), async () => {
    await uploadDataSource(selectedTenantId.value!, {
      name: uploadDataSourceName.value.trim(),
      file: uploadDataSourceFile.value!,
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

  await withAdminAction(t('admin.success.importRetried'), async () => {
    await retryDataSourceImport(selectedTenantId.value!, source.id, source.latest_import!.id);
    await loadAdminWorkspace();
  });
}

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
      <div>
        <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-[var(--accent)]">{{ t('admin.agentConfig.eyebrow') }}</p>
        <h3 class="mt-2 text-xl font-semibold">{{ t('admin.agentConfig.title') }}</h3>
      </div>

      <form class="mt-6 grid gap-4" @submit.prevent="saveTenantAgentSettings">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          <FormField :label="t('admin.tenant.name')">
            <input v-model="tenantAgentConfigForm.name" class="input-base" type="text" :disabled="!canManageAgentConfig || adminSaving" />
          </FormField>
          <FormField :label="t('admin.agentConfig.model')">
            <input v-model="tenantAgentConfigForm.model" class="input-base" type="text" :disabled="!canManageAgentConfig || adminSaving" />
          </FormField>
          <FormField :label="t('admin.agentConfig.promptVersion')">
            <input v-model="tenantAgentConfigForm.promptVersion" class="input-base" type="text" :disabled="!canManageAgentConfig || adminSaving" />
          </FormField>
          <label class="flex items-end gap-3 rounded-2xl border px-4 py-3 text-sm" :style="{ borderColor: 'var(--border)' }">
            <input v-model="tenantAgentConfigForm.isActive" type="checkbox" class="h-4 w-4" :disabled="!canManageAgentConfig || adminSaving" />
            <span>{{ t('admin.agentConfig.active') }}</span>
          </label>
          <label class="flex items-end gap-3 rounded-2xl border px-4 py-3 text-sm" :style="{ borderColor: 'var(--border)' }">
            <input v-model="tenantAgentConfigForm.automationEnabled" type="checkbox" class="h-4 w-4" :disabled="!canManageAgentConfig || adminSaving" />
            <span>{{ t('admin.agentConfig.automationEnabled') }}</span>
          </label>
        </div>

        <FormField :label="t('admin.agentConfig.systemPrompt')">
          <textarea v-model="tenantAgentConfigForm.systemPrompt" class="input-base min-h-40 resize-y" rows="5" :disabled="!canManageAgentConfig || adminSaving" />
        </FormField>

        <button class="btn-primary w-full justify-center md:w-auto" type="submit" :disabled="!canManageAgentConfig || adminSaving">
          {{ t('admin.agentConfig.saveTenant') }}
        </button>
      </form>

      <div class="mt-8 grid gap-4">
        <article v-for="line in adminOverview.whatsapp_lines" :key="line.id" class="rounded-[24px] border p-4" :style="{ borderColor: 'var(--border)' }">
          <strong>{{ lineLabel(line) }}</strong>

          <div v-if="lineAgentConfigDrafts[line.id]" class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <FormField :label="t('admin.tenant.name')">
              <input v-model="lineAgentConfigDrafts[line.id].name" class="input-base" type="text" :disabled="!canManageAgentConfig || adminSaving" />
            </FormField>
            <FormField :label="t('admin.agentConfig.model')">
              <input v-model="lineAgentConfigDrafts[line.id].model" class="input-base" type="text" :disabled="!canManageAgentConfig || adminSaving" />
            </FormField>
            <FormField :label="t('admin.agentConfig.promptVersion')">
              <input v-model="lineAgentConfigDrafts[line.id].promptVersion" class="input-base" type="text" :disabled="!canManageAgentConfig || adminSaving" />
            </FormField>
            <label class="flex items-end gap-3 rounded-2xl border px-4 py-3 text-sm" :style="{ borderColor: 'var(--border)' }">
              <input v-model="lineAgentConfigDrafts[line.id].isActive" type="checkbox" class="h-4 w-4" :disabled="!canManageAgentConfig || adminSaving" />
              <span>{{ t('admin.agentConfig.active') }}</span>
            </label>
            <label class="flex items-end gap-3 rounded-2xl border px-4 py-3 text-sm" :style="{ borderColor: 'var(--border)' }">
              <input v-model="lineAgentConfigDrafts[line.id].automationEnabled" type="checkbox" class="h-4 w-4" :disabled="!canManageAgentConfig || adminSaving" />
              <span>{{ t('admin.agentConfig.automationEnabled') }}</span>
            </label>
          </div>

          <FormField v-if="lineAgentConfigDrafts[line.id]" class="mt-5" :label="t('admin.agentConfig.systemPrompt')">
            <textarea v-model="lineAgentConfigDrafts[line.id].systemPrompt" class="input-base min-h-32 resize-y" rows="4" :disabled="!canManageAgentConfig || adminSaving" />
          </FormField>

          <button class="btn-secondary mt-5" type="button" :disabled="!canManageAgentConfig || adminSaving" @click="saveLineAgentSettings(line.id)">
            {{ t('admin.agentConfig.saveOverride') }}
          </button>
        </article>
      </div>
    </SurfaceCard>

    <SurfaceCard v-else-if="activePanel === 'sources'" padding="lg">
      <div>
        <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-[var(--accent)]">{{ t('admin.dataSources.eyebrow') }}</p>
        <h3 class="mt-2 text-xl font-semibold">{{ t('admin.dataSources.title') }}</h3>
      </div>

      <form class="mt-6 grid gap-4" @submit.prevent="submitDataSource">
        <div class="grid gap-4 md:grid-cols-2">
          <FormField :label="t('admin.dataSources.visibleName')">
            <input v-model="uploadDataSourceName" class="input-base" type="text" :disabled="!canManageAgentConfig || adminSaving" />
          </FormField>
          <FormField :label="t('admin.dataSources.acceptedFiles')">
            <input class="input-base" type="file" accept=".pdf,.txt,.csv,.xlsx" :disabled="!canManageAgentConfig || adminSaving" @change="onDataSourceFileChange" />
          </FormField>
        </div>

        <button class="btn-primary w-full justify-center md:w-auto" type="submit" :disabled="!canManageAgentConfig || adminSaving || !uploadDataSourceFile">
          {{ t('admin.dataSources.upload') }}
        </button>
      </form>

      <div class="mt-8 grid gap-4">
        <article v-for="source in adminOverview.data_sources" :key="source.id" class="rounded-[24px] border p-4" :style="{ borderColor: 'var(--border)' }">
          <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
              <strong>{{ source.name }}</strong>
              <p class="mt-1 text-sm text-[var(--text-muted)]">
                {{ source.latest_upload?.original_name || t('common.noFile') }} · {{ formatBytes(source.latest_upload?.size_bytes) }}
              </p>
            </div>
            <StatusBadge :label="translateDataSourceStatus(source.status)" tone="neutral" />
          </div>

          <div class="mt-4 flex flex-wrap gap-4 text-sm text-[var(--text-muted)]">
            <span>{{ t('admin.dataSources.chunkCount', { count: source.chunk_count }) }}</span>
            <span>{{ t('admin.dataSources.attempts', { count: source.latest_import?.attempts_count ?? 0 }) }}</span>
            <span>{{ t('admin.dataSources.lastSync', { value: formatTimestamp(source.last_synced_at) }) }}</span>
          </div>

          <InlineAlert v-if="source.latest_import?.error_message" class="mt-4" :message="source.latest_import.error_message" tone="danger" />

          <button class="btn-secondary mt-4" type="button" :disabled="!canManageAgentConfig || adminSaving || source.latest_import?.status !== 'failed'" @click="retryImport(source)">
            {{ t('admin.dataSources.retry') }}
          </button>
        </article>
      </div>
    </SurfaceCard>

    <SurfaceCard v-else-if="activePanel === 'bindings'" padding="lg">
      <div>
        <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-[var(--accent)]">{{ t('admin.bindings.eyebrow') }}</p>
        <h3 class="mt-2 text-xl font-semibold">{{ t('admin.bindings.title') }}</h3>
      </div>

      <div class="mt-6 grid gap-4">
        <article v-for="toolName in bindingTools" :key="toolName" class="rounded-[24px] border p-4" :style="{ borderColor: 'var(--border)' }">
          <strong>{{ t('admin.bindings.tenantScope', { toolName }) }}</strong>

          <div v-if="tenantToolDrafts[toolName]" class="mt-5 grid gap-4 md:grid-cols-3">
            <label class="flex items-end gap-3 rounded-2xl border px-4 py-3 text-sm" :style="{ borderColor: 'var(--border)' }">
              <input v-model="tenantToolDrafts[toolName].enabled" type="checkbox" class="h-4 w-4" :disabled="!canManageAgentConfig || adminSaving" />
              <span>{{ t('admin.bindings.enabled') }}</span>
            </label>
            <FormField :label="t('admin.bindings.timeout')">
              <input v-model="tenantToolDrafts[toolName].timeoutSeconds" class="input-base" type="number" min="1" max="120" :disabled="!canManageAgentConfig || adminSaving" />
            </FormField>
            <FormField :label="t('admin.bindings.dataSource')">
              <select v-model="tenantToolDrafts[toolName].dataSourceId" class="input-base" :disabled="!canManageAgentConfig || adminSaving">
                <option value="">{{ t('admin.bindings.automaticFallback') }}</option>
                <option v-for="source in readyDataSources" :key="source.id" :value="String(source.id)">
                  {{ source.name }} · {{ translateDataSourceStatus(source.status) }}
                </option>
              </select>
            </FormField>
          </div>

          <button class="btn-secondary mt-5" type="button" :disabled="!canManageAgentConfig || adminSaving" @click="saveTenantToolBinding(toolName)">
            {{ t('admin.bindings.saveTenant') }}
          </button>
        </article>
      </div>

      <div class="mt-8 grid gap-4">
        <article v-for="line in adminOverview.whatsapp_lines" :key="line.id" class="rounded-[24px] border p-4" :style="{ borderColor: 'var(--border)' }">
          <strong>{{ lineLabel(line) }}</strong>

          <div v-for="toolName in bindingTools" :key="toolDraftKey(line.id, toolName)" class="mt-5 rounded-[20px] border p-4" :style="{ borderColor: 'var(--border)' }">
            <strong>{{ toolName }}</strong>

            <div v-if="lineToolDrafts[toolDraftKey(line.id, toolName)]" class="mt-4 grid gap-4 md:grid-cols-3">
              <label class="flex items-end gap-3 rounded-2xl border px-4 py-3 text-sm" :style="{ borderColor: 'var(--border)' }">
                <input v-model="lineToolDrafts[toolDraftKey(line.id, toolName)].enabled" type="checkbox" class="h-4 w-4" :disabled="!canManageAgentConfig || adminSaving" />
                <span>{{ t('admin.bindings.enabled') }}</span>
              </label>
              <FormField :label="t('admin.bindings.timeout')">
                <input v-model="lineToolDrafts[toolDraftKey(line.id, toolName)].timeoutSeconds" class="input-base" type="number" min="1" max="120" :disabled="!canManageAgentConfig || adminSaving" />
              </FormField>
              <FormField :label="t('admin.bindings.dataSource')">
                <select v-model="lineToolDrafts[toolDraftKey(line.id, toolName)].dataSourceId" class="input-base" :disabled="!canManageAgentConfig || adminSaving">
                  <option value="">{{ t('admin.bindings.tenantFallback') }}</option>
                  <option v-for="source in readyDataSources" :key="source.id" :value="String(source.id)">
                    {{ source.name }} · {{ translateDataSourceStatus(source.status) }}
                  </option>
                </select>
              </FormField>
            </div>

            <button class="btn-secondary mt-4" type="button" :disabled="!canManageAgentConfig || adminSaving" @click="saveLineToolBinding(line.id, toolName)">
              {{ t('admin.bindings.saveOverride') }}
            </button>
          </div>
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
                <span>{{ execution.tool_name }} · {{ execution.status }}</span>
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
