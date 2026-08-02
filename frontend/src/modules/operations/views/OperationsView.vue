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
import {
  assignConversation,
  fetchConversation,
  fetchConversations,
  handoffConversation,
  resumeConversation,
  sendManualReply,
} from '../api';
import type {
  ConversationSummary,
  ConversationThreadPayload,
  OperatorOption,
} from '../types';

const statusOptions = ['ALL', 'BOT_ACTIVE', 'WAITING_CUSTOMER', 'HUMAN_HANDOFF', 'ERROR', 'CLOSED'];

const { t, locale } = useI18n();
const route = useRoute();
const router = useRouter();
const { currentUser } = useSession();
const { selectedMembership, selectedTenantId } = useTenantSelection();
const {
  canManageHandoffs,
  canReplyToConversations,
  canViewConversations,
} = useNavigationAccess(selectedMembership);

const inboxLoading = ref(false);
const inboxError = ref<string | null>(null);
const threadLoading = ref(false);
const threadError = ref<string | null>(null);
const actionLoading = ref(false);
const actionError = ref<string | null>(null);
const actionSuccess = ref<string | null>(null);
const conversations = ref<ConversationSummary[]>([]);
const thread = ref<ConversationThreadPayload | null>(null);
const searchInput = ref('');
const statusFilter = ref('ALL');
const assignedToMeOnly = ref(false);
const handoffReason = ref('');
const manualReplyBody = ref('');
const selectedAssigneeId = ref<number | null>(null);

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

function routeNumber(key: string): number | null {
  const raw = routeString(key);

  if (raw === '') {
    return null;
  }

  const parsed = Number(raw);
  return Number.isInteger(parsed) ? parsed : null;
}

function replaceQuery(patch: Record<string, string | undefined>): void {
  void router.replace({
    query: {
      ...route.query,
      ...patch,
    },
  });
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

function translateRole(role: string | null | undefined): string {
  return t(`common.roles.${role ?? 'unknown'}`);
}

function translateConversationStatus(status: string): string {
  return t(`common.conversationStatuses.${status}`);
}

function translateHandoffStatus(status: string | null | undefined): string {
  return status ? t(`common.handoffStatuses.${status}`) : t('operations.noRecord');
}

function resolveErrorMessage(error: unknown, fallbackKey: string): string {
  return error instanceof Error && error.message !== '' ? error.message : t(fallbackKey);
}

function initialAssignee(payload: ConversationThreadPayload | null): number | null {
  if (!payload) {
    return null;
  }

  return payload.conversation.assigned_to_user?.id ?? payload.available_operators[0]?.user_id ?? null;
}

const selectedConversationId = computed(() => routeNumber('conversation'));
const selectedConversation = computed(() => thread.value?.conversation ?? null);
const availableOperators = computed<OperatorOption[]>(() => thread.value?.available_operators ?? []);
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
      status: routeString('status') || 'ALL',
      search: routeString('search').trim(),
      assignedToMe: routeString('mine') === '1',
    });

    conversations.value = payload.data;

    const validSelection = payload.data.some((conversation) => conversation.id === selectedConversationId.value);
    const fallbackId = payload.data[0]?.id ?? null;

    if (!validSelection && fallbackId !== selectedConversationId.value) {
      replaceQuery({
        conversation: fallbackId ? String(fallbackId) : undefined,
      });
    }
  } catch (error) {
    inboxError.value = resolveErrorMessage(error, 'operations.loadInboxFailed');
  } finally {
    inboxLoading.value = false;
  }
}

async function loadConversation(): Promise<void> {
  if (!selectedTenantId.value || !selectedConversationId.value || !canViewConversations.value) {
    thread.value = null;
    selectedAssigneeId.value = null;
    return;
  }

  threadLoading.value = true;
  threadError.value = null;

  try {
    const payload = await fetchConversation(selectedTenantId.value, selectedConversationId.value);
    thread.value = payload.data;
    selectedAssigneeId.value = initialAssignee(payload.data);
  } catch (error) {
    threadError.value = resolveErrorMessage(error, 'operations.loadThreadFailed');
  } finally {
    threadLoading.value = false;
  }
}

