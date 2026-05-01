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
import { useTenantSelection } from '../../../composables/useTenantSelection';
import type { ConversationThreadMessage } from '../../operations/types';
import {
  closeSandboxSession,
  createSandboxSession,
  fetchSandboxSession,
  fetchSandboxSessions,
  sendSandboxMessage,
} from '../api';
import type { SandboxLineOption, SandboxLastTurn, SandboxSessionPayload, SandboxSessionSummary } from '../types';

const { t, locale } = useI18n();
const route = useRoute();
const router = useRouter();
const { selectedMembership, selectedTenantId } = useTenantSelection();
const { canAccessSandbox } = useNavigationAccess(selectedMembership);

const sandboxLoading = ref(false);
const sandboxError = ref<string | null>(null);
const sandboxThreadLoading = ref(false);
const sandboxThreadError = ref<string | null>(null);
const sandboxActionLoading = ref(false);
const sandboxActionError = ref<string | null>(null);
const sandboxActionSuccess = ref<string | null>(null);
const sandboxSessions = ref<SandboxSessionSummary[]>([]);
const sandboxThread = ref<SandboxSessionPayload | null>(null);
const sandboxAvailableLines = ref<SandboxLineOption[]>([]);
const sandboxLineId = ref<number | null>(null);
const sandboxMessageBody = ref('');

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

function formatMachineLabel(value: string | null | undefined): string {
  if (!value) {
    return t('common.notAvailable');
  }

  return value
    .split('_')
    .filter((part) => part !== '')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1).toLowerCase())
    .join(' ');
}

function translateSandboxStatus(status: string): string {
  return t(`sandbox.statusLabels.${status}`);
}

function translateHandoffStatus(status: string | null | undefined): string {
  return status ? t(`common.handoffStatuses.${status}`) : t('operations.noRecord');
}

function translateRuntimeOutcome(outcome: string | null | undefined): string {
  return outcome ? t(`sandbox.runtimeOutcomes.${outcome}`) : t('sandbox.noTurns');
}

function translateToolStatus(status: string): string {
  const translated = t(`sandbox.toolStatuses.${status}`);
  return translated === `sandbox.toolStatuses.${status}` ? formatMachineLabel(status) : translated;
}

function translateEventType(eventType: string): string {
  const translated = t(`sandbox.eventLabels.${eventType}`);
  return translated === `sandbox.eventLabels.${eventType}` ? formatMachineLabel(eventType) : translated;
}

function resolveErrorMessage(error: unknown, fallbackKey: string): string {
  return error instanceof Error && error.message !== '' ? error.message : t(fallbackKey);
}

function findLatestMessage(direction: string): ConversationThreadMessage | null {
  const messages = sandboxThread.value?.messages ?? [];

  for (let index = messages.length - 1; index >= 0; index -= 1) {
    if (messages[index]?.direction === direction) {
      return messages[index];
    }
  }

  return null;
}

