<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { appConfig } from './config/app';
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
  uploadDataSource as uploadKnowledgeSource,
  upsertCredential,
} from './modules/admin/api';
import type {
  AdminOverview,
  AgentConfigRecord,
  DataSourceRecord,
  TenantRecord,
  ToolConfigRecord,
  WhatsAppLineRecord,
} from './modules/admin/types';
import {
  assignConversation,
  fetchConversation,
  fetchConversations,
  fetchSession,
  handoffConversation,
  login,
  logout,
  resumeConversation,
  sendManualReply,
} from './modules/operations/api';
import type {
  ConversationSummary,
  ConversationThreadPayload,
  OperatorOption,
  SessionMembership,
  SessionResponse,
} from './modules/operations/types';
import {
  closeSandboxSession,
  createSandboxSession,
  fetchSandboxSession,
  fetchSandboxSessions,
  sendSandboxMessage,
} from './modules/sandbox/api';
import type {
  SandboxLastTurn,
  SandboxLineOption,
  SandboxSessionPayload,
  SandboxSessionSummary,
} from './modules/sandbox/types';
import type { TranslationValues } from './i18n';

type TenantMembership = SessionMembership & {
  tenant_id: number;
  tenant_name: string;
  tenant_slug: string;
};

type WorkspaceSection = 'operations' | 'sandbox' | 'admin';

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

const session = ref<SessionResponse | null>(null);
const authLoading = ref(true);
const authError = ref<string | null>(null);
const selectedTenantId = ref<number | null>(null);
const workspaceSection = ref<WorkspaceSection>('operations');

const inboxLoading = ref(false);
const threadLoading = ref(false);
const actionLoading = ref(false);
const inboxError = ref<string | null>(null);
const threadError = ref<string | null>(null);
const actionError = ref<string | null>(null);
const conversations = ref<ConversationSummary[]>([]);
const thread = ref<ConversationThreadPayload | null>(null);
const selectedConversationId = ref<number | null>(null);
const selectedAssigneeId = ref<number | null>(null);
const manualReplyBody = ref('');
const handoffReason = ref('');
const search = ref('');
const statusFilter = ref('ALL');
const assignedToMeOnly = ref(false);

const sandboxLoading = ref(false);
const sandboxThreadLoading = ref(false);
const sandboxActionLoading = ref(false);
const sandboxError = ref<string | null>(null);
const sandboxThreadError = ref<string | null>(null);
const sandboxActionError = ref<string | null>(null);
const sandboxSessions = ref<SandboxSessionSummary[]>([]);
const sandboxThread = ref<SandboxSessionPayload | null>(null);
const selectedSandboxConversationId = ref<number | null>(null);
const sandboxAvailableLines = ref<SandboxLineOption[]>([]);
const sandboxSessionLabel = ref('');
const sandboxLineId = ref<number | null>(null);
const sandboxMessageBody = ref('');

const adminLoading = ref(false);
const adminSaving = ref(false);
const adminError = ref<string | null>(null);
const adminSuccess = ref<string | null>(null);
const adminOverview = ref<AdminOverview | null>(null);
const adminTenants = ref<TenantRecord[]>([]);

const loginEmail = ref('');
const loginPassword = ref('');

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
const { t, locale } = useI18n();

const statusOptions = [
  'ALL',
  'BOT_ACTIVE',
  'WAITING_CUSTOMER',
  'HUMAN_HANDOFF',
  'ERROR',
  'CLOSED',
];

const currentUser = computed(() => session.value?.user ?? null);
const memberships = computed<TenantMembership[]>(() =>
  (session.value?.memberships ?? []).filter(
    (membership): membership is TenantMembership =>
      membership.tenant_id !== null &&
      membership.tenant_name !== null &&
      membership.tenant_slug !== null,
  ),
);

const selectedMembership = computed(() => {
  if (!selectedTenantId.value) {
    return null;
  }

  return (
    memberships.value.find(
      (membership) => membership.tenant_id === selectedTenantId.value,
    ) ?? null
  );
});

const canViewConversations = computed(() =>
  selectedMembership.value?.permissions.includes('conversations.view') ?? false,
);
const canReplyToConversations = computed(() =>
  selectedMembership.value?.permissions.includes('conversations.reply') ?? false,
);
const canManageHandoffs = computed(() =>
  selectedMembership.value?.permissions.includes('handoffs.manage') ?? false,
);
const canManageTenant = computed(() =>
  selectedMembership.value?.permissions.includes('tenant.manage') ?? false,
);
const canManageTenantUsers = computed(() =>
  selectedMembership.value?.permissions.includes('tenant_users.manage') ?? false,
);
const canManageAgentConfig = computed(() =>
  selectedMembership.value?.permissions.includes('agent_configs.manage') ?? false,
);
const canViewCredentialMetadata = computed(() =>
  selectedMembership.value?.permissions.includes('credentials.view_metadata') ?? false,
);
const canManagePlatform = computed(() =>
  selectedMembership.value?.permissions.includes('platform.manage') ?? false,
);
const canAccessSandbox = computed(() => canManageAgentConfig.value);
const canAccessAdmin = computed(() =>
  canManageTenant.value || canManageAgentConfig.value || canViewCredentialMetadata.value,
);

const selectedConversation = computed(() => thread.value?.conversation ?? null);
const availableOperators = computed<OperatorOption[]>(
  () => thread.value?.available_operators ?? [],
);

const isOwnedByCurrentUser = computed(() => {
  const assignedUserId = selectedConversation.value?.assigned_to_user?.id ?? null;
  const currentUserId = currentUser.value?.id ?? null;

  return assignedUserId !== null && currentUserId !== null && assignedUserId === currentUserId;
});

const canManuallyReply = computed(() => {
  if (!selectedConversation.value || !canReplyToConversations.value) {
    return false;
  }

  return (
    selectedConversation.value.status === 'HUMAN_HANDOFF' &&
    (selectedConversation.value.assigned_to_user === null || isOwnedByCurrentUser.value)
  );
});