async function reloadWorkspace(): Promise<void> {
  await loadInbox();
  await loadConversation();
}

async function withConversationAction(successKey: string, action: () => Promise<void>): Promise<void> {
  actionLoading.value = true;
  actionError.value = null;
  actionSuccess.value = null;

  try {
    await action();
    actionSuccess.value = t(successKey);
    await reloadWorkspace();
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

  await withConversationAction('operations.success.taken', async () => {
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

  await withConversationAction('operations.success.reassigned', async () => {
    await assignConversation(selectedTenantId.value!, selectedConversationId.value!, selectedAssigneeId.value!);
  });
}

async function submitManualReply(): Promise<void> {
  if (!selectedTenantId.value || !selectedConversationId.value || manualReplyBody.value.trim() === '') {
    return;
  }

  await withConversationAction('operations.success.manualReplySent', async () => {
    await sendManualReply(selectedTenantId.value!, selectedConversationId.value!, manualReplyBody.value.trim());
    manualReplyBody.value = '';
  });
}

async function resumeBot(targetStatus: 'BOT_ACTIVE' | 'WAITING_CUSTOMER'): Promise<void> {
  if (!selectedTenantId.value || !selectedConversationId.value) {
    return;
  }

  await withConversationAction(
    targetStatus === 'BOT_ACTIVE' ? 'operations.success.botReactivated' : 'operations.success.resumedWaiting',
    async () => {
      await resumeConversation(selectedTenantId.value!, selectedConversationId.value!, targetStatus);
    },
  );
}

function applyFilters(): void {
  replaceQuery({
    conversation: undefined,
    mine: assignedToMeOnly.value ? '1' : undefined,
    search: searchInput.value.trim() || undefined,
    status: statusFilter.value !== 'ALL' ? statusFilter.value : undefined,
  });
}

watch(
  () => route.query,
  () => {
    searchInput.value = routeString('search');
    statusFilter.value = routeString('status') || 'ALL';
    assignedToMeOnly.value = routeString('mine') === '1';
  },
  { immediate: true },
);

watch(
  [selectedTenantId, () => canViewConversations.value, () => route.query.search, () => route.query.status, () => route.query.mine],
  async () => {
    actionError.value = null;
    actionSuccess.value = null;
    await loadInbox();
  },
  { immediate: true },
);

watch(
  [selectedTenantId, () => canViewConversations.value, () => route.query.conversation],
  async () => {
    await loadConversation();
  },
  { immediate: true },
);
</script>

<template>
  <section v-if="!selectedMembership">
    <SurfaceCard>
      <EmptyState :title="t('states.noMembershipsTitle')" :description="t('operations.noMemberships')" />
    </SurfaceCard>
  </section>

  <section v-else-if="!canViewConversations">
    <SurfaceCard>
      <ForbiddenState :title="t('states.restrictedTitle')" :description="t('operations.noAccess')" />
    </SurfaceCard>
  </section>

  <section v-else class="panel-grid xl:min-h-0 xl:flex-1 xl:overflow-hidden">
    <SurfaceCard class="xl:flex xl:min-h-0 xl:flex-col">
      <div class="flex items-start justify-between gap-4 border-b pb-4" :style="{ borderColor: 'var(--border)' }">
        <div>
          <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-[var(--accent)]">
            {{ t('operations.eyebrow') }}
          </p>
          <h2 class="mt-2 text-2xl font-semibold tracking-tight">{{ selectedMembership.tenant_name }}</h2>
        </div>
        <StatusBadge :label="t('operations.threadsCount', { count: conversations.length })" tone="neutral" />
      </div>

      <form class="mt-5 grid gap-4 rounded-[20px] border bg-[var(--muted)] p-4" :style="{ borderColor: 'var(--border)' }" @submit.prevent="applyFilters">
        <FormField :label="t('operations.search')">
          <input
            v-model="searchInput"
            class="input-base"
            type="search"
            :placeholder="t('operations.searchPlaceholder')"
          />
        </FormField>

        <div class="grid gap-4">
          <FormField :label="t('operations.status')">
            <select v-model="statusFilter" class="input-base" @change="applyFilters">
              <option v-for="option in statusOptions" :key="option" :value="option">
                {{ translateConversationStatus(option) }}
              </option>
            </select>
          </FormField>

          <label class="flex items-center gap-3 rounded-xl border px-3.5 py-3 text-sm" :style="{ borderColor: 'var(--border)' }">
            <input v-model="assignedToMeOnly" type="checkbox" class="h-4 w-4" @change="applyFilters" />
            <span>{{ t('operations.assignedToMe') }}</span>
          </label>
        </div>

        <button class="btn-secondary w-full justify-center" type="submit">
          {{ t('common.applyFilters') }}
        </button>
      </form>

      <div class="mt-5 grid gap-3">
        <InlineAlert v-if="inboxError" :message="inboxError" tone="danger" />
      </div>

      <div class="mt-5 flex min-h-0 flex-1 flex-col">
        <LoadingState v-if="inboxLoading" :label="t('operations.loadingInbox')" />

        <ul v-else-if="conversations.length > 0" class="grid max-h-[52vh] gap-2 overflow-y-auto pr-1 xl:max-h-none xl:min-h-0 xl:flex-1">
          <li v-for="conversation in conversations" :key="conversation.id">
            <button
              class="w-full rounded-[20px] border px-4 py-4 text-left transition hover:border-[color:color-mix(in_srgb,var(--accent)_30%,var(--border))]"
              :class="
                selectedConversationId === conversation.id
                  ? 'border-[color:color-mix(in_srgb,var(--accent)_28%,var(--border))] bg-[var(--muted)] shadow-[0_12px_24px_rgba(15,23,42,0.08)]'
                  : 'bg-transparent'
              "
              :style="selectedConversationId === conversation.id ? undefined : { borderColor: 'var(--border)' }"
              type="button"
              @click="replaceQuery({ conversation: String(conversation.id) })"
            >
              <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                  <strong class="block truncate text-sm">{{ conversation.contact_name || conversation.contact_phone }}</strong>
                  <p class="mt-1 truncate text-xs text-[var(--text-mute)]">{{ conversation.contact_phone }}</p>
                </div>
                <StatusBadge :label="translateConversationStatus(conversation.status)" :status="conversation.status" />
              </div>
              <p class="mt-3 line-clamp-2 text-sm leading-6 text-[var(--text-mute)]">
                {{ conversation.last_message_preview || t('operations.noMessagesVisible') }}
              </p>
              <div class="mt-4 flex flex-wrap items-center justify-between gap-2 text-xs text-[var(--text-mute)]">
                <span>
                  {{
                    conversation.assigned_to_user
                      ? t('operations.ownerLabel', { name: conversation.assigned_to_user.name })
                      : t('operations.noOwner')
                  }}
                </span>
                <span>{{ formatTimestamp(conversation.last_message_at) }}</span>
              </div>
            </button>
          </li>
        </ul>

        <EmptyState
          v-else
          :title="t('states.emptyTitle')"
          :description="t('operations.emptyInbox')"
        />
      </div>
    </SurfaceCard>

    <SurfaceCard padding="lg" class="xl:flex xl:min-h-0 xl:flex-col">
      <template v-if="selectedConversation && thread">
        <div class="flex shrink-0 flex-col gap-4 border-b pb-5 lg:flex-row lg:items-start lg:justify-between" :style="{ borderColor: 'var(--border)' }">
          <div>
            <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-[var(--accent)]">
              {{ t('operations.threadEyebrow') }}
            </p>
            <h2 class="mt-2 text-2xl font-semibold tracking-tight">
              {{ selectedConversation.contact_name || selectedConversation.contact_phone }}
            </h2>
            <p class="mt-2 text-sm text-[var(--text-mute)]">
              {{ selectedConversation.contact_phone }}
              <template v-if="selectedConversation.whatsapp_line">
                · {{ selectedConversation.whatsapp_line.name }}
              </template>
            </p>
          </div>

          <StatusBadge
            :label="translateConversationStatus(selectedConversation.status)"
            :status="selectedConversation.status"
            large
          />
        </div>

        <div class="mt-5 grid gap-3 md:grid-cols-3">
          <div class="rounded-[18px] border bg-[var(--muted)] p-4" :style="{ borderColor: 'var(--border)' }">
            <p class="text-xs uppercase tracking-[0.2em] text-[var(--text-mute)]">{{ t('operations.currentOwner') }}</p>
            <strong class="mt-3 block text-base">
              {{ selectedConversation.assigned_to_user?.name ?? t('operations.noAssignment') }}
            </strong>
            <p class="mt-2 text-sm text-[var(--text-mute)]">
              {{ t('operations.lastMessage', { value: formatTimestamp(selectedConversation.last_message_at) }) }}
            </p>
          </div>

          <div class="rounded-[18px] border bg-[var(--muted)] p-4" :style="{ borderColor: 'var(--border)' }">
            <p class="text-xs uppercase tracking-[0.2em] text-[var(--text-mute)]">{{ t('operations.latestHandoff') }}</p>
            <strong class="mt-3 block text-base">
              {{ translateHandoffStatus(selectedConversation.latest_handoff?.status) }}
            </strong>
            <p class="mt-2 text-sm text-[var(--text-mute)]">
              {{ selectedConversation.latest_handoff?.reason || t('operations.noReason') }}
            </p>
          </div>

          <div class="rounded-[18px] border bg-[var(--muted)] p-4" :style="{ borderColor: 'var(--border)' }">
            <p class="text-xs uppercase tracking-[0.2em] text-[var(--text-mute)]">{{ t('operations.runtimeState') }}</p>
            <strong class="mt-3 block text-base">
              {{ selectedConversation.state?.last_agent_action ?? t('operations.noSnapshot') }}
            </strong>
            <p class="mt-2 text-sm text-[var(--text-mute)]">
              {{ selectedConversation.state?.current_intent ?? t('operations.noIntent') }}
            </p>
          </div>
        </div>

        <div class="mt-5 grid gap-4 xl:grid-cols-2">
          <div class="rounded-[20px] border bg-[var(--muted)] p-4" :style="{ borderColor: 'var(--border)' }">
            <FormField :label="t('operations.handoffReason')">
              <input
                v-model="handoffReason"
                class="input-base"
                type="text"
                :disabled="!canManageHandoffs || actionLoading"
                :placeholder="t('operations.handoffPlaceholder')"
              />
            </FormField>

            <button
              class="btn-primary mt-4 w-full justify-center"
              type="button"
              :disabled="!canManageHandoffs || actionLoading"
              @click="takeConversation"
            >
              {{ actionLoading ? t('operations.processing') : t('operations.takeConversation') }}
            </button>
          </div>

          <div class="rounded-[20px] border bg-[var(--muted)] p-4" :style="{ borderColor: 'var(--border)' }">
            <FormField :label="t('operations.assignOperator')">
              <select v-model.number="selectedAssigneeId" class="input-base" :disabled="!canManageHandoffs || actionLoading">
                <option v-for="operator in availableOperators" :key="operator.user_id" :value="operator.user_id">
                  {{ operator.name }} · {{ translateRole(operator.role) }}
                </option>
              </select>
            </FormField>

            <button
              class="btn-secondary mt-4 w-full justify-center"
              type="button"
              :disabled="!canManageHandoffs || !selectedAssigneeId || actionLoading"
              @click="reassignConversation"
            >
              {{ t('operations.reassign') }}
            </button>
          </div>
        </div>

        <div class="mt-6 grid gap-3">
          <InlineAlert v-if="actionError" :message="actionError" tone="danger" />
          <InlineAlert v-if="actionSuccess" :message="actionSuccess" tone="success" />
          <InlineAlert v-if="threadError" :message="threadError" tone="danger" />
        </div>

        <LoadingState v-if="threadLoading" class="mt-6" :label="t('operations.loadingThread')" />

        <div v-else class="mt-5 flex min-h-0 flex-1 flex-col overflow-hidden">
          <div class="min-h-[42vh] flex-1 overflow-y-auto rounded-[20px] border bg-[var(--muted)] p-3 sm:p-4 xl:min-h-0" :style="{ borderColor: 'var(--border)' }">
            <div class="space-y-3">
              <article
                v-for="message in thread.messages"
                :key="message.id"
                class="max-w-3xl rounded-[20px] border px-4 py-4"
                :class="message.direction === 'outbound' ? 'ml-auto bg-[color:color-mix(in_srgb,var(--accent)_10%,transparent)]' : 'bg-[var(--surface)]'"
                :style="{ borderColor: 'var(--border)' }"
              >
                <header class="flex items-center justify-between gap-3 text-xs uppercase tracking-[0.14em] text-[var(--text-mute)]">
                  <strong class="text-[11px] font-semibold text-[var(--text)]">
                    {{ message.direction === 'outbound' ? t('operations.outbound') : t('operations.inbound') }}
                  </strong>
                  <span>{{ message.source || t('common.unknown') }}</span>
                </header>
                <p class="mt-3 whitespace-pre-wrap text-sm leading-6">
                  {{ message.body || t('common.messageWithoutBody') }}
                </p>
                <footer class="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs text-[var(--text-mute)]">
                  <span>{{ message.status || t('common.notAvailable') }}</span>
                  <span>{{ formatTimestamp(message.created_at) }}</span>
                </footer>
              </article>
            </div>
          </div>

          <form class="mt-4 shrink-0 rounded-[20px] border bg-[var(--surface)] p-5" :style="{ borderColor: 'var(--border)' }" @submit.prevent="submitManualReply">
            <FormField :label="t('operations.manualReply')">
              <textarea
                v-model="manualReplyBody"
                class="input-base min-h-32 resize-y"
                rows="4"
                :disabled="!canManuallyReply || actionLoading"
                :placeholder="t('operations.manualReplyPlaceholder')"
              />
            </FormField>

            <div class="mt-5 flex flex-wrap gap-3">
              <button
                class="btn-primary"
                type="submit"
                :disabled="!canManuallyReply || manualReplyBody.trim() === '' || actionLoading"
              >
                {{ t('operations.sendManualReply') }}
              </button>

              <button class="btn-secondary" type="button" :disabled="!canManageHandoffs || actionLoading" @click="resumeBot('BOT_ACTIVE')">
                {{ t('operations.resumeBot') }}
              </button>

              <button class="btn-secondary" type="button" :disabled="!canManageHandoffs || actionLoading" @click="resumeBot('WAITING_CUSTOMER')">
                {{ t('operations.resumeWaiting') }}
              </button>
            </div>

            <p class="mt-4 text-sm leading-6 text-[var(--text-mute)]">
              {{ t('operations.helper') }}
            </p>
          </form>
        </div>
      </template>

      <template v-else-if="threadLoading">
        <LoadingState :label="t('operations.loadingThread')" />
      </template>

      <template v-else>
        <EmptyState :title="t('states.selectTitle')" :description="t('operations.noConversationSelected')" />
      </template>
    </SurfaceCard>
  </section>
</template>
