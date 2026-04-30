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
const sandboxSessionLabel = ref('');
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

function translateConversationStatus(status: string): string {
  return t(`common.conversationStatuses.${status}`);
}

function translateHandoffStatus(status: string | null | undefined): string {
  return status ? t(`common.handoffStatuses.${status}`) : t('operations.noRecord');
}

function resolveErrorMessage(error: unknown, fallbackKey: string): string {
  return error instanceof Error && error.message !== '' ? error.message : t(fallbackKey);
}

const selectedSandboxConversationId = computed(() => routeNumber('conversation'));
const selectedSandboxConversation = computed(() => sandboxThread.value?.conversation ?? null);
const sandboxLastTurn = computed<SandboxLastTurn | null>(() => sandboxThread.value?.last_turn ?? null);
const canSendSandboxMessage = computed(
  () =>
    selectedSandboxConversation.value !== null &&
    selectedSandboxConversation.value.status !== 'CLOSED' &&
    canAccessSandbox.value,
);

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
      label: sandboxSessionLabel.value.trim() || undefined,
    });

    sandboxSessionLabel.value = '';
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
      <div class="flex items-start justify-between gap-4">
        <div>
          <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-[var(--accent)]">
            {{ t('sandbox.eyebrow') }}
          </p>
          <h2 class="mt-2 text-2xl font-semibold tracking-tight">{{ selectedMembership.tenant_name }}</h2>
        </div>
        <StatusBadge :label="t('sandbox.sessionsCount', { count: sandboxSessions.length })" tone="neutral" />
      </div>

      <form class="mt-6 grid gap-4" @submit.prevent="createSandboxChat">
        <FormField :label="t('sandbox.line')">
          <select v-model.number="sandboxLineId" class="input-base" :disabled="sandboxActionLoading">
            <option v-for="line in sandboxAvailableLines" :key="line.id" :value="line.id">
              {{ line.name }}{{ line.display_phone_number ? ` · ${line.display_phone_number}` : '' }}
            </option>
          </select>
        </FormField>

        <FormField :label="t('sandbox.label')">
          <input
            v-model="sandboxSessionLabel"
            class="input-base"
            type="text"
            :disabled="sandboxActionLoading"
            :placeholder="t('sandbox.labelPlaceholder')"
          />
        </FormField>

        <button class="btn-primary w-full justify-center" type="submit" :disabled="!sandboxLineId || sandboxActionLoading">
          {{ sandboxActionLoading ? t('sandbox.creating') : t('sandbox.newSession') }}
        </button>
      </form>

      <div class="mt-6 grid gap-3">
        <InlineAlert v-if="sandboxError" :message="sandboxError" tone="danger" />
        <InlineAlert v-if="sandboxActionError" :message="sandboxActionError" tone="danger" />
        <InlineAlert v-if="sandboxActionSuccess" :message="sandboxActionSuccess" tone="success" />
      </div>

      <LoadingState v-if="sandboxLoading" class="mt-6" :label="t('sandbox.loadingSessions')" />

      <ul v-else-if="sandboxSessions.length > 0" class="mt-6 grid gap-3">
        <li v-for="conversation in sandboxSessions" :key="conversation.id">
          <button
            class="w-full rounded-[24px] border p-4 text-left transition hover:border-[color:color-mix(in_srgb,var(--accent)_30%,var(--border))]"
            :class="selectedSandboxConversationId === conversation.id ? 'bg-[var(--surface-muted)]' : 'bg-transparent'"
            :style="{ borderColor: 'var(--border)' }"
            type="button"
            @click="replaceQuery({ conversation: String(conversation.id) })"
          >
            <div class="flex items-start justify-between gap-3">
              <strong class="text-sm">{{ conversation.label }}</strong>
              <StatusBadge :label="translateConversationStatus(conversation.status)" :status="conversation.status" />
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
    </SurfaceCard>

    <SurfaceCard padding="lg">
      <template v-if="selectedSandboxConversation && sandboxThread">
        <div class="flex flex-col gap-4 border-b pb-6 lg:flex-row lg:items-start lg:justify-between" :style="{ borderColor: 'var(--border)' }">
          <div>
            <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-[var(--accent)]">
              {{ t('sandbox.threadEyebrow') }}
            </p>
            <h2 class="mt-2 text-2xl font-semibold tracking-tight">{{ selectedSandboxConversation.label }}</h2>
            <p class="mt-2 text-sm text-[var(--text-muted)]">
              {{ selectedSandboxConversation.whatsapp_line?.name ?? t('sandbox.noLine') }}
              <template v-if="selectedSandboxConversation.whatsapp_line?.display_phone_number">
                · {{ selectedSandboxConversation.whatsapp_line.display_phone_number }}
              </template>
            </p>
          </div>

          <StatusBadge
            :label="translateConversationStatus(selectedSandboxConversation.status)"
            :status="selectedSandboxConversation.status"
            large
          />
        </div>

        <div class="mt-6 grid gap-4 xl:grid-cols-3">
          <div class="rounded-[24px] border p-4" :style="{ borderColor: 'var(--border)' }">
            <p class="text-xs uppercase tracking-[0.2em] text-[var(--text-muted)]">{{ t('sandbox.latestOutcome') }}</p>
            <strong class="mt-3 block text-base">{{ sandboxLastTurn?.runtime_outcome ?? t('sandbox.noTurns') }}</strong>
            <p class="mt-2 text-sm text-[var(--text-muted)]">
              {{ selectedSandboxConversation.state?.current_intent ?? t('operations.noIntent') }}
            </p>
          </div>

          <div class="rounded-[24px] border p-4" :style="{ borderColor: 'var(--border)' }">
            <p class="text-xs uppercase tracking-[0.2em] text-[var(--text-muted)]">{{ t('sandbox.latestHandoff') }}</p>
            <strong class="mt-3 block text-base">{{ translateHandoffStatus(selectedSandboxConversation.latest_handoff?.status) }}</strong>
            <p class="mt-2 text-sm text-[var(--text-muted)]">
              {{ selectedSandboxConversation.latest_handoff?.reason || t('operations.noReason') }}
            </p>
          </div>

          <div class="rounded-[24px] border p-4" :style="{ borderColor: 'var(--border)' }">
            <p class="text-xs uppercase tracking-[0.2em] text-[var(--text-muted)]">{{ t('sandbox.latestTurnTools') }}</p>
            <strong class="mt-3 block text-base">{{ sandboxLastTurn?.tool_executions.length ?? 0 }}</strong>
            <p class="mt-2 text-sm text-[var(--text-muted)]">
              {{ sandboxLastTurn?.handoff_requested ? t('sandbox.escalated') : t('sandbox.notEscalated') }}
            </p>
          </div>
        </div>

        <div class="mt-6 grid gap-3">
          <InlineAlert v-if="sandboxThreadError" :message="sandboxThreadError" tone="danger" />
          <InlineAlert v-if="sandboxActionError" :message="sandboxActionError" tone="danger" />
          <InlineAlert v-if="sandboxActionSuccess" :message="sandboxActionSuccess" tone="success" />
        </div>

        <LoadingState v-if="sandboxThreadLoading" class="mt-6" :label="t('sandbox.loadingSession')" />

        <div v-else-if="sandboxLastTurn" class="mt-6 grid gap-4 xl:grid-cols-3">
          <div class="rounded-[24px] border p-4" :style="{ borderColor: 'var(--border)' }">
            <p class="text-xs uppercase tracking-[0.2em] text-[var(--text-muted)]">{{ t('sandbox.toolCalls') }}</p>
            <div class="mt-3 grid gap-2 text-sm text-[var(--text-muted)]">
              <p v-for="toolExecution in sandboxLastTurn.tool_executions" :key="toolExecution.id">
                {{ toolExecution.tool_name }} · {{ toolExecution.status }}
                <template v-if="toolExecution.next_action"> · {{ toolExecution.next_action }} </template>
              </p>
              <p v-if="sandboxLastTurn.tool_executions.length === 0">{{ t('sandbox.noToolCalls') }}</p>
            </div>
          </div>

          <div class="rounded-[24px] border p-4" :style="{ borderColor: 'var(--border)' }">
            <p class="text-xs uppercase tracking-[0.2em] text-[var(--text-muted)]">{{ t('sandbox.turnEvents') }}</p>
            <div class="mt-3 grid gap-2 text-sm text-[var(--text-muted)]">
              <p v-for="event in sandboxLastTurn.events" :key="event.id">
                {{ event.event_type }} · {{ formatTimestamp(event.occurred_at) }}
              </p>
            </div>
          </div>

          <div class="rounded-[24px] border p-4" :style="{ borderColor: 'var(--border)' }">
            <p class="text-xs uppercase tracking-[0.2em] text-[var(--text-muted)]">{{ t('sandbox.visibleError') }}</p>
            <strong class="mt-3 block text-base">{{ sandboxLastTurn.error_message || t('sandbox.noError') }}</strong>
            <p class="mt-2 text-sm text-[var(--text-muted)]">
              {{ t('sandbox.triggerMessage', { id: sandboxLastTurn.triggering_message_id }) }}
            </p>
          </div>
        </div>

        <div class="mt-6 space-y-3">
          <article
            v-for="message in sandboxThread.messages"
            :key="message.id"
            class="max-w-3xl rounded-[24px] border px-4 py-4"
            :class="message.direction === 'outbound' ? 'ml-auto bg-[color:color-mix(in_srgb,var(--accent)_10%,transparent)]' : 'bg-[var(--surface-muted)]'"
            :style="{ borderColor: 'var(--border)' }"
          >
            <header class="flex items-center justify-between gap-3 text-xs uppercase tracking-[0.14em] text-[var(--text-muted)]">
              <strong class="text-[11px] font-semibold text-[var(--text)]">
                {{ message.direction === 'outbound' ? t('sandbox.agent') : t('sandbox.tester') }}
              </strong>
              <span>{{ message.source || t('common.unknown') }}</span>
            </header>
            <p class="mt-3 whitespace-pre-wrap text-sm leading-6">
              {{ message.body || t('common.messageWithoutBody') }}
            </p>
            <footer class="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs text-[var(--text-muted)]">
              <span>{{ message.status || t('common.notAvailable') }}</span>
              <span>{{ formatTimestamp(message.created_at) }}</span>
            </footer>
          </article>
        </div>

        <form class="mt-8 rounded-[28px] border p-5" :style="{ borderColor: 'var(--border)' }" @submit.prevent="submitSandboxMessage">
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
              :disabled="selectedSandboxConversation.status === 'CLOSED' || sandboxActionLoading"
              @click="closeSandboxChat"
            >
              {{ t('sandbox.closeSession') }}
            </button>
          </div>

          <p class="mt-4 text-sm text-[var(--text-muted)]">
            {{ t('sandbox.helper') }}
          </p>
        </form>
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