const bindingTools = computed(() => adminOverview.value?.binding_tools ?? []);
const readyDataSources = computed(() =>
  (adminOverview.value?.data_sources ?? []).filter(
    (source) => source.status === 'ready' || source.status === 'pending' || source.status === 'failed',
  ),
);
const selectedSandboxConversation = computed(
  () => sandboxThread.value?.conversation ?? null,
);
const sandboxLastTurn = computed<SandboxLastTurn | null>(
  () => sandboxThread.value?.last_turn ?? null,
);
const canSendSandboxMessage = computed(() =>
  selectedSandboxConversation.value !== null &&
  selectedSandboxConversation.value.status !== 'CLOSED' &&
  canAccessSandbox.value,
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

function agentConfigFormFromRecord(
  record: AgentConfigRecord | null | undefined,
  fallbackName: string,
): AgentConfigForm {
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

function formatTimestamp(value: string | null): string {
  if (!value) {
    return t('common.noDate');
  }

  return new Intl.DateTimeFormat(locale.value, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(value));
}

function formatBytes(value: number | null | undefined): string {
  if (!value) {
    return '0 B';
  }

  if (value < 1024) {
    return `${value} B`;
  }

  if (value < 1024 * 1024) {
    return `${(value / 1024).toFixed(1)} KB`;
  }

  return `${(value / (1024 * 1024)).toFixed(1)} MB`;
}

function initialAssigneeFromThread(payload: ConversationThreadPayload | null): number | null {
  if (!payload) {
    return null;
  }

  return (
    payload.conversation.assigned_to_user?.id ??
    payload.available_operators[0]?.user_id ??
    null
  );
}

function toolDraftKey(lineId: number, toolName: string): string {
  return `${lineId}:${toolName}`;
}

function resolveTenantAgentConfig(overview: AdminOverview): AgentConfigRecord | null {
  return overview.agent_configs.find((config) => config.scope_type === 'tenant') ?? null;
}

function resolveLineAgentConfig(
  overview: AdminOverview,
  lineId: number,
): AgentConfigRecord | null {
  return (
    overview.agent_configs.find(
      (config) =>
        config.scope_type === 'whatsapp_line' &&
        config.whatsapp_line_id === lineId,
    ) ?? null
  );
}

function resolveTenantToolConfig(
  overview: AdminOverview,
  toolName: string,
): ToolConfigRecord | null {
  return (
    overview.tool_configs.find(
      (config) => config.scope_type === 'tenant' && config.tool_name === toolName,
    ) ?? null
  );
}

function resolveLineToolConfig(
  overview: AdminOverview,
  lineId: number,
  toolName: string,
): ToolConfigRecord | null {
  return (
    overview.tool_configs.find(
      (config) =>
        config.scope_type === 'whatsapp_line' &&
        config.whatsapp_line_id === lineId &&
        config.tool_name === toolName,
    ) ?? null
  );
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

  tenantAgentConfigForm.value = agentConfigFormFromRecord(
    resolveTenantAgentConfig(overview),
    `${overview.tenant.name} Agent`,
  );

  lineAgentConfigDrafts.value = Object.fromEntries(
    overview.whatsapp_lines.map((line) => [
      line.id,
      agentConfigFormFromRecord(
        resolveLineAgentConfig(overview, line.id),
        `${line.name} Override`,
      ),
    ]),
  );

  tenantToolDrafts.value = Object.fromEntries(
    overview.binding_tools.map((toolName) => [
      toolName,
      defaultToolConfigForm(resolveTenantToolConfig(overview, toolName)),
    ]),
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

function lineLabel(
  line: Pick<WhatsAppLineRecord, 'name' | 'display_phone_number'> | null,
): string {
  if (!line) {
    return t('common.scopes.tenant');
  }

  return `${line.name}${line.display_phone_number ? ` · ${line.display_phone_number}` : ''}`;
}

function resolveErrorMessage(error: unknown, fallbackKey: string): string {
  return error instanceof Error && error.message !== ''
    ? error.message
    : t(fallbackKey);
}

function translateRole(role: string | null | undefined): string {
  return t(`common.roles.${role ?? 'unknown'}`);
}

function translateConversationStatus(status: string): string {
  return t(`common.conversationStatuses.${status}`);
}

function translateHandoffStatus(status: string | null | undefined): string {
  return status ? t(`common.handoffStatuses.${status}`) : t('operations.noRecord');
}

function translateLineStatus(isEnabled: boolean): string {
  return t(`common.lineStatus.${isEnabled ? 'enabled' : 'disabled'}`);
}

function translateCredentialStatus(hasSecret: boolean): string {
  return t(`common.credentialStatus.${hasSecret ? 'configured' : 'empty'}`);
}

function translateDataSourceStatus(status: string): string {
  return t(`common.dataSourceStatuses.${status}`);
}

function translateScope(scope: 'tenant' | 'whatsapp_line'): string {
  return t(`common.scopes.${scope}`);
}

function translateMessage(key: string, values: TranslationValues = {}): string {
  return t(key, values);
}

function eventPreview(payload: Record<string, unknown>): string {
  const text = JSON.stringify(payload);
  return text.length > 220 ? `${text.slice(0, 220)}…` : text;
}

async function refreshSession(): Promise<void> {
  authLoading.value = true;
  authError.value = null;

  try {
    const payload = await fetchSession();
    session.value = payload;

    if (!payload.authenticated) {
      selectedTenantId.value = null;
      selectedConversationId.value = null;
      conversations.value = [];
      thread.value = null;
      adminOverview.value = null;
      adminTenants.value = [];
      return;
    }

    if (
      payload.memberships.length > 0 &&
      !payload.memberships.some(
        (membership) => membership.tenant_id === selectedTenantId.value,
      )
    ) {
      selectedTenantId.value = payload.memberships[0]?.tenant_id ?? null;
    }
  } catch (error) {
    authError.value = resolveErrorMessage(error, 'auth.loadFailed');
  } finally {
    authLoading.value = false;
  }
}

async function submitLogin(): Promise<void> {
  authLoading.value = true;
  authError.value = null;

  try {
    session.value = await login(loginEmail.value, loginPassword.value);
    loginPassword.value = '';
    selectedTenantId.value = session.value.memberships[0]?.tenant_id ?? null;
  } catch (error) {
    authError.value = resolveErrorMessage(error, 'auth.loginFailed');
  } finally {
    authLoading.value = false;
  }
}

async function submitLogout(): Promise<void> {
  authLoading.value = true;
  authError.value = null;

  try {
    session.value = await logout();
    selectedTenantId.value = null;
    selectedConversationId.value = null;
    conversations.value = [];
    thread.value = null;
    adminOverview.value = null;
    adminTenants.value = [];
    manualReplyBody.value = '';
  } catch (error) {
    authError.value = resolveErrorMessage(error, 'auth.logoutFailed');
  } finally {
    authLoading.value = false;
  }
}

async function loadInbox(): Promise<void> {
  if (!selectedTenantId.value || !canViewConversations.value) {
    conversations.value = [];
    thread.value = null;
    return;
  }

  inboxLoading.value = true;
  inboxError.value = null;

  try {
    const payload = await fetchConversations(selectedTenantId.value, {
      status: statusFilter.value,
      search: search.value.trim(),
      assignedToMe: assignedToMeOnly.value,
    });

    conversations.value = payload.data;

    if (
      selectedConversationId.value !== null &&
      !payload.data.some(
        (conversation) => conversation.id === selectedConversationId.value,
      )
    ) {
      selectedConversationId.value = payload.data[0]?.id ?? null;
    }

    if (selectedConversationId.value === null) {
      selectedConversationId.value = payload.data[0]?.id ?? null;
    }
  } catch (error) {
    inboxError.value = resolveErrorMessage(error, 'operations.loadInboxFailed');
  } finally {
    inboxLoading.value = false;
  }
}

async function loadConversation(conversationId: number | null): Promise<void> {
  if (!selectedTenantId.value || conversationId === null) {
    thread.value = null;
    selectedAssigneeId.value = null;
    return;
  }

  threadLoading.value = true;
  threadError.value = null;

  try {
    const payload = await fetchConversation(selectedTenantId.value, conversationId);
    thread.value = payload.data;
    selectedAssigneeId.value = initialAssigneeFromThread(payload.data);
  } catch (error) {
    threadError.value = resolveErrorMessage(error, 'operations.loadThreadFailed');
  } finally {
    threadLoading.value = false;
  }
}

async function reloadOperationsWorkspace(): Promise<void> {
  await loadInbox();
  await loadConversation(selectedConversationId.value);
}

async function withConversationAction(action: () => Promise<void>): Promise<void> {
  actionLoading.value = true;
  actionError.value = null;

  try {
    await action();
    await reloadOperationsWorkspace();
  } catch (error) {
    actionError.value = resolveErrorMessage(error, 'operations.loadActionFailed');
  } finally {
    actionLoading.value = false;
  }
}

async function takeConversation(): Promise<void> {
  if (!selectedTenantId.value || !selectedConversationId.value) {
    return;
  }

  await withConversationAction(async () => {
    await handoffConversation(selectedTenantId.value!, selectedConversationId.value!, {
      assigned_to_user_id: currentUser.value?.id,
      reason: handoffReason.value.trim() || undefined,
    });

    handoffReason.value = '';
  });
}

async function reassignConversation(): Promise<void> {
  if (!selectedTenantId.value || !selectedConversationId.value || !selectedAssigneeId.value) {
    return;
  }

  await withConversationAction(async () => {
    await assignConversation(
      selectedTenantId.value!,
      selectedConversationId.value!,
      selectedAssigneeId.value!,
    );
  });
}

async function submitManualReply(): Promise<void> {
  if (!selectedTenantId.value || !selectedConversationId.value || manualReplyBody.value.trim() === '') {
    return;
  }

  await withConversationAction(async () => {
    await sendManualReply(
      selectedTenantId.value!,
      selectedConversationId.value!,
      manualReplyBody.value.trim(),
    );

    manualReplyBody.value = '';
  });
}

async function resumeBot(targetStatus: 'BOT_ACTIVE' | 'WAITING_CUSTOMER'): Promise<void> {
  if (!selectedTenantId.value || !selectedConversationId.value) {
    return;
  }

  await withConversationAction(async () => {
    await resumeConversation(
      selectedTenantId.value!,
      selectedConversationId.value!,
      targetStatus,
    );
  });
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

    if (canManagePlatform.value) {
      adminTenants.value = (await fetchAdminTenants()).data;
    } else {
      adminTenants.value = [];
    }
  } catch (error) {
    adminError.value = resolveErrorMessage(error, 'admin.loadFailed');
  } finally {
    adminLoading.value = false;
  }
}

async function loadSandboxSessions(): Promise<void> {
  if (!selectedTenantId.value || !canAccessSandbox.value) {
    sandboxSessions.value = [];
    sandboxThread.value = null;
    sandboxAvailableLines.value = [];
    return;
  }

  sandboxLoading.value = true;
  sandboxError.value = null;

  try {
    const payload = await fetchSandboxSessions(selectedTenantId.value);
    sandboxSessions.value = payload.data;
    sandboxAvailableLines.value = payload.meta?.available_lines ?? [];

    if (
      selectedSandboxConversationId.value !== null &&
      !payload.data.some((conversation) => conversation.id === selectedSandboxConversationId.value)
    ) {
      selectedSandboxConversationId.value = payload.data[0]?.id ?? null;
    }

    if (selectedSandboxConversationId.value === null) {
      selectedSandboxConversationId.value = payload.data[0]?.id ?? null;
    }

    if (
      sandboxLineId.value === null ||
      !sandboxAvailableLines.value.some((line) => line.id === sandboxLineId.value)
    ) {
      sandboxLineId.value = sandboxAvailableLines.value[0]?.id ?? null;
    }
  } catch (error) {
    sandboxError.value = resolveErrorMessage(error, 'sandbox.loadSessionsFailed');
  } finally {
    sandboxLoading.value = false;
  }
}

async function loadSandboxConversation(conversationId: number | null): Promise<void> {
  if (!selectedTenantId.value || conversationId === null) {
    sandboxThread.value = null;
    return;
  }

  sandboxThreadLoading.value = true;
  sandboxThreadError.value = null;

  try {
    const payload = await fetchSandboxSession(selectedTenantId.value, conversationId);
    sandboxThread.value = payload.data;
  } catch (error) {
    sandboxThreadError.value = resolveErrorMessage(error, 'sandbox.loadSessionFailed');
  } finally {
    sandboxThreadLoading.value = false;
  }
}

async function createSandboxChat(): Promise<void> {
  if (!selectedTenantId.value || !sandboxLineId.value) {
    return;
  }

  sandboxActionLoading.value = true;
  sandboxActionError.value = null;

  try {
    const response = await createSandboxSession(selectedTenantId.value, {
      whatsapp_line_id: sandboxLineId.value,
      label: sandboxSessionLabel.value.trim() || undefined,
    });

    sandboxSessionLabel.value = '';
    selectedSandboxConversationId.value = response.data.conversation.id;
    sandboxThread.value = response.data;
    await loadSandboxSessions();
  } catch (error) {
    sandboxActionError.value = resolveErrorMessage(error, 'sandbox.createFailed');
  } finally {
    sandboxActionLoading.value = false;
  }
}

async function submitSandboxMessage(): Promise<void> {
  if (!selectedTenantId.value || !selectedSandboxConversationId.value || sandboxMessageBody.value.trim() === '') {
    return;
  }

  sandboxActionLoading.value = true;
  sandboxActionError.value = null;

  try {
    const response = await sendSandboxMessage(
      selectedTenantId.value,
      selectedSandboxConversationId.value,
      sandboxMessageBody.value.trim(),
    );

    sandboxMessageBody.value = '';
    sandboxThread.value = response.data;
    await loadSandboxSessions();
  } catch (error) {
    sandboxActionError.value = resolveErrorMessage(error, 'sandbox.turnFailed');
  } finally {
    sandboxActionLoading.value = false;
  }
}

async function closeSandboxChat(): Promise<void> {
  if (!selectedTenantId.value || !selectedSandboxConversationId.value) {
    return;
  }

  sandboxActionLoading.value = true;
  sandboxActionError.value = null;

  try {
    const response = await closeSandboxSession(
      selectedTenantId.value,
      selectedSandboxConversationId.value,
    );

    sandboxThread.value = response.data;
    await loadSandboxSessions();
  } catch (error) {
    sandboxActionError.value = resolveErrorMessage(error, 'sandbox.closeFailed');
  } finally {
    sandboxActionLoading.value = false;
  }
}

async function withAdminAction(
  successMessage: string,
  action: () => Promise<void>,
): Promise<void> {
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
    selectedTenantId.value = response.data.id;
    workspaceSection.value = 'admin';
    await loadAdminWorkspace();
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

async function uploadDataSource(): Promise<void> {
  if (!selectedTenantId.value || !uploadDataSourceFile.value) {
    return;
  }

  await withAdminAction(t('admin.success.dataSourceUploaded'), async () => {
    await uploadKnowledgeSource(selectedTenantId.value!, {
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
    await retryDataSourceImport(
      selectedTenantId.value!,
      source.id,
      source.latest_import!.id,
    );

    await loadAdminWorkspace();
  });
}

watch(selectedTenantId, async () => {
  selectedConversationId.value = null;
  thread.value = null;
  selectedSandboxConversationId.value = null;
  sandboxThread.value = null;
  sandboxSessions.value = [];
  sandboxAvailableLines.value = [];
  sandboxActionError.value = null;
  sandboxError.value = null;
  adminOverview.value = null;
  adminSuccess.value = null;
  adminError.value = null;

  if (workspaceSection.value === 'operations') {
    await loadInbox();
    return;
  }

  if (workspaceSection.value === 'sandbox') {
    await loadSandboxSessions();
    return;
  }

  await loadAdminWorkspace();
});

watch(selectedConversationId, async (conversationId) => {
  if (workspaceSection.value === 'operations') {
    await loadConversation(conversationId);
  }
});

watch(selectedSandboxConversationId, async (conversationId) => {
  if (workspaceSection.value === 'sandbox') {
    await loadSandboxConversation(conversationId);
  }
});

watch(workspaceSection, async (section) => {
  if (section === 'operations') {
    await loadInbox();
    await loadConversation(selectedConversationId.value);
    return;
  }

  if (section === 'sandbox') {
    await loadSandboxSessions();
    await loadSandboxConversation(selectedSandboxConversationId.value);
    return;
  }

  await loadAdminWorkspace();
});

onMounted(async () => {
  await refreshSession();
});
</script>

<template>
  <main class="ops-app">
    <section class="hero-band">
      <div>
        <p class="eyebrow">{{ t('common.appEyebrow') }}</p>
        <h1>{{ appConfig.appName }}</h1>
        <p class="lede">
          {{ t('common.appLede') }}
        </p>
      </div>

      <div class="hero-meta">
        <a :href="appConfig.backendHealthUrl" target="_blank" rel="noreferrer">
          {{ t('common.apiHealth') }}
        </a>
      </div>
    </section>

    <section v-if="authLoading && !session" class="surface centered-state">
      <p>{{ t('auth.loadingSession') }}</p>
    </section>

    <section v-else-if="!session?.authenticated" class="login-layout">
      <article class="surface login-card">
        <p class="eyebrow">{{ t('auth.accessEyebrow') }}</p>
        <h2>{{ t('auth.title') }}</h2>
        <p class="muted-copy">
          {{ t('auth.subtitle') }}
        </p>

        <form class="form-stack" @submit.prevent="submitLogin">
          <label>
            <span>{{ t('auth.email') }}</span>
            <input v-model="loginEmail" type="email" autocomplete="email" required />
          </label>

          <label>
            <span>{{ t('auth.password') }}</span>
            <input
              v-model="loginPassword"
              type="password"
              autocomplete="current-password"
              required
            />
          </label>

          <p v-if="authError" class="error-copy">{{ authError }}</p>

          <button class="primary-button" type="submit" :disabled="authLoading">
            {{ authLoading ? t('auth.submitting') : t('auth.submit') }}
          </button>
        </form>
      </article>
    </section>

    <section v-else class="workspace-shell">
      <header class="surface topbar">
        <div class="identity-block">
          <p class="eyebrow">{{ t('auth.authenticatedSession') }}</p>
          <h2>{{ currentUser?.name }}</h2>
          <p class="muted-copy">{{ currentUser?.email }}</p>
        </div>

        <div class="topbar-controls">
          <label class="compact-field">
            <span>{{ t('common.scopes.tenant') }}</span>
            <select v-model.number="selectedTenantId">
              <option
                v-for="membership in memberships"
                :key="membership.tenant_id"
                :value="membership.tenant_id ?? undefined"
              >
                {{ membership.tenant_name }} · {{ translateRole(membership.role) }}
              </option>
            </select>
          </label>

          <div class="segmented-control">
            <button
              type="button"
              class="segment-button"
              :class="{ 'segment-button-active': workspaceSection === 'operations' }"
              @click="workspaceSection = 'operations'"
            >
              {{ t('operations.tab') }}
            </button>
            <button
              v-if="selectedMembership && canAccessSandbox"
              type="button"
              class="segment-button"
              :class="{ 'segment-button-active': workspaceSection === 'sandbox' }"
              @click="workspaceSection = 'sandbox'"
            >
              {{ t('sandbox.tab') }}
            </button>
            <button
              type="button"
              class="segment-button"
              :class="{ 'segment-button-active': workspaceSection === 'admin' }"
              @click="workspaceSection = 'admin'"
            >
              {{ t('admin.tab') }}
            </button>
          </div>

          <button class="ghost-button" type="button" :disabled="authLoading" @click="submitLogout">
            {{ authLoading ? t('auth.loggingOut') : t('auth.logout') }}
          </button>
        </div>
      </header>

      <section v-if="workspaceSection === 'operations'">
        <section v-if="selectedMembership" class="workspace-grid">
          <aside class="surface inbox-panel">
            <div class="panel-topline">
              <div>
                <p class="eyebrow">{{ t('operations.eyebrow') }}</p>
                <h3>{{ selectedMembership.tenant_name }}</h3>
              </div>
              <span class="status-pill">
                {{ t('operations.threadsCount', { count: conversations.length }) }}
              </span>
            </div>

            <div class="filters-stack">
              <label>
                <span>{{ t('operations.search') }}</span>
                <input
                  v-model="search"
                  type="search"
                  :placeholder="t('operations.searchPlaceholder')"
                  @change="loadInbox"
                />
              </label>

              <div class="inline-filters">
                <label>
                  <span>{{ t('operations.status') }}</span>
                  <select v-model="statusFilter" @change="loadInbox">
                    <option v-for="option in statusOptions" :key="option" :value="option">
                      {{ translateConversationStatus(option) }}
                    </option>
                  </select>
                </label>

                <label class="checkbox-field">
                  <input
                    v-model="assignedToMeOnly"
                    type="checkbox"
                    @change="loadInbox"
                  />
                  <span>{{ t('operations.assignedToMe') }}</span>
                </label>
              </div>
            </div>

            <p v-if="inboxError" class="error-copy">{{ inboxError }}</p>
            <p v-else-if="inboxLoading" class="muted-copy">{{ t('operations.loadingInbox') }}</p>
            <p v-else-if="!canViewConversations" class="muted-copy">
              {{ t('operations.noAccess') }}
            </p>

            <ul v-else class="thread-list">
              <li v-for="conversation in conversations" :key="conversation.id">
                <button
                  type="button"
                  class="thread-card"
                  :class="{ 'thread-card-active': selectedConversationId === conversation.id }"
                  @click="selectedConversationId = conversation.id"
                >
                  <div class="thread-card-header">
                    <strong>{{ conversation.contact_name || conversation.contact_phone }}</strong>
                    <span class="status-chip" :data-status="conversation.status">
                      {{ translateConversationStatus(conversation.status) }}
                    </span>
                  </div>
                  <p class="thread-preview">
                    {{ conversation.last_message_preview || t('operations.noMessagesVisible') }}
                  </p>
                  <div class="thread-card-meta">
                    <span>
                      {{
                        conversation.assigned_to_user
                          ? translateMessage('operations.ownerLabel', { name: conversation.assigned_to_user.name })
                          : t('operations.noOwner')
                      }}
                    </span>
                    <span>{{ formatTimestamp(conversation.last_message_at) }}</span>
                  </div>
                </button>
              </li>
            </ul>
          </aside>

          <section class="surface thread-panel">
            <template v-if="selectedConversation && thread">
              <div class="panel-topline">
                <div>
                  <p class="eyebrow">{{ t('operations.threadEyebrow') }}</p>
                  <h3>{{ selectedConversation.contact_name || selectedConversation.contact_phone }}</h3>
                  <p class="muted-copy">
                    {{ selectedConversation.contact_phone }}
                    <template v-if="selectedConversation.whatsapp_line">
                      · {{ selectedConversation.whatsapp_line.name }}
                    </template>
                  </p>
                </div>
                <div class="thread-top-actions">
                  <span class="status-chip status-chip-large" :data-status="selectedConversation.status">
                    {{ translateConversationStatus(selectedConversation.status) }}
                  </span>
                </div>
              </div>

              <div class="detail-grid">
                <article class="detail-card">
                  <p class="detail-label">{{ t('operations.currentOwner') }}</p>
                  <strong>
                    {{
                      selectedConversation.assigned_to_user?.name ??
                      t('operations.noAssignment')
                    }}
                  </strong>
                  <p class="muted-copy">
                    {{ t('operations.lastMessage', { value: formatTimestamp(selectedConversation.last_message_at) }) }}
                  </p>
                </article>

                <article class="detail-card">
                  <p class="detail-label">{{ t('operations.latestHandoff') }}</p>
                  <strong>{{ translateHandoffStatus(selectedConversation.latest_handoff?.status) }}</strong>
                  <p class="muted-copy">
                    {{ selectedConversation.latest_handoff?.reason || t('operations.noReason') }}
                  </p>
                </article>

                <article class="detail-card">
                  <p class="detail-label">{{ t('operations.runtimeState') }}</p>
                  <strong>{{ selectedConversation.state?.last_agent_action ?? t('operations.noSnapshot') }}</strong>
                  <p class="muted-copy">
                    {{ selectedConversation.state?.current_intent ?? t('operations.noIntent') }}
                  </p>
                </article>
              </div>

              <div class="operator-tools">
                <div class="operator-toolset">
                  <label class="wide-field">
                    <span>{{ t('operations.handoffReason') }}</span>
                    <input
                      v-model="handoffReason"
                      type="text"
                      :placeholder="t('operations.handoffPlaceholder')"
                      :disabled="!canManageHandoffs || actionLoading"
                    />
                  </label>

                  <button
                    class="accent-button"
                    type="button"
                    :disabled="!canManageHandoffs || actionLoading"
                    @click="takeConversation"
                  >
                    {{ actionLoading ? t('operations.processing') : t('operations.takeConversation') }}
                  </button>
                </div>

                <div class="operator-toolset">
                  <label class="wide-field">
                    <span>{{ t('operations.assignOperator') }}</span>
                    <select
                      v-model.number="selectedAssigneeId"
                      :disabled="!canManageHandoffs || actionLoading"
                    >
                      <option
                        v-for="operator in availableOperators"
                        :key="operator.user_id"
                        :value="operator.user_id"
                      >
                        {{ operator.name }} · {{ translateRole(operator.role) }}
                      </option>
                    </select>
                  </label>

                  <button
                    class="ghost-button"
                    type="button"
                    :disabled="!canManageHandoffs || !selectedAssigneeId || actionLoading"
                    @click="reassignConversation"
                  >
                    {{ t('operations.reassign') }}
                  </button>
                </div>
              </div>

              <p v-if="actionError" class="error-copy">{{ actionError }}</p>
              <p v-if="threadError" class="error-copy">{{ threadError }}</p>
              <p v-else-if="threadLoading" class="muted-copy">{{ t('operations.loadingThread') }}</p>

              <div class="messages-panel">
                <article
                  v-for="message in thread.messages"
                  :key="message.id"
                  class="message-bubble"
                  :class="message.direction === 'outbound' ? 'message-outbound' : 'message-inbound'"
                >
                  <header>
                    <strong>
                      {{ message.direction === 'outbound' ? t('operations.outbound') : t('operations.inbound') }}
                    </strong>
                    <span>{{ message.source || t('common.unknown') }}</span>
                  </header>
                  <p>{{ message.body || t('common.messageWithoutBody') }}</p>
                  <footer>
                    <span>{{ message.status || t('common.notAvailable') }}</span>
                    <span>{{ formatTimestamp(message.created_at) }}</span>
                  </footer>
                </article>
              </div>

              <form class="composer-panel" @submit.prevent="submitManualReply">
                <label class="wide-field">
                  <span>{{ t('operations.manualReply') }}</span>
                  <textarea
                    v-model="manualReplyBody"
                    rows="4"
                    :placeholder="t('operations.manualReplyPlaceholder')"
                    :disabled="!canManuallyReply || actionLoading"
                  />
                </label>

                <div class="composer-actions">
                  <button
                    class="primary-button"
                    type="submit"
                    :disabled="!canManuallyReply || manualReplyBody.trim() === '' || actionLoading"
                  >
                    {{ t('operations.sendManualReply') }}
                  </button>

                  <button
                    class="ghost-button"
                    type="button"
                    :disabled="!canManageHandoffs || actionLoading"
                    @click="resumeBot('BOT_ACTIVE')"
                  >
                    {{ t('operations.resumeBot') }}
                  </button>

                  <button
                    class="ghost-button"
                    type="button"
                    :disabled="!canManageHandoffs || actionLoading"
                    @click="resumeBot('WAITING_CUSTOMER')"
                  >
                    {{ t('operations.resumeWaiting') }}
                  </button>
                </div>

                <p class="muted-copy helper-copy">
                  {{ t('operations.helper') }}
                </p>
              </form>
            </template>

            <div v-else class="centered-state">
              <p>{{ t('operations.noConversationSelected') }}</p>
            </div>
          </section>
        </section>

        <section v-else class="surface centered-state">
          <p>{{ t('operations.noMemberships') }}</p>
        </section>
      </section>

      <section v-else-if="workspaceSection === 'sandbox'">
        <section v-if="!selectedMembership" class="surface centered-state">
          <p>{{ t('sandbox.selectTenant') }}</p>
        </section>

        <section v-else-if="!canAccessSandbox" class="surface centered-state">
          <p>{{ t('sandbox.noAccess') }}</p>
        </section>

        <section v-else class="workspace-grid">
          <aside class="surface inbox-panel">
            <div class="panel-topline">
              <div>
                <p class="eyebrow">{{ t('sandbox.eyebrow') }}</p>
                <h3>{{ selectedMembership.tenant_name }}</h3>
              </div>
              <span class="status-pill">
                {{ t('sandbox.sessionsCount', { count: sandboxSessions.length }) }}
              </span>
            </div>

            <form class="form-stack" @submit.prevent="createSandboxChat">
              <label>
                <span>{{ t('sandbox.line') }}</span>
                <select v-model.number="sandboxLineId" :disabled="sandboxActionLoading">
                  <option
                    v-for="line in sandboxAvailableLines"
                    :key="line.id"
                    :value="line.id"
                  >
                    {{ line.name }}{{ line.display_phone_number ? ` · ${line.display_phone_number}` : '' }}
                  </option>
                </select>
              </label>

              <label>
                <span>{{ t('sandbox.label') }}</span>
                <input
                  v-model="sandboxSessionLabel"
                  type="text"
                  :placeholder="t('sandbox.labelPlaceholder')"
                  :disabled="sandboxActionLoading"
                />
              </label>

              <button
                class="primary-button"
                type="submit"
                :disabled="!sandboxLineId || sandboxActionLoading"
              >
                {{ sandboxActionLoading ? t('sandbox.creating') : t('sandbox.newSession') }}
              </button>
            </form>

            <p v-if="sandboxError" class="error-copy">{{ sandboxError }}</p>
            <p v-else-if="sandboxLoading" class="muted-copy">{{ t('sandbox.loadingSessions') }}</p>

            <ul v-else class="thread-list">
              <li v-for="conversation in sandboxSessions" :key="conversation.id">
                <button
                  type="button"
                  class="thread-card"
                  :class="{ 'thread-card-active': selectedSandboxConversationId === conversation.id }"
                  @click="selectedSandboxConversationId = conversation.id"
                >
                  <div class="thread-card-header">
                    <strong>{{ conversation.label }}</strong>
                    <span class="status-chip" :data-status="conversation.status">
                      {{ translateConversationStatus(conversation.status) }}
                    </span>
                  </div>
                  <p class="thread-preview">
                    {{ conversation.last_message_preview || t('sandbox.noMessagesYet') }}
                  </p>
                  <div class="thread-card-meta">
                    <span>{{ conversation.whatsapp_line?.name ?? t('sandbox.noLine') }}</span>
                    <span>{{ formatTimestamp(conversation.last_message_at || conversation.created_at) }}</span>
                  </div>
                </button>
              </li>
            </ul>
          </aside>

          <section class="surface thread-panel">
            <template v-if="selectedSandboxConversation && sandboxThread">
              <div class="panel-topline">
                <div>
                  <p class="eyebrow">{{ t('sandbox.threadEyebrow') }}</p>
                  <h3>{{ selectedSandboxConversation.label }}</h3>
                  <p class="muted-copy">
                    {{ selectedSandboxConversation.whatsapp_line?.name ?? t('sandbox.noLine') }}
                    <template v-if="selectedSandboxConversation.whatsapp_line?.display_phone_number">
                      · {{ selectedSandboxConversation.whatsapp_line.display_phone_number }}
                    </template>
                  </p>
                </div>
                <div class="thread-top-actions">
                  <span class="status-chip status-chip-large" :data-status="selectedSandboxConversation.status">
                    {{ translateConversationStatus(selectedSandboxConversation.status) }}
                  </span>
                </div>
              </div>

              <div class="detail-grid">
                <article class="detail-card">
                  <p class="detail-label">{{ t('sandbox.latestOutcome') }}</p>
                  <strong>{{ sandboxLastTurn?.runtime_outcome ?? t('sandbox.noTurns') }}</strong>
                  <p class="muted-copy">
                    {{ selectedSandboxConversation.state?.current_intent ?? t('operations.noIntent') }}
                  </p>
                </article>

                <article class="detail-card">
                  <p class="detail-label">{{ t('sandbox.latestHandoff') }}</p>
                  <strong>{{ translateHandoffStatus(selectedSandboxConversation.latest_handoff?.status) }}</strong>
                  <p class="muted-copy">
                    {{ selectedSandboxConversation.latest_handoff?.reason || t('operations.noReason') }}
                  </p>
                </article>

                <article class="detail-card">
                  <p class="detail-label">{{ t('sandbox.latestTurnTools') }}</p>
                  <strong>{{ sandboxLastTurn?.tool_executions.length ?? 0 }}</strong>
                  <p class="muted-copy">
                    {{ sandboxLastTurn?.handoff_requested ? t('sandbox.escalated') : t('sandbox.notEscalated') }}
                  </p>
                </article>
              </div>

              <p v-if="sandboxActionError" class="error-copy">{{ sandboxActionError }}</p>
              <p v-if="sandboxThreadError" class="error-copy">{{ sandboxThreadError }}</p>
              <p v-else-if="sandboxThreadLoading" class="muted-copy">{{ t('sandbox.loadingSession') }}</p>

              <div v-if="sandboxLastTurn" class="detail-grid turn-metadata-grid">
                <article class="detail-card">
                  <p class="detail-label">{{ t('sandbox.toolCalls') }}</p>
                  <div class="stack-list">
                    <p
                      v-for="toolExecution in sandboxLastTurn.tool_executions"
                      :key="toolExecution.id"
                      class="muted-copy compact-copy"
                    >
                      {{ toolExecution.tool_name }} · {{ toolExecution.status }}
                      <template v-if="toolExecution.next_action">
                        · {{ toolExecution.next_action }}
                      </template>
                    </p>
                    <p v-if="sandboxLastTurn.tool_executions.length === 0" class="muted-copy compact-copy">
                      {{ t('sandbox.noToolCalls') }}
                    </p>
                  </div>
                </article>

                <article class="detail-card">
                  <p class="detail-label">{{ t('sandbox.turnEvents') }}</p>
                  <div class="stack-list">
                    <p
                      v-for="event in sandboxLastTurn.events"
                      :key="event.id"
                      class="muted-copy compact-copy"
                    >
                      {{ event.event_type }} · {{ formatTimestamp(event.occurred_at) }}
                    </p>
                  </div>
                </article>

                <article class="detail-card">
                  <p class="detail-label">{{ t('sandbox.visibleError') }}</p>
                  <strong>{{ sandboxLastTurn.error_message || t('sandbox.noError') }}</strong>
                  <p class="muted-copy">
                    {{ t('sandbox.triggerMessage', { id: sandboxLastTurn.triggering_message_id }) }}
                  </p>
                </article>
              </div>

              <div class="messages-panel">
                <article
                  v-for="message in sandboxThread.messages"
                  :key="message.id"
                  class="message-bubble"
                  :class="message.direction === 'outbound' ? 'message-outbound' : 'message-inbound'"
                >
                  <header>
                    <strong>
                      {{ message.direction === 'outbound' ? t('sandbox.agent') : t('sandbox.tester') }}
                    </strong>
                    <span>{{ message.source || t('common.unknown') }}</span>
                  </header>
                  <p>{{ message.body || t('common.messageWithoutBody') }}</p>
                  <footer>
                    <span>{{ message.status || t('common.notAvailable') }}</span>
                    <span>{{ formatTimestamp(message.created_at) }}</span>
                  </footer>
                </article>
              </div>

              <form class="composer-panel" @submit.prevent="submitSandboxMessage">
                <label class="wide-field">
                  <span>{{ t('sandbox.testMessage') }}</span>
                  <textarea
                    v-model="sandboxMessageBody"
                    rows="4"
                    :placeholder="t('sandbox.testMessagePlaceholder')"
                    :disabled="!canSendSandboxMessage || sandboxActionLoading"
                  />
                </label>

                <div class="composer-actions">
                  <button
                    class="primary-button"
                    type="submit"
                    :disabled="!canSendSandboxMessage || sandboxMessageBody.trim() === '' || sandboxActionLoading"
                  >
                    {{ t('sandbox.executeTurn') }}
                  </button>

                  <button
                    class="ghost-button"
                    type="button"
                    :disabled="selectedSandboxConversation.status === 'CLOSED' || sandboxActionLoading"
                    @click="closeSandboxChat"
                  >
                    {{ t('sandbox.closeSession') }}
                  </button>
                </div>

                <p class="muted-copy helper-copy">
                  {{ t('sandbox.helper') }}
                </p>
              </form>
            </template>

            <div v-else class="centered-state">
              <p>{{ t('sandbox.emptyState') }}</p>
            </div>
          </section>
        </section>
      </section>

      <section v-else class="admin-shell">
        <section v-if="!selectedMembership" class="surface centered-state">
          <p>{{ t('admin.selectTenant') }}</p>
        </section>

        <section v-else-if="!canAccessAdmin" class="surface centered-state">
          <p>{{ t('admin.noAccess') }}</p>
        </section>

        <section v-else-if="adminLoading && !adminOverview" class="surface centered-state">
          <p>{{ t('admin.loading') }}</p>
        </section>

        <template v-else-if="adminOverview">
          <section class="admin-summary-grid">
            <article class="surface summary-card">
              <p class="detail-label">{{ t('admin.summary.tenant') }}</p>
              <strong>{{ adminOverview.tenant.name }}</strong>
              <span>{{ adminOverview.tenant.status }}</span>
            </article>

            <article class="surface summary-card">
              <p class="detail-label">{{ t('admin.summary.lines') }}</p>
              <strong>{{ adminOverview.whatsapp_lines.length }}</strong>
              <span>{{ t('admin.summary.enabledLines', { count: adminOverview.whatsapp_lines.filter((line) => line.is_enabled).length }) }}</span>
            </article>

            <article class="surface summary-card">
              <p class="detail-label">{{ t('admin.summary.knowledgeSources') }}</p>
              <strong>{{ adminOverview.data_sources.length }}</strong>
              <span>{{ t('admin.summary.readySources', { count: adminOverview.data_sources.filter((source) => source.status === 'ready').length }) }}</span>
            </article>

            <article class="surface summary-card">
              <p class="detail-label">{{ t('admin.summary.credentials') }}</p>
              <strong>{{ adminOverview.credential_metadata.length }}</strong>
              <span>{{ t('admin.summary.configuredCredentials', { count: adminOverview.credential_metadata.filter((item) => item.has_secret).length }) }}</span>
            </article>
          </section>

          <p v-if="adminError" class="error-copy section-copy">{{ adminError }}</p>
          <p v-if="adminSuccess" class="success-copy section-copy">{{ adminSuccess }}</p>

          <section class="admin-grid">
            <article class="surface admin-panel">
              <div class="panel-topline">
                <div>
                  <p class="eyebrow">{{ t('admin.tenant.eyebrow') }}</p>
                  <h3>{{ t('admin.tenant.title') }}</h3>
                </div>
                <span class="status-pill">{{ adminOverview.tenant.slug }}</span>
              </div>

              <form class="form-stack" @submit.prevent="saveTenantSettings">
                <div class="field-grid">
                  <label>
                    <span>{{ t('admin.tenant.name') }}</span>
                    <input v-model="tenantForm.name" type="text" :disabled="!canManageTenant || adminSaving" />
                  </label>

                  <label>
                    <span>{{ t('admin.tenant.slug') }}</span>
                    <input v-model="tenantForm.slug" type="text" :disabled="!canManageTenant || adminSaving" />
                  </label>

                  <label>
                    <span>{{ t('admin.tenant.status') }}</span>
                    <input v-model="tenantForm.status" type="text" :disabled="!canManageTenant || adminSaving" />
                  </label>
                </div>

                <button class="primary-button" type="submit" :disabled="!canManageTenant || adminSaving">
                  {{ t('admin.tenant.save') }}
                </button>
              </form>

              <div v-if="canManagePlatform" class="subsection">
                <div class="subsection-header">
                  <strong>{{ t('admin.tenant.createTitle') }}</strong>
                  <span class="muted-copy">{{ t('admin.tenant.visible', { count: adminTenants.length }) }}</span>
                </div>

                <form class="form-stack" @submit.prevent="createNewTenant">
                  <div class="field-grid">
                    <label>
                      <span>{{ t('admin.tenant.name') }}</span>
                      <input v-model="createTenantForm.name" type="text" :disabled="adminSaving" />
                    </label>

                    <label>
                      <span>{{ t('admin.tenant.slug') }}</span>
                      <input v-model="createTenantForm.slug" type="text" :disabled="adminSaving" />
                    </label>

                    <label>
                      <span>{{ t('admin.tenant.status') }}</span>
                      <input v-model="createTenantForm.status" type="text" :disabled="adminSaving" />
                    </label>
                  </div>

                  <button class="ghost-button" type="submit" :disabled="adminSaving">
                    {{ t('admin.tenant.create') }}
                  </button>
                </form>
              </div>
            </article>

            <article class="surface admin-panel">
              <div class="panel-topline">
                <div>
                  <p class="eyebrow">{{ t('admin.tenantUsers.eyebrow') }}</p>
                  <h3>{{ t('admin.tenantUsers.title') }}</h3>
                </div>
                <span class="status-pill">{{ t('admin.tenantUsers.count', { count: adminOverview.tenant_users.length }) }}</span>
              </div>

              <div class="table-stack">
                <article
                  v-for="membership in adminOverview.tenant_users"
                  :key="membership.id"
                  class="mini-card"
                >
                  <div class="mini-card-header">
                    <div>
                      <strong>{{ membership.user?.name || t('admin.tenantUsers.noProfile') }}</strong>
                      <p class="muted-copy">{{ membership.user?.email }}</p>
                    </div>

                    <span class="status-chip">{{ translateRole(membership.role) }}</span>
                  </div>

                  <div class="inline-form">
                    <label class="wide-field">
                      <span>{{ t('admin.tenantUsers.role') }}</span>
                      <select
                        v-model="tenantUserRoles[membership.id]"
                        :disabled="!canManageTenantUsers || adminSaving"
                      >
                        <option
                          v-for="role in adminOverview.available_roles"
                          :key="role"
                          :value="role"
                        >
                          {{ translateRole(role) }}
                        </option>
                      </select>
                    </label>

                    <button
                      class="ghost-button"
                      type="button"
                      :disabled="!canManageTenantUsers || adminSaving"
                      @click="saveTenantUserRole(membership.id)"
                    >
                      {{ t('admin.tenantUsers.saveRole') }}
                    </button>

                    <button
                      class="danger-button"
                      type="button"
                      :disabled="!canManageTenantUsers || adminSaving"
                      @click="removeTenantUser(membership.id)"
                    >
                      {{ t('admin.tenantUsers.remove') }}
                    </button>
                  </div>
                </article>
              </div>

              <form class="form-stack subsection" @submit.prevent="addTenantUser">
                <div class="subsection-header">
                  <strong>{{ t('admin.tenantUsers.addTitle') }}</strong>
                </div>

                <div class="field-grid">
                  <label>
                    <span>{{ t('admin.tenant.name') }}</span>
                    <input v-model="newTenantUserForm.name" type="text" :disabled="!canManageTenantUsers || adminSaving" />
                  </label>

                  <label>
                    <span>{{ t('auth.email') }}</span>
                    <input v-model="newTenantUserForm.email" type="email" :disabled="!canManageTenantUsers || adminSaving" />
                  </label>

                  <label>
                    <span>{{ t('auth.password') }}</span>
                    <input v-model="newTenantUserForm.password" type="password" :disabled="!canManageTenantUsers || adminSaving" />
                  </label>

                  <label>
                    <span>{{ t('admin.tenantUsers.role') }}</span>
                    <select v-model="newTenantUserForm.role" :disabled="!canManageTenantUsers || adminSaving">
                      <option v-for="role in adminOverview.available_roles" :key="role" :value="role">
                        {{ translateRole(role) }}
                      </option>
                    </select>
                  </label>
                </div>

                <button class="primary-button" type="submit" :disabled="!canManageTenantUsers || adminSaving">
                  {{ t('admin.tenantUsers.addToTenant') }}
                </button>
              </form>
            </article>

            <article class="surface admin-panel">
              <div class="panel-topline">
                <div>
                  <p class="eyebrow">{{ t('admin.lines.eyebrow') }}</p>
                  <h3>{{ t('admin.lines.title') }}</h3>
                </div>
                <span class="status-pill">{{ t('admin.lines.count', { count: adminOverview.whatsapp_lines.length }) }}</span>
              </div>

              <div class="table-stack">
                <article
                  v-for="line in adminOverview.whatsapp_lines"
                  :key="line.id"
                  class="mini-card"
                >
                  <div class="mini-card-header">
                    <div>
                      <strong>{{ line.name }}</strong>
                      <p class="muted-copy">{{ line.display_phone_number || line.phone_number_id }}</p>
                    </div>
                    <span class="status-chip" :data-status="line.is_enabled ? 'BOT_ACTIVE' : 'CLOSED'">
                      {{ translateLineStatus(line.is_enabled) }}
                    </span>
                  </div>

                  <div v-if="lineDrafts[line.id]" class="field-grid">
                    <label>
                      <span>{{ t('admin.tenant.name') }}</span>
                      <input v-model="lineDrafts[line.id].name" type="text" :disabled="!canManageTenant || adminSaving" />
                    </label>

                    <label>
                      <span>{{ t('admin.lines.phoneNumberId') }}</span>
                      <input v-model="lineDrafts[line.id].phoneNumberId" type="text" :disabled="!canManageTenant || adminSaving" />
                    </label>

                    <label>
                      <span>{{ t('admin.lines.displayPhone') }}</span>
                      <input v-model="lineDrafts[line.id].displayPhoneNumber" type="text" :disabled="!canManageTenant || adminSaving" />
                    </label>

                    <label>
                      <span>{{ t('admin.lines.wabaId') }}</span>
                      <input v-model="lineDrafts[line.id].wabaId" type="text" :disabled="!canManageTenant || adminSaving" />
                    </label>

                    <label>
                      <span>{{ t('admin.tenant.status') }}</span>
                      <input v-model="lineDrafts[line.id].status" type="text" :disabled="!canManageTenant || adminSaving" />
                    </label>

                    <label class="checkbox-field checkbox-field-surface">
                      <input v-model="lineDrafts[line.id].isEnabled" type="checkbox" :disabled="!canManageTenant || adminSaving" />
                      <span>{{ t('admin.lines.automationEnabled') }}</span>
                    </label>
                  </div>

                  <div class="inline-form">
                    <button
                      class="ghost-button"
                      type="button"
                    :disabled="!canManageTenant || adminSaving"
                    @click="saveLine(line.id)"
                  >
                    {{ t('admin.lines.save') }}
                  </button>

                    <button
                      class="danger-button"
                      type="button"
                    :disabled="!canManageTenant || adminSaving"
                    @click="removeLine(line.id)"
                  >
                      {{ t('admin.lines.delete') }}
                    </button>
                  </div>
                </article>
              </div>

              <form class="form-stack subsection" @submit.prevent="createLine">
                <div class="subsection-header">
                  <strong>{{ t('admin.lines.createTitle') }}</strong>
                </div>

                <div class="field-grid">
                  <label>
                    <span>{{ t('admin.tenant.name') }}</span>
                    <input v-model="newLineForm.name" type="text" :disabled="!canManageTenant || adminSaving" />
                  </label>

                  <label>
                    <span>{{ t('admin.lines.phoneNumberId') }}</span>
                    <input v-model="newLineForm.phoneNumberId" type="text" :disabled="!canManageTenant || adminSaving" />
                  </label>

                  <label>
                    <span>{{ t('admin.lines.displayPhone') }}</span>
                    <input v-model="newLineForm.displayPhoneNumber" type="text" :disabled="!canManageTenant || adminSaving" />
                  </label>

                  <label>
                    <span>{{ t('admin.lines.wabaId') }}</span>
                    <input v-model="newLineForm.wabaId" type="text" :disabled="!canManageTenant || adminSaving" />
                  </label>

                  <label>
                    <span>{{ t('admin.tenant.status') }}</span>
                    <input v-model="newLineForm.status" type="text" :disabled="!canManageTenant || adminSaving" />
                  </label>

                  <label class="checkbox-field checkbox-field-surface">
                    <input v-model="newLineForm.isEnabled" type="checkbox" :disabled="!canManageTenant || adminSaving" />
                    <span>{{ t('admin.lines.enabled') }}</span>
                  </label>
                </div>

                <button class="primary-button" type="submit" :disabled="!canManageTenant || adminSaving">
                  {{ t('admin.lines.create') }}
                </button>
              </form>
            </article>

            <article class="surface admin-panel">
              <div class="panel-topline">
                <div>
                  <p class="eyebrow">{{ t('admin.agentConfig.eyebrow') }}</p>
                  <h3>{{ t('admin.agentConfig.title') }}</h3>
                </div>
                <span class="status-pill">{{ t('admin.agentConfig.scopes', { count: adminOverview.agent_configs.length }) }}</span>
              </div>

              <form class="form-stack" @submit.prevent="saveTenantAgentSettings">
                <div class="subsection-header">
                  <strong>{{ t('admin.agentConfig.tenantConfig') }}</strong>
                </div>

                <div class="field-grid">
                  <label>
                    <span>{{ t('admin.tenant.name') }}</span>
                    <input v-model="tenantAgentConfigForm.name" type="text" :disabled="!canManageAgentConfig || adminSaving" />
                  </label>

                  <label>
                    <span>{{ t('admin.agentConfig.model') }}</span>
                    <input v-model="tenantAgentConfigForm.model" type="text" :disabled="!canManageAgentConfig || adminSaving" />
                  </label>

                  <label>
                    <span>{{ t('admin.agentConfig.promptVersion') }}</span>
                    <input v-model="tenantAgentConfigForm.promptVersion" type="text" :disabled="!canManageAgentConfig || adminSaving" />
                  </label>

                  <label class="checkbox-field checkbox-field-surface">
                    <input v-model="tenantAgentConfigForm.isActive" type="checkbox" :disabled="!canManageAgentConfig || adminSaving" />
                    <span>{{ t('admin.agentConfig.active') }}</span>
                  </label>

                  <label class="checkbox-field checkbox-field-surface">
                    <input v-model="tenantAgentConfigForm.automationEnabled" type="checkbox" :disabled="!canManageAgentConfig || adminSaving" />
                    <span>{{ t('admin.agentConfig.automationEnabled') }}</span>
                  </label>
                </div>

                <label>
                  <span>{{ t('admin.agentConfig.systemPrompt') }}</span>
                  <textarea
                    v-model="tenantAgentConfigForm.systemPrompt"
                    rows="5"
                    :disabled="!canManageAgentConfig || adminSaving"
                  />
                </label>

                <button class="primary-button" type="submit" :disabled="!canManageAgentConfig || adminSaving">
                  {{ t('admin.agentConfig.saveTenant') }}
                </button>
              </form>

              <div class="table-stack subsection">
                <article
                  v-for="line in adminOverview.whatsapp_lines"
                  :key="line.id"
                  class="mini-card"
                >
                  <div class="subsection-header">
                    <strong>{{ lineLabel(line) }}</strong>
                  </div>

                  <div v-if="lineAgentConfigDrafts[line.id]" class="field-grid">
                    <label>
                      <span>{{ t('admin.tenant.name') }}</span>
                      <input v-model="lineAgentConfigDrafts[line.id].name" type="text" :disabled="!canManageAgentConfig || adminSaving" />
                    </label>

                    <label>
                      <span>{{ t('admin.agentConfig.model') }}</span>
                      <input v-model="lineAgentConfigDrafts[line.id].model" type="text" :disabled="!canManageAgentConfig || adminSaving" />
                    </label>

                    <label>
                      <span>{{ t('admin.agentConfig.promptVersion') }}</span>
                      <input v-model="lineAgentConfigDrafts[line.id].promptVersion" type="text" :disabled="!canManageAgentConfig || adminSaving" />
                    </label>

                    <label class="checkbox-field checkbox-field-surface">
                      <input v-model="lineAgentConfigDrafts[line.id].isActive" type="checkbox" :disabled="!canManageAgentConfig || adminSaving" />
                      <span>{{ t('admin.agentConfig.active') }}</span>
                    </label>

                    <label class="checkbox-field checkbox-field-surface">
                      <input v-model="lineAgentConfigDrafts[line.id].automationEnabled" type="checkbox" :disabled="!canManageAgentConfig || adminSaving" />
                      <span>{{ t('admin.agentConfig.automationEnabled') }}</span>
                    </label>
                  </div>

                  <label v-if="lineAgentConfigDrafts[line.id]">
                    <span>{{ t('admin.agentConfig.systemPrompt') }}</span>
                    <textarea
                      v-model="lineAgentConfigDrafts[line.id].systemPrompt"
                      rows="4"
                      :disabled="!canManageAgentConfig || adminSaving"
                    />
                  </label>

                  <button
                    class="ghost-button"
                    type="button"
                    :disabled="!canManageAgentConfig || adminSaving"
                    @click="saveLineAgentSettings(line.id)"
                  >
                    {{ t('admin.agentConfig.saveOverride') }}
                  </button>
                </article>
              </div>
            </article>

            <article class="surface admin-panel">
              <div class="panel-topline">
                <div>
                  <p class="eyebrow">{{ t('admin.dataSources.eyebrow') }}</p>
                  <h3>{{ t('admin.dataSources.title') }}</h3>
                </div>
                <span class="status-pill">{{ t('admin.dataSources.count', { count: adminOverview.data_sources.length }) }}</span>
              </div>

              <form class="form-stack" @submit.prevent="uploadDataSource">
                <div class="field-grid">
                  <label>
                    <span>{{ t('admin.dataSources.visibleName') }}</span>
                    <input v-model="uploadDataSourceName" type="text" :disabled="!canManageAgentConfig || adminSaving" />
                  </label>

                  <label>
                    <span>{{ t('admin.dataSources.acceptedFiles') }}</span>
                    <input type="file" accept=".pdf,.txt,.csv,.xlsx" :disabled="!canManageAgentConfig || adminSaving" @change="onDataSourceFileChange" />
                  </label>
                </div>

                <button
                  class="primary-button"
                  type="submit"
                  :disabled="!canManageAgentConfig || adminSaving || !uploadDataSourceFile"
                >
                  {{ t('admin.dataSources.upload') }}
                </button>
              </form>

              <div class="table-stack subsection">
                <article
                  v-for="source in adminOverview.data_sources"
                  :key="source.id"
                  class="mini-card"
                >
                  <div class="mini-card-header">
                    <div>
                      <strong>{{ source.name }}</strong>
                      <p class="muted-copy">
                        {{ source.latest_upload?.original_name || t('common.noFile') }}
                        · {{ formatBytes(source.latest_upload?.size_bytes) }}
                      </p>
                    </div>

                    <span class="status-chip">{{ translateDataSourceStatus(source.status) }}</span>
                  </div>

                  <div class="metrics-row">
                    <span>{{ t('admin.dataSources.chunkCount', { count: source.chunk_count }) }}</span>
                    <span>{{ t('admin.dataSources.attempts', { count: source.latest_import?.attempts_count ?? 0 }) }}</span>
                    <span>{{ t('admin.dataSources.lastSync', { value: formatTimestamp(source.last_synced_at) }) }}</span>
                  </div>

                  <p v-if="source.latest_import?.error_message" class="error-copy">
                    {{ source.latest_import.error_message }}
                  </p>

                  <div class="inline-form">
                    <button
                      class="ghost-button"
                      type="button"
                      :disabled="!canManageAgentConfig || adminSaving || source.latest_import?.status !== 'failed'"
                      @click="retryImport(source)"
                    >
                      {{ t('admin.dataSources.retry') }}
                    </button>
                  </div>
                </article>
              </div>
            </article>

            <article class="surface admin-panel">
              <div class="panel-topline">
                <div>
                  <p class="eyebrow">{{ t('admin.bindings.eyebrow') }}</p>
                  <h3>{{ t('admin.bindings.title') }}</h3>
                </div>
                <span class="status-pill">{{ t('admin.bindings.toolsCount', { count: bindingTools.length }) }}</span>
              </div>

              <div class="table-stack">
                <article v-for="toolName in bindingTools" :key="toolName" class="mini-card">
                  <div class="subsection-header">
                    <strong>{{ t('admin.bindings.tenantScope', { toolName }) }}</strong>
                  </div>

                  <div v-if="tenantToolDrafts[toolName]" class="field-grid">
                    <label class="checkbox-field checkbox-field-surface">
                      <input v-model="tenantToolDrafts[toolName].enabled" type="checkbox" :disabled="!canManageAgentConfig || adminSaving" />
                      <span>{{ t('admin.bindings.enabled') }}</span>
                    </label>

                    <label>
                      <span>{{ t('admin.bindings.timeout') }}</span>
                      <input v-model="tenantToolDrafts[toolName].timeoutSeconds" type="number" min="1" max="120" :disabled="!canManageAgentConfig || adminSaving" />
                    </label>

                    <label>
                      <span>{{ t('admin.bindings.dataSource') }}</span>
                      <select v-model="tenantToolDrafts[toolName].dataSourceId" :disabled="!canManageAgentConfig || adminSaving">
                        <option value="">{{ t('admin.bindings.automaticFallback') }}</option>
                        <option v-for="source in readyDataSources" :key="source.id" :value="String(source.id)">
                          {{ source.name }} · {{ translateDataSourceStatus(source.status) }}
                        </option>
                      </select>
                    </label>
                  </div>

                  <button
                    class="ghost-button"
                    type="button"
                    :disabled="!canManageAgentConfig || adminSaving"
                    @click="saveTenantToolBinding(toolName)"
                  >
                    {{ t('admin.bindings.saveTenant') }}
                  </button>
                </article>
              </div>

              <div class="table-stack subsection">
                <article
                  v-for="line in adminOverview.whatsapp_lines"
                  :key="line.id"
                  class="mini-card"
                >
                  <div class="subsection-header">
                    <strong>{{ lineLabel(line) }}</strong>
                  </div>

                  <div
                    v-for="toolName in bindingTools"
                    :key="toolDraftKey(line.id, toolName)"
                    class="embedded-section"
                  >
                    <div class="subsection-header">
                      <span>{{ toolName }}</span>
                    </div>

                    <div v-if="lineToolDrafts[toolDraftKey(line.id, toolName)]" class="field-grid">
                      <label class="checkbox-field checkbox-field-surface">
                        <input v-model="lineToolDrafts[toolDraftKey(line.id, toolName)].enabled" type="checkbox" :disabled="!canManageAgentConfig || adminSaving" />
                        <span>{{ t('admin.bindings.enabled') }}</span>
                      </label>

                      <label>
                        <span>{{ t('admin.bindings.timeout') }}</span>
                        <input v-model="lineToolDrafts[toolDraftKey(line.id, toolName)].timeoutSeconds" type="number" min="1" max="120" :disabled="!canManageAgentConfig || adminSaving" />
                      </label>

                      <label>
                        <span>{{ t('admin.bindings.dataSource') }}</span>
                        <select v-model="lineToolDrafts[toolDraftKey(line.id, toolName)].dataSourceId" :disabled="!canManageAgentConfig || adminSaving">
                          <option value="">{{ t('admin.bindings.tenantFallback') }}</option>
                          <option v-for="source in readyDataSources" :key="source.id" :value="String(source.id)">
                            {{ source.name }} · {{ translateDataSourceStatus(source.status) }}
                          </option>
                        </select>
                      </label>
                    </div>

                    <button
                      class="ghost-button"
                      type="button"
                      :disabled="!canManageAgentConfig || adminSaving"
                      @click="saveLineToolBinding(line.id, toolName)"
                    >
                      {{ t('admin.bindings.saveOverride') }}
                    </button>
                  </div>
                </article>
              </div>
            </article>

            <article class="surface admin-panel">
              <div class="panel-topline">
                <div>
                  <p class="eyebrow">{{ t('admin.credentials.eyebrow') }}</p>
                  <h3>{{ t('admin.credentials.title') }}</h3>
                </div>
                <span class="status-pill">{{ t('admin.credentials.count', { count: adminOverview.credential_metadata.length }) }}</span>
              </div>

              <div class="table-stack">
                <article
                  v-for="credential in adminOverview.credential_metadata"
                  :key="credential.id"
                  class="mini-card"
                >
                  <div class="mini-card-header">
                    <div>
                      <strong>{{ credential.provider }} / {{ credential.credential_key }}</strong>
                      <p class="muted-copy">
                        {{ credential.scope_type === 'tenant' ? t('common.scopes.tenant') : lineLabel(credential.whatsapp_line) }}
                      </p>
                    </div>
                    <span class="status-chip">{{ translateCredentialStatus(credential.has_secret) }}</span>
                  </div>

                  <div class="metrics-row">
                    <span>{{ t('admin.credentials.lastUsed', { value: formatTimestamp(credential.last_used_at) }) }}</span>
                    <span>{{ t('admin.credentials.updatedAt', { value: formatTimestamp(credential.updated_at) }) }}</span>
                  </div>
                </article>
              </div>

              <form v-if="canManagePlatform" class="form-stack subsection" @submit.prevent="saveCredential">
                <div class="subsection-header">
                  <strong>{{ t('admin.credentials.formTitle') }}</strong>
                </div>

                <div class="field-grid">
                  <label>
                    <span>{{ t('admin.credentials.scope') }}</span>
                    <select v-model="credentialForm.scopeType" :disabled="adminSaving">
                      <option value="tenant">{{ translateScope('tenant') }}</option>
                      <option value="whatsapp_line">{{ translateScope('whatsapp_line') }}</option>
                    </select>
                  </label>

                  <label v-if="credentialForm.scopeType === 'whatsapp_line'">
                    <span>{{ t('sandbox.line') }}</span>
                    <select v-model="credentialForm.whatsappLineId" :disabled="adminSaving">
                      <option value="">{{ t('common.selectLine') }}</option>
                      <option v-for="line in adminOverview.whatsapp_lines" :key="line.id" :value="String(line.id)">
                        {{ lineLabel(line) }}
                      </option>
                    </select>
                  </label>

                  <label>
                    <span>{{ t('admin.credentials.provider') }}</span>
                    <input v-model="credentialForm.provider" type="text" :disabled="adminSaving" />
                  </label>

                  <label>
                    <span>{{ t('admin.credentials.credentialKey') }}</span>
                    <input v-model="credentialForm.credentialKey" type="text" :disabled="adminSaving" />
                  </label>
                </div>

                <label>
                  <span>{{ t('admin.credentials.secret') }}</span>
                  <textarea v-model="credentialForm.secret" rows="3" :disabled="adminSaving" />
                </label>

                <button class="primary-button" type="submit" :disabled="adminSaving">
                  {{ t('admin.credentials.save') }}
                </button>
              </form>
            </article>

            <article class="surface admin-panel admin-panel-wide">
              <div class="panel-topline">
                <div>
                  <p class="eyebrow">{{ t('admin.logs.eyebrow') }}</p>
                  <h3>{{ t('admin.logs.title') }}</h3>
                </div>
                <span class="status-pill">{{ t('admin.logs.recent') }}</span>
              </div>

              <div class="logs-grid">
                <div class="log-column">
                  <strong>{{ t('admin.logs.agentEvents') }}</strong>
                  <article
                    v-for="event in adminOverview.logs.agent_events"
                    :key="`agent-${event.id}`"
                    class="log-card"
                  >
                    <div class="mini-card-header">
                      <span>{{ event.event_type }}</span>
                      <span>{{ formatTimestamp(event.occurred_at) }}</span>
                    </div>
                    <p class="muted-copy">{{ eventPreview(event.payload) }}</p>
                  </article>
                </div>

                <div class="log-column">
                  <strong>{{ t('admin.logs.auditEvents') }}</strong>
                  <article
                    v-for="event in adminOverview.logs.audit_events"
                    :key="`audit-${event.id}`"
                    class="log-card"
                  >
                    <div class="mini-card-header">
                      <span>{{ event.event_type }}</span>
                      <span>{{ formatTimestamp(event.occurred_at) }}</span>
                    </div>
                    <p class="muted-copy">
                      {{ event.actor_user?.email || t('common.system') }} · {{ eventPreview(event.payload) }}
                    </p>
                  </article>
                </div>

                <div class="log-column">
                  <strong>{{ t('admin.logs.toolExecutions') }}</strong>
                  <article
                    v-for="execution in adminOverview.logs.tool_executions"
                    :key="`tool-${execution.id}`"
                    class="log-card"
                  >
                    <div class="mini-card-header">
                      <span>{{ execution.tool_name }} · {{ execution.status }}</span>
                      <span>{{ formatTimestamp(execution.executed_at || execution.created_at) }}</span>
                    </div>
                    <p class="muted-copy">
                      {{ execution.error_message || eventPreview(execution.output_summary) }}
                    </p>
                  </article>
                </div>
              </div>
            </article>
          </section>
        </template>
      </section>
    </section>
  </main>
</template>
