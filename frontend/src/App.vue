<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
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

type TenantMembership = SessionMembership & {
  tenant_id: number;
  tenant_name: string;
  tenant_slug: string;
};

type WorkspaceSection = 'operations' | 'admin';

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
    return 'Sin fecha';
  }

  return new Intl.DateTimeFormat('es-CO', {
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
    return 'Tenant';
  }

  return `${line.name}${line.display_phone_number ? ` · ${line.display_phone_number}` : ''}`;
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
    authError.value =
      error instanceof Error ? error.message : 'No fue posible cargar la sesión.';
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
    authError.value =
      error instanceof Error ? error.message : 'No fue posible iniciar sesión.';
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
    authError.value =
      error instanceof Error ? error.message : 'No fue posible cerrar sesión.';
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
    inboxError.value =
      error instanceof Error
        ? error.message
        : 'No fue posible cargar el inbox.';
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
    threadError.value =
      error instanceof Error ? error.message : 'No fue posible cargar el thread.';
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
    actionError.value =
      error instanceof Error
        ? error.message
        : 'La operación no se pudo completar.';
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
    adminError.value =
      error instanceof Error
        ? error.message
        : 'No fue posible cargar la configuración administrativa.';
  } finally {
    adminLoading.value = false;
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
    adminError.value =
      error instanceof Error
        ? error.message
        : 'La operación administrativa no se pudo completar.';
  } finally {
    adminSaving.value = false;
  }
}