const selectedSandboxConversationId = computed(() => routeNumber('conversation'));
const selectedSandboxConversation = computed(() => sandboxThread.value?.conversation ?? null);
const sandboxLastTurn = computed<SandboxLastTurn | null>(() => sandboxThread.value?.last_turn ?? null);
const selectedLine = computed(() =>
  sandboxAvailableLines.value.find((line) => line.id === sandboxLineId.value) ?? null,
);
const activeConversationLine = computed(() => selectedSandboxConversation.value?.whatsapp_line ?? selectedLine.value);
const latestAssistantMessage = computed(() => findLatestMessage('outbound'));
const isConversationClosed = computed(() => selectedSandboxConversation.value?.status === 'CLOSED');
const canSendSandboxMessage = computed(
  () =>
    selectedSandboxConversation.value !== null &&
    !isConversationClosed.value &&
    canAccessSandbox.value,
);
const canCreateConversation = computed(() => sandboxLineId.value !== null && !sandboxActionLoading.value);
const currentConversationStatusLabel = computed(() =>
  selectedSandboxConversation.value ? translateSandboxStatus(selectedSandboxConversation.value.status) : '',
);
const summaryStatusLabel = computed(() => {
  if (!selectedSandboxConversation.value) {
    return t('sandbox.summary.noMessages');
  }

  if (sandboxLastTurn.value?.error_message) {
    return t('sandbox.summary.error');
  }

  if (selectedSandboxConversation.value.status === 'HUMAN_HANDOFF' || sandboxLastTurn.value?.handoff_requested) {
    return t('sandbox.summary.needsReview');
  }

  if (selectedSandboxConversation.value.status === 'CLOSED') {
    return t('sandbox.summary.closed');
  }

  if (!sandboxLastTurn.value) {
    return t('sandbox.summary.noMessages');
  }

  return t('sandbox.summary.replySent');
});
const summaryStatusDescription = computed(() => {
  if (!selectedSandboxConversation.value) {
    return t('sandbox.summary.noMessagesDescription');
  }

  if (sandboxLastTurn.value?.error_message) {
    return sandboxLastTurn.value.error_message || t('sandbox.summary.errorDescription');
  }

  if (selectedSandboxConversation.value.status === 'HUMAN_HANDOFF' || sandboxLastTurn.value?.handoff_requested) {
    return selectedSandboxConversation.value.latest_handoff?.reason || t('sandbox.summary.needsReviewDescription');
  }

  if (selectedSandboxConversation.value.status === 'CLOSED') {
    return t('sandbox.summary.closedDescription');
  }

  if (!sandboxLastTurn.value) {
    return t('sandbox.summary.noMessagesDescription');
  }

  return t('sandbox.summary.replySentDescription');
});
const latestResponsePreview = computed(() => {
  const body = latestAssistantMessage.value?.body?.trim();
  return body && body !== '' ? body : t('sandbox.summary.noAssistantReply');
});
const reviewLabel = computed(() => {
  if (!selectedSandboxConversation.value || !sandboxLastTurn.value) {
    return t('sandbox.summary.reviewNotAvailable');
  }

  return selectedSandboxConversation.value.status === 'HUMAN_HANDOFF' || sandboxLastTurn.value.handoff_requested
    ? t('sandbox.summary.reviewRequired')
    : t('sandbox.summary.reviewNotRequired');
});
const reviewDescription = computed(() => {
  if (!selectedSandboxConversation.value || !sandboxLastTurn.value) {
    return t('operations.noRecord');
  }

  return selectedSandboxConversation.value.latest_handoff?.reason || t('sandbox.notEscalated');
});
const hasTechnicalDetails = computed(() => {
  if (!selectedSandboxConversation.value || !sandboxLastTurn.value) {
    return false;
  }

  return (
    sandboxLastTurn.value.runtime_outcome !== null ||
    sandboxLastTurn.value.error_message !== null ||
    sandboxLastTurn.value.tool_executions.length > 0 ||
    sandboxLastTurn.value.events.length > 0 ||
    selectedSandboxConversation.value.latest_handoff !== null
  );
});

async function loadSandboxSessionsList(): Promise<void> {
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

    const validSelection = payload.data.some((conversation) => conversation.id === selectedSandboxConversationId.value);
    const fallbackId = payload.data[0]?.id ?? null;

    if (!validSelection && fallbackId !== selectedSandboxConversationId.value) {
      replaceQuery({
        conversation: fallbackId ? String(fallbackId) : undefined,
      });
    }

    if (sandboxLineId.value === null || !sandboxAvailableLines.value.some((line) => line.id === sandboxLineId.value)) {
      sandboxLineId.value = sandboxAvailableLines.value[0]?.id ?? null;
    }
  } catch (error) {
    sandboxError.value = resolveErrorMessage(error, 'sandbox.loadSessionsFailed');
  } finally {
    sandboxLoading.value = false;
  }
}

