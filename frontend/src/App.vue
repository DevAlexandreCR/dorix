<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { appConfig } from './config/app';
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

const session = ref<SessionResponse | null>(null);
const authLoading = ref(true);
const authError = ref<string | null>(null);
const inboxLoading = ref(false);
const threadLoading = ref(false);
const actionLoading = ref(false);
const inboxError = ref<string | null>(null);
const threadError = ref<string | null>(null);
const actionError = ref<string | null>(null);
const conversations = ref<ConversationSummary[]>([]);
const thread = ref<ConversationThreadPayload | null>(null);
const selectedConversationId = ref<number | null>(null);
const selectedTenantId = ref<number | null>(null);
const selectedAssigneeId = ref<number | null>(null);
const loginEmail = ref('');
const loginPassword = ref('');
const manualReplyBody = ref('');
const handoffReason = ref('');
const search = ref('');
const statusFilter = ref('ALL');
const assignedToMeOnly = ref(false);

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

function formatTimestamp(value: string | null): string {
  if (!value) {
    return 'Sin fecha';
  }

  return new Intl.DateTimeFormat('es-CO', {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(value));
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

async function reloadWorkspace(): Promise<void> {
  await loadInbox();
  await loadConversation(selectedConversationId.value);
}

async function withConversationAction(
  action: () => Promise<void>,
): Promise<void> {
  actionLoading.value = true;
  actionError.value = null;

  try {
    await action();
    await reloadWorkspace();
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

watch(selectedTenantId, async () => {
  selectedConversationId.value = null;
  await loadInbox();
});

watch(selectedConversationId, async (conversationId) => {
  await loadConversation(conversationId);
});

onMounted(async () => {
  await refreshSession();
});
</script>

<template>
  <main class="ops-app">
    <section class="hero-band">
      <div>
        <p class="eyebrow">Operational Console</p>
        <h1>{{ appConfig.appName }}</h1>
        <p class="lede">
          Inbox interno para tomar conversaciones, responder manualmente y
          devolver control al bot sin herramientas externas.
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
          Usa la sesión mínima con Sanctum para entrar a la consola operativa.
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
            {{ authLoading ? 'Entrando…' : 'Entrar a la consola' }}
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

          <button class="ghost-button" type="button" :disabled="authLoading" @click="submitLogout">
            {{ authLoading ? 'Saliendo…' : 'Cerrar sesión' }}
          </button>
        </div>
      </header>

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
  </main>
</template>