async function saveTenantSettings(): Promise<void> {
  if (!selectedTenantId.value) {
    return;
  }

  await withAdminAction('Tenant actualizado.', async () => {
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
  await withAdminAction('Tenant creado.', async () => {
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

  await withAdminAction('Usuario agregado al tenant.', async () => {
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

  await withAdminAction('Rol actualizado.', async () => {
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

  await withAdminAction('Usuario removido del tenant.', async () => {
    await deleteTenantUser(selectedTenantId.value!, tenantUserId);
    await loadAdminWorkspace();
  });
}

async function createLine(): Promise<void> {
  if (!selectedTenantId.value) {
    return;
  }

  await withAdminAction('Línea creada.', async () => {
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

  await withAdminAction('Línea actualizada.', async () => {
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

  await withAdminAction('Línea eliminada.', async () => {
    await deleteWhatsAppLine(selectedTenantId.value!, lineId);
    await loadAdminWorkspace();
  });
}

async function saveTenantAgentSettings(): Promise<void> {
  if (!selectedTenantId.value) {
    return;
  }

  await withAdminAction('Configuración tenant actualizada.', async () => {
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

  await withAdminAction('Override de línea actualizado.', async () => {
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

  await withAdminAction(`Binding ${toolName} actualizado.`, async () => {
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

  await withAdminAction(`Binding ${toolName} por línea actualizado.`, async () => {
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

  await withAdminAction('Credencial almacenada.', async () => {
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

  await withAdminAction('Fuente de conocimiento cargada.', async () => {
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

  await withAdminAction('Importación reintentada.', async () => {
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
  adminOverview.value = null;
  adminSuccess.value = null;
  adminError.value = null;

  if (workspaceSection.value === 'operations') {
    await loadInbox();
    return;
  }

  await loadAdminWorkspace();
});

watch(selectedConversationId, async (conversationId) => {
  if (workspaceSection.value === 'operations') {
    await loadConversation(conversationId);
  }
});

watch(workspaceSection, async (section) => {
  if (section === 'operations') {
    await loadInbox();
    await loadConversation(selectedConversationId.value);
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
        <p class="eyebrow">Control Plane + Operations</p>
        <h1>{{ appConfig.appName }}</h1>
        <p class="lede">
          Consola unificada para operar conversaciones, tenants, líneas,
          prompts, fuentes de conocimiento y bindings del runtime.
        </p>
      </div>

      <div class="hero-meta">
        <a :href="appConfig.backendHealthUrl" target="_blank" rel="noreferrer">
          API health
        </a>
      </div>
    </section>

    <section v-if="authLoading && !session" class="surface centered-state">
      <p>Cargando sesión operativa…</p>
    </section>

    <section v-else-if="!session?.authenticated" class="login-layout">
      <article class="surface login-card">
        <p class="eyebrow">Operator Access</p>
        <h2>Iniciar sesión</h2>
        <p class="muted-copy">
          Usa la sesión mínima con Sanctum para entrar al panel.
        </p>

        <form class="form-stack" @submit.prevent="submitLogin">
          <label>
            <span>Email</span>
            <input v-model="loginEmail" type="email" autocomplete="email" required />
          </label>

          <label>
            <span>Password</span>
            <input
              v-model="loginPassword"
              type="password"
              autocomplete="current-password"
              required
            />
          </label>

          <p v-if="authError" class="error-copy">{{ authError }}</p>

          <button class="primary-button" type="submit" :disabled="authLoading">
            {{ authLoading ? 'Entrando…' : 'Entrar al panel' }}
          </button>
        </form>
      </article>
    </section>

    <section v-else class="workspace-shell">
      <header class="surface topbar">
        <div class="identity-block">
          <p class="eyebrow">Authenticated Session</p>
          <h2>{{ currentUser?.name }}</h2>
          <p class="muted-copy">{{ currentUser?.email }}</p>
        </div>

        <div class="topbar-controls">
          <label class="compact-field">
            <span>Tenant</span>
            <select v-model.number="selectedTenantId">
              <option
                v-for="membership in memberships"
                :key="membership.tenant_id"
                :value="membership.tenant_id ?? undefined"
              >
                {{ membership.tenant_name }} · {{ membership.role }}
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
              Operaciones
            </button>
            <button
              type="button"
              class="segment-button"
              :class="{ 'segment-button-active': workspaceSection === 'admin' }"
              @click="workspaceSection = 'admin'"
            >
              Admin
            </button>
          </div>

          <button class="ghost-button" type="button" :disabled="authLoading" @click="submitLogout">
            {{ authLoading ? 'Saliendo…' : 'Cerrar sesión' }}
          </button>
        </div>
      </header>

      <section v-if="workspaceSection === 'operations'">
        <section v-if="selectedMembership" class="workspace-grid">
          <aside class="surface inbox-panel">
            <div class="panel-topline">
              <div>
                <p class="eyebrow">Inbox</p>
                <h3>{{ selectedMembership.tenant_name }}</h3>
              </div>
              <span class="status-pill">
                {{ conversations.length }} threads
              </span>
            </div>

            <div class="filters-stack">
              <label>
                <span>Buscar</span>
                <input
                  v-model="search"
                  type="search"
                  placeholder="Nombre o teléfono"
                  @change="loadInbox"
                />
              </label>

              <div class="inline-filters">
                <label>
                  <span>Estado</span>
                  <select v-model="statusFilter" @change="loadInbox">
                    <option v-for="option in statusOptions" :key="option" :value="option">
                      {{ option }}
                    </option>
                  </select>
                </label>

                <label class="checkbox-field">
                  <input
                    v-model="assignedToMeOnly"
                    type="checkbox"
                    @change="loadInbox"
                  />
                  <span>Solo mías</span>
                </label>
              </div>
            </div>

            <p v-if="inboxError" class="error-copy">{{ inboxError }}</p>
            <p v-else-if="inboxLoading" class="muted-copy">Actualizando inbox…</p>
            <p v-else-if="!canViewConversations" class="muted-copy">
              Este rol no puede ver conversaciones en este tenant.
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
                      {{ conversation.status }}
                    </span>
                  </div>
                  <p class="thread-preview">
                    {{ conversation.last_message_preview || 'Sin mensajes visibles' }}
                  </p>
                  <div class="thread-card-meta">
                    <span>
                      {{
                        conversation.assigned_to_user
                          ? `Owner: ${conversation.assigned_to_user.name}`
                          : 'Sin owner'
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
                  <p class="eyebrow">Thread</p>
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
                    {{ selectedConversation.status }}
                  </span>
                </div>
              </div>

              <div class="detail-grid">
                <article class="detail-card">
                  <p class="detail-label">Owner actual</p>
                  <strong>
                    {{
                      selectedConversation.assigned_to_user?.name ??
                      'Sin asignación'
                    }}
                  </strong>
                  <p class="muted-copy">
                    Último mensaje: {{ formatTimestamp(selectedConversation.last_message_at) }}
                  </p>
                </article>

                <article class="detail-card">
                  <p class="detail-label">Último handoff</p>
                  <strong>{{ selectedConversation.latest_handoff?.status ?? 'Sin registro' }}</strong>
                  <p class="muted-copy">
                    {{ selectedConversation.latest_handoff?.reason || 'Sin motivo registrado.' }}
                  </p>
                </article>

                <article class="detail-card">
                  <p class="detail-label">Estado runtime</p>
                  <strong>{{ selectedConversation.state?.last_agent_action ?? 'Sin snapshot' }}</strong>
                  <p class="muted-copy">
                    {{ selectedConversation.state?.current_intent ?? 'Sin intent actual.' }}
                  </p>
                </article>
              </div>

              <div class="operator-tools">
                <div class="operator-toolset">
                  <label class="wide-field">
                    <span>Motivo handoff</span>
                    <input
                      v-model="handoffReason"
                      type="text"
                      placeholder="Contexto breve para el takeover"
                      :disabled="!canManageHandoffs || actionLoading"
                    />
                  </label>

                  <button
                    class="accent-button"
                    type="button"
                    :disabled="!canManageHandoffs || actionLoading"
                    @click="takeConversation"
                  >
                    {{ actionLoading ? 'Procesando…' : 'Tomar conversación' }}
                  </button>
                </div>

                <div class="operator-toolset">
                  <label class="wide-field">
                    <span>Asignar operador</span>
                    <select
                      v-model.number="selectedAssigneeId"
                      :disabled="!canManageHandoffs || actionLoading"
                    >
                      <option
                        v-for="operator in availableOperators"
                        :key="operator.user_id"
                        :value="operator.user_id"
                      >
                        {{ operator.name }} · {{ operator.role }}
                      </option>
                    </select>
                  </label>

                  <button
                    class="ghost-button"
                    type="button"
                    :disabled="!canManageHandoffs || !selectedAssigneeId || actionLoading"
                    @click="reassignConversation"
                  >
                    Reasignar
                  </button>
                </div>
              </div>

              <p v-if="actionError" class="error-copy">{{ actionError }}</p>
              <p v-if="threadError" class="error-copy">{{ threadError }}</p>
              <p v-else-if="threadLoading" class="muted-copy">Cargando thread…</p>

              <div class="messages-panel">
                <article
                  v-for="message in thread.messages"
                  :key="message.id"
                  class="message-bubble"
                  :class="message.direction === 'outbound' ? 'message-outbound' : 'message-inbound'"
                >
                  <header>
                    <strong>
                      {{ message.direction === 'outbound' ? 'Outbound' : 'Inbound' }}
                    </strong>
                    <span>{{ message.source || 'unknown' }}</span>
                  </header>
                  <p>{{ message.body || '[mensaje sin body]' }}</p>
                  <footer>
                    <span>{{ message.status || 'n/a' }}</span>
                    <span>{{ formatTimestamp(message.created_at) }}</span>
                  </footer>
                </article>
              </div>

              <form class="composer-panel" @submit.prevent="submitManualReply">
                <label class="wide-field">
                  <span>Reply manual</span>
                  <textarea
                    v-model="manualReplyBody"
                    rows="4"
                    placeholder="Responder manualmente en texto…"
                    :disabled="!canManuallyReply || actionLoading"
                  />
                </label>

                <div class="composer-actions">
                  <button
                    class="primary-button"
                    type="submit"
                    :disabled="!canManuallyReply || manualReplyBody.trim() === '' || actionLoading"
                  >
                    Enviar reply manual
                  </button>

                  <button
                    class="ghost-button"
                    type="button"
                    :disabled="!canManageHandoffs || actionLoading"
                    @click="resumeBot('BOT_ACTIVE')"
                  >
                    Reactivar bot
                  </button>

                  <button
                    class="ghost-button"
                    type="button"
                    :disabled="!canManageHandoffs || actionLoading"
                    @click="resumeBot('WAITING_CUSTOMER')"
                  >
                    Reanudar en espera
                  </button>
                </div>

                <p class="muted-copy helper-copy">
                  El reply manual solo se habilita cuando la conversación está en
                  `HUMAN_HANDOFF` y te pertenece o está libre.
                </p>
              </form>
            </template>

            <div v-else class="centered-state">
              <p>Selecciona una conversación del inbox para abrir el thread.</p>
            </div>
          </section>
        </section>

        <section v-else class="surface centered-state">
          <p>La sesión autenticada no tiene memberships operativas disponibles.</p>
        </section>
      </section>

      <section v-else class="admin-shell">
        <section v-if="!selectedMembership" class="surface centered-state">
          <p>Selecciona un tenant para cargar la configuración administrativa.</p>
        </section>

        <section v-else-if="!canAccessAdmin" class="surface centered-state">
          <p>Este rol no tiene acceso al panel admin de este tenant.</p>
        </section>

        <section v-else-if="adminLoading && !adminOverview" class="surface centered-state">
          <p>Cargando configuración administrativa…</p>
        </section>

        <template v-else-if="adminOverview">
          <section class="admin-summary-grid">
            <article class="surface summary-card">
              <p class="detail-label">Tenant</p>
              <strong>{{ adminOverview.tenant.name }}</strong>
              <span>{{ adminOverview.tenant.status }}</span>
            </article>

            <article class="surface summary-card">
              <p class="detail-label">Líneas</p>
              <strong>{{ adminOverview.whatsapp_lines.length }}</strong>
              <span>{{ adminOverview.whatsapp_lines.filter((line) => line.is_enabled).length }} habilitadas</span>
            </article>

            <article class="surface summary-card">
              <p class="detail-label">Fuentes conocimiento</p>
              <strong>{{ adminOverview.data_sources.length }}</strong>
              <span>{{ adminOverview.data_sources.filter((source) => source.status === 'ready').length }} listas</span>
            </article>

            <article class="surface summary-card">
              <p class="detail-label">Credenciales</p>
              <strong>{{ adminOverview.credential_metadata.length }}</strong>
              <span>{{ adminOverview.credential_metadata.filter((item) => item.has_secret).length }} configuradas</span>
            </article>
          </section>

          <p v-if="adminError" class="error-copy section-copy">{{ adminError }}</p>
          <p v-if="adminSuccess" class="success-copy section-copy">{{ adminSuccess }}</p>

          <section class="admin-grid">
            <article class="surface admin-panel">
              <div class="panel-topline">
                <div>
                  <p class="eyebrow">Tenant</p>
                  <h3>Configuración base</h3>
                </div>
                <span class="status-pill">{{ adminOverview.tenant.slug }}</span>
              </div>

              <form class="form-stack" @submit.prevent="saveTenantSettings">
                <div class="field-grid">
                  <label>
                    <span>Nombre</span>
                    <input v-model="tenantForm.name" type="text" :disabled="!canManageTenant || adminSaving" />
                  </label>

                  <label>
                    <span>Slug</span>
                    <input v-model="tenantForm.slug" type="text" :disabled="!canManageTenant || adminSaving" />
                  </label>

                  <label>
                    <span>Status</span>
                    <input v-model="tenantForm.status" type="text" :disabled="!canManageTenant || adminSaving" />
                  </label>
                </div>

                <button class="primary-button" type="submit" :disabled="!canManageTenant || adminSaving">
                  Guardar tenant
                </button>
              </form>

              <div v-if="canManagePlatform" class="subsection">
                <div class="subsection-header">
                  <strong>Crear tenant</strong>
                  <span class="muted-copy">{{ adminTenants.length }} visibles</span>
                </div>

                <form class="form-stack" @submit.prevent="createNewTenant">
                  <div class="field-grid">
                    <label>
                      <span>Nombre</span>
                      <input v-model="createTenantForm.name" type="text" :disabled="adminSaving" />
                    </label>

                    <label>
                      <span>Slug</span>
                      <input v-model="createTenantForm.slug" type="text" :disabled="adminSaving" />
                    </label>

                    <label>
                      <span>Status</span>
                      <input v-model="createTenantForm.status" type="text" :disabled="adminSaving" />
                    </label>
                  </div>

                  <button class="ghost-button" type="submit" :disabled="adminSaving">
                    Crear tenant
                  </button>
                </form>
              </div>
            </article>

            <article class="surface admin-panel">
              <div class="panel-topline">
                <div>
                  <p class="eyebrow">Tenant Users</p>
                  <h3>Roles y acceso</h3>
                </div>
                <span class="status-pill">{{ adminOverview.tenant_users.length }} usuarios</span>
              </div>

              <div class="table-stack">
                <article
                  v-for="membership in adminOverview.tenant_users"
                  :key="membership.id"
                  class="mini-card"
                >
                  <div class="mini-card-header">
                    <div>
                      <strong>{{ membership.user?.name || 'Usuario sin perfil' }}</strong>
                      <p class="muted-copy">{{ membership.user?.email }}</p>
                    </div>

                    <span class="status-chip">{{ membership.role }}</span>
                  </div>

                  <div class="inline-form">
                    <label class="wide-field">
                      <span>Rol</span>
                      <select
                        v-model="tenantUserRoles[membership.id]"
                        :disabled="!canManageTenantUsers || adminSaving"
                      >
                        <option
                          v-for="role in adminOverview.available_roles"
                          :key="role"
                          :value="role"
                        >
                          {{ role }}
                        </option>
                      </select>
                    </label>

                    <button
                      class="ghost-button"
                      type="button"
                      :disabled="!canManageTenantUsers || adminSaving"
                      @click="saveTenantUserRole(membership.id)"
                    >
                      Guardar rol
                    </button>

                    <button
                      class="danger-button"
                      type="button"
                      :disabled="!canManageTenantUsers || adminSaving"
                      @click="removeTenantUser(membership.id)"
                    >
                      Remover
                    </button>
                  </div>
                </article>
              </div>

              <form class="form-stack subsection" @submit.prevent="addTenantUser">
                <div class="subsection-header">
                  <strong>Agregar usuario</strong>
                </div>

                <div class="field-grid">
                  <label>
                    <span>Nombre</span>
                    <input v-model="newTenantUserForm.name" type="text" :disabled="!canManageTenantUsers || adminSaving" />
                  </label>

                  <label>
                    <span>Email</span>
                    <input v-model="newTenantUserForm.email" type="email" :disabled="!canManageTenantUsers || adminSaving" />
                  </label>

                  <label>
                    <span>Password</span>
                    <input v-model="newTenantUserForm.password" type="password" :disabled="!canManageTenantUsers || adminSaving" />
                  </label>

                  <label>
                    <span>Rol</span>
                    <select v-model="newTenantUserForm.role" :disabled="!canManageTenantUsers || adminSaving">
                      <option v-for="role in adminOverview.available_roles" :key="role" :value="role">
                        {{ role }}
                      </option>
                    </select>
                  </label>
                </div>

                <button class="primary-button" type="submit" :disabled="!canManageTenantUsers || adminSaving">
                  Agregar al tenant
                </button>
              </form>
            </article>

            <article class="surface admin-panel">
              <div class="panel-topline">
                <div>
                  <p class="eyebrow">WhatsApp Lines</p>
                  <h3>Líneas y enablement</h3>
                </div>
                <span class="status-pill">{{ adminOverview.whatsapp_lines.length }} líneas</span>
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
                      {{ line.is_enabled ? 'enabled' : 'disabled' }}
                    </span>
                  </div>

                  <div v-if="lineDrafts[line.id]" class="field-grid">
                    <label>
                      <span>Nombre</span>
                      <input v-model="lineDrafts[line.id].name" type="text" :disabled="!canManageTenant || adminSaving" />
                    </label>

                    <label>
                      <span>Phone Number ID</span>
                      <input v-model="lineDrafts[line.id].phoneNumberId" type="text" :disabled="!canManageTenant || adminSaving" />
                    </label>

                    <label>
                      <span>Display Phone</span>
                      <input v-model="lineDrafts[line.id].displayPhoneNumber" type="text" :disabled="!canManageTenant || adminSaving" />
                    </label>

                    <label>
                      <span>WABA ID</span>
                      <input v-model="lineDrafts[line.id].wabaId" type="text" :disabled="!canManageTenant || adminSaving" />
                    </label>

                    <label>
                      <span>Status</span>
                      <input v-model="lineDrafts[line.id].status" type="text" :disabled="!canManageTenant || adminSaving" />
                    </label>

                    <label class="checkbox-field checkbox-field-surface">
                      <input v-model="lineDrafts[line.id].isEnabled" type="checkbox" :disabled="!canManageTenant || adminSaving" />
                      <span>Automation line enabled</span>
                    </label>
                  </div>

                  <div class="inline-form">
                    <button
                      class="ghost-button"
                      type="button"
                      :disabled="!canManageTenant || adminSaving"
                      @click="saveLine(line.id)"
                    >
                      Guardar línea
                    </button>

                    <button
                      class="danger-button"
                      type="button"
                      :disabled="!canManageTenant || adminSaving"
                      @click="removeLine(line.id)"
                    >
                      Eliminar
                    </button>
                  </div>
                </article>
              </div>

              <form class="form-stack subsection" @submit.prevent="createLine">
                <div class="subsection-header">
                  <strong>Crear línea</strong>
                </div>

                <div class="field-grid">
                  <label>
                    <span>Nombre</span>
                    <input v-model="newLineForm.name" type="text" :disabled="!canManageTenant || adminSaving" />
                  </label>

                  <label>
                    <span>Phone Number ID</span>
                    <input v-model="newLineForm.phoneNumberId" type="text" :disabled="!canManageTenant || adminSaving" />
                  </label>

                  <label>
                    <span>Display Phone</span>
                    <input v-model="newLineForm.displayPhoneNumber" type="text" :disabled="!canManageTenant || adminSaving" />
                  </label>

                  <label>
                    <span>WABA ID</span>
                    <input v-model="newLineForm.wabaId" type="text" :disabled="!canManageTenant || adminSaving" />
                  </label>

                  <label>
                    <span>Status</span>
                    <input v-model="newLineForm.status" type="text" :disabled="!canManageTenant || adminSaving" />
                  </label>

                  <label class="checkbox-field checkbox-field-surface">
                    <input v-model="newLineForm.isEnabled" type="checkbox" :disabled="!canManageTenant || adminSaving" />
                    <span>Habilitada</span>
                  </label>
                </div>

                <button class="primary-button" type="submit" :disabled="!canManageTenant || adminSaving">
                  Crear línea
                </button>
              </form>
            </article>

            <article class="surface admin-panel">
              <div class="panel-topline">
                <div>
                  <p class="eyebrow">Prompts + Automation</p>
                  <h3>Agent config</h3>
                </div>
                <span class="status-pill">{{ adminOverview.agent_configs.length }} scopes</span>
              </div>

              <form class="form-stack" @submit.prevent="saveTenantAgentSettings">
                <div class="subsection-header">
                  <strong>Tenant config</strong>
                </div>

                <div class="field-grid">
                  <label>
                    <span>Nombre</span>
                    <input v-model="tenantAgentConfigForm.name" type="text" :disabled="!canManageAgentConfig || adminSaving" />
                  </label>

                  <label>
                    <span>Modelo</span>
                    <input v-model="tenantAgentConfigForm.model" type="text" :disabled="!canManageAgentConfig || adminSaving" />
                  </label>

                  <label>
                    <span>Prompt version</span>
                    <input v-model="tenantAgentConfigForm.promptVersion" type="text" :disabled="!canManageAgentConfig || adminSaving" />
                  </label>

                  <label class="checkbox-field checkbox-field-surface">
                    <input v-model="tenantAgentConfigForm.isActive" type="checkbox" :disabled="!canManageAgentConfig || adminSaving" />
                    <span>Config activa</span>
                  </label>

                  <label class="checkbox-field checkbox-field-surface">
                    <input v-model="tenantAgentConfigForm.automationEnabled" type="checkbox" :disabled="!canManageAgentConfig || adminSaving" />
                    <span>Automation enabled</span>
                  </label>
                </div>

                <label>
                  <span>System prompt</span>
                  <textarea
                    v-model="tenantAgentConfigForm.systemPrompt"
                    rows="5"
                    :disabled="!canManageAgentConfig || adminSaving"
                  />
                </label>

                <button class="primary-button" type="submit" :disabled="!canManageAgentConfig || adminSaving">
                  Guardar tenant config
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
                      <span>Nombre</span>
                      <input v-model="lineAgentConfigDrafts[line.id].name" type="text" :disabled="!canManageAgentConfig || adminSaving" />
                    </label>

                    <label>
                      <span>Modelo</span>
                      <input v-model="lineAgentConfigDrafts[line.id].model" type="text" :disabled="!canManageAgentConfig || adminSaving" />
                    </label>

                    <label>
                      <span>Prompt version</span>
                      <input v-model="lineAgentConfigDrafts[line.id].promptVersion" type="text" :disabled="!canManageAgentConfig || adminSaving" />
                    </label>

                    <label class="checkbox-field checkbox-field-surface">
                      <input v-model="lineAgentConfigDrafts[line.id].isActive" type="checkbox" :disabled="!canManageAgentConfig || adminSaving" />
                      <span>Activa</span>
                    </label>

                    <label class="checkbox-field checkbox-field-surface">
                      <input v-model="lineAgentConfigDrafts[line.id].automationEnabled" type="checkbox" :disabled="!canManageAgentConfig || adminSaving" />
                      <span>Automation enabled</span>
                    </label>
                  </div>

                  <label v-if="lineAgentConfigDrafts[line.id]">
                    <span>System prompt</span>
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
                    Guardar override
                  </button>
                </article>
              </div>
            </article>

            <article class="surface admin-panel">
              <div class="panel-topline">
                <div>
                  <p class="eyebrow">Knowledge Sources</p>
                  <h3>Uploads e indexación</h3>
                </div>
                <span class="status-pill">{{ adminOverview.data_sources.length }} fuentes</span>
              </div>

              <form class="form-stack" @submit.prevent="uploadDataSource">
                <div class="field-grid">
                  <label>
                    <span>Nombre visible</span>
                    <input v-model="uploadDataSourceName" type="text" :disabled="!canManageAgentConfig || adminSaving" />
                  </label>

                  <label>
                    <span>Archivo .pdf, .txt, .csv o .xlsx</span>
                    <input type="file" accept=".pdf,.txt,.csv,.xlsx" :disabled="!canManageAgentConfig || adminSaving" @change="onDataSourceFileChange" />
                  </label>
                </div>

                <button
                  class="primary-button"
                  type="submit"
                  :disabled="!canManageAgentConfig || adminSaving || !uploadDataSourceFile"
                >
                  Cargar fuente
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
                        {{ source.latest_upload?.original_name || 'Sin archivo' }}
                        · {{ formatBytes(source.latest_upload?.size_bytes) }}
                      </p>
                    </div>

                    <span class="status-chip">{{ source.status }}</span>
                  </div>

                  <div class="metrics-row">
                    <span>{{ source.chunk_count }} chunks</span>
                    <span>Intentos: {{ source.latest_import?.attempts_count ?? 0 }}</span>
                    <span>Última sync: {{ formatTimestamp(source.last_synced_at) }}</span>
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
                      Retry import
                    </button>
                  </div>
                </article>
              </div>
            </article>

            <article class="surface admin-panel">
              <div class="panel-topline">
                <div>
                  <p class="eyebrow">Bindings</p>
                  <h3>`search_inventory` y `search_knowledge`</h3>
                </div>
                <span class="status-pill">{{ bindingTools.length }} tools</span>
              </div>

              <div class="table-stack">
                <article v-for="toolName in bindingTools" :key="toolName" class="mini-card">
                  <div class="subsection-header">
                    <strong>Tenant · {{ toolName }}</strong>
                  </div>

                  <div v-if="tenantToolDrafts[toolName]" class="field-grid">
                    <label class="checkbox-field checkbox-field-surface">
                      <input v-model="tenantToolDrafts[toolName].enabled" type="checkbox" :disabled="!canManageAgentConfig || adminSaving" />
                      <span>Habilitada</span>
                    </label>

                    <label>
                      <span>Timeout seconds</span>
                      <input v-model="tenantToolDrafts[toolName].timeoutSeconds" type="number" min="1" max="120" :disabled="!canManageAgentConfig || adminSaving" />
                    </label>

                    <label>
                      <span>Data source</span>
                      <select v-model="tenantToolDrafts[toolName].dataSourceId" :disabled="!canManageAgentConfig || adminSaving">
                        <option value="">Fallback automático</option>
                        <option v-for="source in readyDataSources" :key="source.id" :value="String(source.id)">
                          {{ source.name }} · {{ source.status }}
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
                    Guardar binding tenant
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
                        <span>Habilitada</span>
                      </label>

                      <label>
                        <span>Timeout seconds</span>
                        <input v-model="lineToolDrafts[toolDraftKey(line.id, toolName)].timeoutSeconds" type="number" min="1" max="120" :disabled="!canManageAgentConfig || adminSaving" />
                      </label>

                      <label>
                        <span>Data source</span>
                        <select v-model="lineToolDrafts[toolDraftKey(line.id, toolName)].dataSourceId" :disabled="!canManageAgentConfig || adminSaving">
                          <option value="">Fallback tenant</option>
                          <option v-for="source in readyDataSources" :key="source.id" :value="String(source.id)">
                            {{ source.name }} · {{ source.status }}
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
                      Guardar override
                    </button>
                  </div>
                </article>
              </div>
            </article>

            <article class="surface admin-panel">
              <div class="panel-topline">
                <div>
                  <p class="eyebrow">Credentials</p>
                  <h3>Metadata sensible</h3>
                </div>
                <span class="status-pill">{{ adminOverview.credential_metadata.length }} registros</span>
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
                        {{ credential.scope_type === 'tenant' ? 'Tenant' : lineLabel(credential.whatsapp_line) }}
                      </p>
                    </div>
                    <span class="status-chip">{{ credential.has_secret ? 'configured' : 'empty' }}</span>
                  </div>

                  <div class="metrics-row">
                    <span>Último uso: {{ formatTimestamp(credential.last_used_at) }}</span>
                    <span>Actualizada: {{ formatTimestamp(credential.updated_at) }}</span>
                  </div>
                </article>
              </div>

              <form v-if="canManagePlatform" class="form-stack subsection" @submit.prevent="saveCredential">
                <div class="subsection-header">
                  <strong>Registrar o reemplazar secreto</strong>
                </div>

                <div class="field-grid">
                  <label>
                    <span>Scope</span>
                    <select v-model="credentialForm.scopeType" :disabled="adminSaving">
                      <option value="tenant">tenant</option>
                      <option value="whatsapp_line">whatsapp_line</option>
                    </select>
                  </label>

                  <label v-if="credentialForm.scopeType === 'whatsapp_line'">
                    <span>Línea</span>
                    <select v-model="credentialForm.whatsappLineId" :disabled="adminSaving">
                      <option value="">Selecciona una línea</option>
                      <option v-for="line in adminOverview.whatsapp_lines" :key="line.id" :value="String(line.id)">
                        {{ lineLabel(line) }}
                      </option>
                    </select>
                  </label>

                  <label>
                    <span>Provider</span>
                    <input v-model="credentialForm.provider" type="text" :disabled="adminSaving" />
                  </label>

                  <label>
                    <span>Credential key</span>
                    <input v-model="credentialForm.credentialKey" type="text" :disabled="adminSaving" />
                  </label>
                </div>

                <label>
                  <span>Secret</span>
                  <textarea v-model="credentialForm.secret" rows="3" :disabled="adminSaving" />
                </label>

                <button class="primary-button" type="submit" :disabled="adminSaving">
                  Guardar credencial
                </button>
              </form>
            </article>

            <article class="surface admin-panel admin-panel-wide">
              <div class="panel-topline">
                <div>
                  <p class="eyebrow">Logs</p>
                  <h3>Operación reciente</h3>
                </div>
                <span class="status-pill">20 recientes por stream</span>
              </div>

              <div class="logs-grid">
                <div class="log-column">
                  <strong>Agent events</strong>
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
                  <strong>Audit events</strong>
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
                      {{ event.actor_user?.email || 'system' }} · {{ eventPreview(event.payload) }}
                    </p>
                  </article>
                </div>

                <div class="log-column">
                  <strong>Tool executions</strong>
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