async function loadSandboxConversation(): Promise<void> {
  if (!selectedTenantId.value || !selectedSandboxConversationId.value || !canAccessSandbox.value) {
    sandboxThread.value = null;
    return;
  }

  sandboxThreadLoading.value = true;
  sandboxThreadError.value = null;

  try {
    const payload = await fetchSandboxSession(selectedTenantId.value, selectedSandboxConversationId.value);
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
  sandboxActionSuccess.value = null;

  try {
    const response = await createSandboxSession(selectedTenantId.value, {
      whatsapp_line_id: sandboxLineId.value,
    });

    sandboxThread.value = response.data;
    sandboxActionSuccess.value = t('sandbox.success.created');
    replaceQuery({
      conversation: String(response.data.conversation.id),
    });
    await loadSandboxSessionsList();
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
  sandboxActionSuccess.value = null;

  try {
    const response = await sendSandboxMessage(
      selectedTenantId.value,
      selectedSandboxConversationId.value,
      sandboxMessageBody.value.trim(),
    );

    sandboxMessageBody.value = '';
    sandboxThread.value = response.data;
    sandboxActionSuccess.value = t('sandbox.success.turnExecuted');
    await loadSandboxSessionsList();
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
  sandboxActionSuccess.value = null;

  try {
    const response = await closeSandboxSession(selectedTenantId.value, selectedSandboxConversationId.value);
    sandboxThread.value = response.data;
    sandboxActionSuccess.value = t('sandbox.success.closed');
    await loadSandboxSessionsList();
  } catch (error) {
    sandboxActionError.value = resolveErrorMessage(error, 'sandbox.closeFailed');
  } finally {
    sandboxActionLoading.value = false;
  }
}

watch(
  [selectedTenantId, () => canAccessSandbox.value],
  async () => {
    sandboxActionError.value = null;
    sandboxActionSuccess.value = null;
    await loadSandboxSessionsList();
  },
  { immediate: true },
);

watch(
  [selectedTenantId, () => canAccessSandbox.value, () => route.query.conversation],
  async () => {
    await loadSandboxConversation();
  },
  { immediate: true },
);
</script>

<template>
  <section v-if="!selectedMembership">
    <SurfaceCard>
      <EmptyState :title="t('states.noMembershipsTitle')" :description="t('sandbox.selectTenant')" />
    </SurfaceCard>
  </section>

  <section v-else-if="!canAccessSandbox">
    <SurfaceCard>
      <ForbiddenState :title="t('states.restrictedTitle')" :description="t('sandbox.noAccess')" />
    </SurfaceCard>
  </section>

  <section v-else class="panel-grid">
    <SurfaceCard>
      <div class="flex flex-wrap items-start gap-4">
        <div class="min-w-0 flex-1">
          <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-[var(--accent)]">
            {{ t('sandbox.eyebrow') }}
          </p>
          <h2 class="mt-2 text-2xl font-semibold tracking-tight">{{ t('sandbox.historyTitle') }}</h2>
          <p class="mt-3 max-w-md text-sm leading-6 text-[var(--text-muted)]">
            {{ t('sandbox.description') }}
          </p>
        </div>
        <div class="shrink-0">
          <StatusBadge :label="t('sandbox.sessionsCount', { count: sandboxSessions.length })" tone="neutral" />
        </div>
      </div>

      <form
        class="mt-6 rounded-[24px] border bg-[color:color-mix(in_srgb,var(--surface-muted)_82%,transparent)] p-4"
        :style="{ borderColor: 'var(--border)' }"
        @submit.prevent="createSandboxChat"
      >
        <div class="grid gap-4">
          <FormField :label="t('sandbox.line')">
            <select v-model.number="sandboxLineId" class="input-base" :disabled="sandboxActionLoading">
              <option v-for="line in sandboxAvailableLines" :key="line.id" :value="line.id">
                {{ line.name }}{{ line.display_phone_number ? ` · ${line.display_phone_number}` : '' }}
              </option>
            </select>
          </FormField>

          <button class="btn-primary w-full justify-center" type="submit" :disabled="!canCreateConversation">
            {{ sandboxActionLoading ? t('sandbox.creating') : t('sandbox.newSession') }}
          </button>
        </div>
        <p class="mt-3 text-sm text-[var(--text-muted)]">
          {{ t('sandbox.lineHelper') }}
        </p>
      </form>

      <div class="mt-6 grid gap-3">
        <InlineAlert v-if="sandboxError" :message="sandboxError" tone="danger" />
        <InlineAlert v-if="sandboxActionError && !selectedSandboxConversation" :message="sandboxActionError" tone="danger" />
        <InlineAlert v-if="sandboxActionSuccess && !selectedSandboxConversationId" :message="sandboxActionSuccess" tone="success" />
      </div>

      <LoadingState v-if="sandboxLoading" class="mt-6" :label="t('sandbox.loadingSessions')" />

      <div v-else class="mt-6">
        <p class="text-sm font-medium text-[var(--text)]">{{ t('sandbox.historyDescription') }}</p>

        <ul v-if="sandboxSessions.length > 0" class="mt-4 grid gap-3">
          <li v-for="conversation in sandboxSessions" :key="conversation.id">
            <button
              class="w-full rounded-[24px] border p-4 text-left transition hover:border-[color:color-mix(in_srgb,var(--accent)_30%,var(--border))]"
              :class="selectedSandboxConversationId === conversation.id ? 'bg-[var(--surface-muted)] shadow-[0_12px_30px_rgba(0,0,0,0.16)]' : 'bg-transparent'"
              :style="{ borderColor: 'var(--border)' }"
              type="button"
              @click="replaceQuery({ conversation: String(conversation.id) })"
            >
              <div class="flex items-start justify-between gap-3">
                <strong class="text-sm leading-6">{{ conversation.label }}</strong>
                <StatusBadge :label="translateSandboxStatus(conversation.status)" :status="conversation.status" />
              </div>
              <p class="mt-3 line-clamp-2 text-sm text-[var(--text-muted)]">
                {{ conversation.last_message_preview || t('sandbox.noMessagesYet') }}
              </p>
              <div class="mt-4 flex flex-wrap items-center justify-between gap-2 text-xs text-[var(--text-muted)]">
                <span>{{ conversation.whatsapp_line?.name ?? t('sandbox.noLine') }}</span>
                <span>{{ formatTimestamp(conversation.last_message_at || conversation.created_at) }}</span>
              </div>
            </button>
          </li>
        </ul>

        <EmptyState
          v-else
          class="mt-6"
          :title="t('states.emptyTitle')"
          :description="t('sandbox.emptyState')"
        />
      </div>
    </SurfaceCard>

    <SurfaceCard padding="lg">
      <template v-if="selectedSandboxConversation && sandboxThread">
        <div class="border-b pb-6" :style="{ borderColor: 'var(--border)' }">
          <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
              <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-[var(--accent)]">
                {{ t('sandbox.threadEyebrow') }}
              </p>
              <h2 class="mt-2 text-2xl font-semibold tracking-tight">{{ selectedSandboxConversation.label }}</h2>
              <p class="mt-3 max-w-2xl text-sm leading-6 text-[var(--text-muted)]">
                {{ t('sandbox.threadDescription') }}
              </p>
            </div>

            <StatusBadge
              :label="currentConversationStatusLabel"
              :status="selectedSandboxConversation.status"
              large
            />
          </div>

          <div
            v-if="activeConversationLine"
            class="mt-4 inline-flex flex-wrap items-center gap-2 rounded-full border px-3 py-2 text-xs text-[var(--text-muted)]"
            :style="{ borderColor: 'var(--border)' }"
          >
            <span class="font-semibold text-[var(--text)]">{{ t('sandbox.currentLine') }}</span>
            <span>{{ activeConversationLine.name }}</span>
            <span v-if="activeConversationLine.display_phone_number">· {{ activeConversationLine.display_phone_number }}</span>
          </div>
        </div>

        <div class="mt-6 grid gap-4 xl:grid-cols-3">
          <div class="rounded-[24px] border p-4" :style="{ borderColor: 'var(--border)' }">
            <p class="text-xs uppercase tracking-[0.2em] text-[var(--text-muted)]">{{ t('sandbox.statusTitle') }}</p>
            <strong class="mt-3 block text-base">{{ summaryStatusLabel }}</strong>
            <p class="mt-2 text-sm leading-6 text-[var(--text-muted)]">
              {{ summaryStatusDescription }}
            </p>
          </div>

          <div class="rounded-[24px] border p-4" :style="{ borderColor: 'var(--border)' }">
            <p class="text-xs uppercase tracking-[0.2em] text-[var(--text-muted)]">{{ t('sandbox.latestResponse') }}</p>
            <strong class="mt-3 block text-base">
              {{ latestAssistantMessage ? formatTimestamp(latestAssistantMessage.created_at) : t('sandbox.summary.noMessages') }}
            </strong>
            <p class="mt-2 line-clamp-3 text-sm leading-6 text-[var(--text-muted)]">
              {{ latestResponsePreview }}
            </p>
          </div>

          <div class="rounded-[24px] border p-4" :style="{ borderColor: 'var(--border)' }">
            <p class="text-xs uppercase tracking-[0.2em] text-[var(--text-muted)]">{{ t('sandbox.reviewTitle') }}</p>
            <strong class="mt-3 block text-base">{{ reviewLabel }}</strong>
            <p class="mt-2 text-sm leading-6 text-[var(--text-muted)]">
              {{ reviewDescription }}
            </p>
          </div>
        </div>

        <div class="mt-6 grid gap-3">
          <InlineAlert v-if="sandboxThreadError" :message="sandboxThreadError" tone="danger" />
          <InlineAlert v-if="sandboxActionError" :message="sandboxActionError" tone="danger" />
          <InlineAlert v-if="sandboxActionSuccess" :message="sandboxActionSuccess" tone="success" />
        </div>

        <LoadingState v-if="sandboxThreadLoading" class="mt-6" :label="t('sandbox.loadingSession')" />

        <div
          v-else
          class="mt-6 rounded-[28px] border bg-[color:color-mix(in_srgb,var(--surface-muted)_62%,transparent)] p-4 md:p-5"
          :style="{ borderColor: 'var(--border)' }"
        >
          <div class="space-y-3">
            <article
              v-for="message in sandboxThread.messages"
              :key="message.id"
              class="max-w-3xl rounded-[24px] border px-4 py-4"
              :class="
                message.direction === 'outbound'
                  ? 'ml-auto bg-[color:color-mix(in_srgb,var(--accent)_11%,transparent)]'
                  : 'bg-[var(--surface)]'
              "
              :style="{ borderColor: 'var(--border)' }"
            >
              <header class="flex items-center justify-between gap-3 text-xs uppercase tracking-[0.14em] text-[var(--text-muted)]">
                <strong class="text-[11px] font-semibold text-[var(--text)]">
                  {{ message.direction === 'outbound' ? t('sandbox.agent') : t('sandbox.tester') }}
                </strong>
                <span>{{ formatTimestamp(message.created_at) }}</span>
              </header>
              <p class="mt-3 whitespace-pre-wrap text-sm leading-6">
                {{ message.body || t('common.messageWithoutBody') }}
              </p>
              <footer class="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs text-[var(--text-muted)]">
                <span>{{ message.status || t('common.notAvailable') }}</span>
                <span v-if="message.error_message">{{ message.error_message }}</span>
              </footer>
            </article>
          </div>
        </div>

        <form
          class="mt-6 rounded-[28px] border p-5"
          :style="{ borderColor: 'var(--border)' }"
          @submit.prevent="submitSandboxMessage"
        >
          <FormField :label="t('sandbox.testMessage')">
            <textarea
              v-model="sandboxMessageBody"
              class="input-base min-h-32 resize-y"
              rows="4"
              :disabled="!canSendSandboxMessage || sandboxActionLoading"
              :placeholder="t('sandbox.testMessagePlaceholder')"
            />
          </FormField>

          <div class="mt-5 flex flex-wrap gap-3">
            <button
              class="btn-primary"
              type="submit"
              :disabled="!canSendSandboxMessage || sandboxMessageBody.trim() === '' || sandboxActionLoading"
            >
              {{ t('sandbox.executeTurn') }}
            </button>

            <button
              class="btn-secondary"
              type="button"
              :disabled="isConversationClosed || sandboxActionLoading"
              @click="closeSandboxChat"
            >
              {{ t('sandbox.closeSession') }}
            </button>
          </div>

          <p class="mt-4 text-sm leading-6 text-[var(--text-muted)]">
            {{ isConversationClosed ? t('sandbox.closedHelper') : t('sandbox.helper') }}
          </p>
        </form>

        <details
          v-if="hasTechnicalDetails"
          class="mt-6 rounded-[24px] border px-5 py-4"
          :style="{ borderColor: 'var(--border)' }"
        >
          <summary class="cursor-pointer list-none text-sm font-semibold text-[var(--text)]">
            {{ t('sandbox.technicalDetails') }}
          </summary>
          <p class="mt-3 text-sm text-[var(--text-muted)]">
            {{ t('sandbox.technicalDetailsDescription') }}
          </p>

          <div class="mt-5 grid gap-4 xl:grid-cols-3">
            <div class="rounded-[20px] border p-4" :style="{ borderColor: 'var(--border)' }">
              <p class="text-xs uppercase tracking-[0.2em] text-[var(--text-muted)]">{{ t('sandbox.latestOutcome') }}</p>
              <strong class="mt-3 block text-base">{{ translateRuntimeOutcome(sandboxLastTurn?.runtime_outcome) }}</strong>
              <p class="mt-2 text-sm leading-6 text-[var(--text-muted)]">
                {{ selectedSandboxConversation.state?.current_intent ?? t('operations.noIntent') }}
              </p>
            </div>

            <div class="rounded-[20px] border p-4" :style="{ borderColor: 'var(--border)' }">
              <p class="text-xs uppercase tracking-[0.2em] text-[var(--text-muted)]">{{ t('sandbox.latestHandoff') }}</p>
              <strong class="mt-3 block text-base">
                {{ translateHandoffStatus(selectedSandboxConversation.latest_handoff?.status) }}
              </strong>
              <p class="mt-2 text-sm leading-6 text-[var(--text-muted)]">
                {{ selectedSandboxConversation.latest_handoff?.reason || t('operations.noReason') }}
              </p>
            </div>

            <div class="rounded-[20px] border p-4" :style="{ borderColor: 'var(--border)' }">
              <p class="text-xs uppercase tracking-[0.2em] text-[var(--text-muted)]">{{ t('sandbox.visibleError') }}</p>
              <strong class="mt-3 block text-base">{{ sandboxLastTurn?.error_message || t('sandbox.noError') }}</strong>
              <p class="mt-2 text-sm leading-6 text-[var(--text-muted)]">
                {{ t('sandbox.triggerMessage', { id: sandboxLastTurn?.triggering_message_id ?? 0 }) }}
              </p>
            </div>
          </div>

          <div class="mt-4 grid gap-4 xl:grid-cols-2">
            <div class="rounded-[20px] border p-4" :style="{ borderColor: 'var(--border)' }">
              <p class="text-xs uppercase tracking-[0.2em] text-[var(--text-muted)]">{{ t('sandbox.toolCalls') }}</p>
              <div class="mt-3 grid gap-2 text-sm text-[var(--text-muted)]">
                <p v-for="toolExecution in sandboxLastTurn?.tool_executions ?? []" :key="toolExecution.id">
                  {{ toolExecution.tool_name }} · {{ translateToolStatus(toolExecution.status) }}
                  <template v-if="toolExecution.next_action"> · {{ toolExecution.next_action }} </template>
                </p>
                <p v-if="(sandboxLastTurn?.tool_executions.length ?? 0) === 0">{{ t('sandbox.noToolCalls') }}</p>
              </div>
            </div>

            <div class="rounded-[20px] border p-4" :style="{ borderColor: 'var(--border)' }">
              <p class="text-xs uppercase tracking-[0.2em] text-[var(--text-muted)]">{{ t('sandbox.turnEvents') }}</p>
              <div class="mt-3 grid gap-2 text-sm text-[var(--text-muted)]">
                <p v-for="event in sandboxLastTurn?.events ?? []" :key="event.id">
                  {{ translateEventType(event.event_type) }} · {{ formatTimestamp(event.occurred_at) }}
                </p>
                <p v-if="(sandboxLastTurn?.events.length ?? 0) === 0">{{ t('operations.noRecord') }}</p>
              </div>
            </div>
          </div>
        </details>
      </template>

      <template v-else-if="sandboxThreadLoading">
        <LoadingState :label="t('sandbox.loadingSession')" />
      </template>

      <template v-else>
        <EmptyState :title="t('states.selectTitle')" :description="t('sandbox.emptyState')" />
      </template>
    </SurfaceCard>
  </section>
</template>
