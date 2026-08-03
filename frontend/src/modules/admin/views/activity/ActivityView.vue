<script setup lang="ts">
import { Bot, UserCog, Wrench } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import EmptyState from '../../../../components/ui/EmptyState.vue';
import FormField from '../../../../components/ui/FormField.vue';
import InlineAlert from '../../../../components/ui/InlineAlert.vue';
import LoadingState from '../../../../components/ui/LoadingState.vue';
import StatusBadge from '../../../../components/ui/StatusBadge.vue';
import SurfaceCard from '../../../../components/ui/SurfaceCard.vue';
import TechValue from '../../../../components/ui/TechValue.vue';
import UiDrawer from '../../../../components/ui/UiDrawer.vue';
import UiSelect from '../../../../components/ui/UiSelect.vue';
import PanelHeader from '../../components/PanelHeader.vue';
import { useAdminResource } from '../../composables/useAdminResource';
import type { AgentEventLog, AuditEventLog, ToolExecutionLog } from '../../types';

// design/05 "activity": one merged timeline out of the three streams the
// overview already returns (agent/audit/tool, capped at 20 each server-side
// — design.md non-goal: no server-side pagination, this view only slices
// the fixed window it already has). Filters (type/period/line) are entirely
// client-side for the same reason. Every real `event_type`/`status` string
// found in AgentEventRecorder/AuditEventRecorder/ToolExecutionRunner call
// sites is mapped to a human sentence below (see task 4.8 memory for the
// full backend research); `fallbackSentence()` only fires for a type this
// screen has never seen before.

type TimelineKind = 'agent' | 'audit' | 'tool';

interface TimelineEntry {
  id: string;
  kind: TimelineKind;
  occurredAt: string | null;
  lineId: number | null;
  sentence: string;
  raw: AgentEventLog | AuditEventLog | ToolExecutionLog;
}

const { t, locale } = useI18n();
const { overview: adminOverview, events } = useAdminResource();
const { loading, error } = events;

// --- shared label helpers ----------------------------------------------------

// Reuses the same business-language tool names assistant/tools already
// defines (title-only, full 5-tool set) — "nombres de herramienta siempre
// en lenguaje de negocio".
const toolTitleKeys: Record<string, string> = {
  search_knowledge: 'admin.assistant.tools.toolLabels.search_knowledge.title',
  search_inventory: 'admin.assistant.tools.toolLabels.search_inventory.title',
  save_customer_data: 'admin.assistant.tools.toolLabels.save_customer_data.title',
  create_lead: 'admin.assistant.tools.toolLabels.create_lead.title',
  handoff_to_human: 'admin.assistant.tools.toolLabels.handoff_to_human.title',
};

function toolLabel(toolName: string): string {
  const key = toolTitleKeys[toolName];
  return key ? t(key) : toolName;
}

const providerTitleKeys: Record<string, string> = {
  whatsapp_meta: 'admin.connect.credentials.providers.whatsapp_meta',
};

function providerLabel(provider: string): string {
  const key = providerTitleKeys[provider];
  return key ? t(key) : provider;
}

function roleLabel(role: string | null): string {
  return t(`common.roles.${role ?? 'unknown'}`);
}

function lineName(lineId: number | null): string {
  if (lineId === null) {
    return t('admin.activity.detail.lineGeneral');
  }

  const line = (adminOverview.value?.whatsapp_lines ?? []).find((candidate) => candidate.id === lineId);

  return line ? line.name : t('common.notAvailable');
}

function memberName(userId: unknown): string {
  const id = typeof userId === 'number' ? userId : null;

  if (id === null) {
    return t('admin.activity.unknownMember');
  }

  const membership = (adminOverview.value?.tenant_users ?? []).find((candidate) => candidate.user?.id === id);

  return membership?.user?.name || membership?.user?.email || t('admin.activity.unknownMember');
}

function actorLabel(event: AuditEventLog): string {
  return event.actor_user?.name || event.actor_user?.email || t('admin.activity.systemActor');
}

function payloadString(payload: Record<string, unknown>, key: string): string | null {
  const value = payload[key];
  return typeof value === 'string' ? value : null;
}

function payloadNumber(payload: Record<string, unknown>, key: string): number | null {
  const value = payload[key];
  return typeof value === 'number' ? value : null;
}

function humanizeType(type: string): string {
  return type.replace(/_/g, ' ');
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

function prettyJson(value: unknown): string {
  try {
    return JSON.stringify(value, null, 2);
  } catch {
    return String(value);
  }
}

// --- sentence builders per stream --------------------------------------------

function agentSentence(event: AgentEventLog): string {
  const payload = event.payload ?? {};
  const tool = toolLabel(payloadString(payload, 'tool_name') ?? '');

  switch (event.event_type) {
    case 'webhook_received':
      return t('admin.activity.agent.webhookReceived');
    case 'webhook_rejected':
      return t('admin.activity.agent.webhookRejected');
    case 'message_saved':
      return t('admin.activity.agent.messageSaved');
    case 'message_deduplicated':
      return t('admin.activity.agent.messageDeduplicated');
    case 'processing_job_dispatched':
      return t('admin.activity.agent.processingJobDispatched');
    case 'processing_job_started':
      return t('admin.activity.agent.processingJobStarted');
    case 'processing_job_completed':
      return t('admin.activity.agent.processingJobCompleted');
    case 'processing_job_released_due_to_lock':
      return t('admin.activity.agent.processingJobReleased');
    case 'processing_job_failed':
      return t('admin.activity.agent.processingJobFailed');
    case 'whatsapp_status_updated':
      return t('admin.activity.agent.whatsappStatusUpdated');
    case 'whatsapp_message_send_requested':
      return t('admin.activity.agent.whatsappMessageSendRequested');
    case 'whatsapp_message_sent':
      return t('admin.activity.agent.whatsappMessageSent');
    case 'whatsapp_message_rejected':
      return t('admin.activity.agent.whatsappMessageRejected');
    case 'agent_started':
      return t('admin.activity.agent.agentStarted');
    case 'agent_response_generated':
      return t('admin.activity.agent.agentResponseGenerated');
    case 'agent_runtime_failed':
      return t('admin.activity.agent.agentRuntimeFailed');
    case 'agent_runtime_skipped_due_to_status':
      return t('admin.activity.agent.agentSkippedStatus');
    case 'agent_runtime_skipped_due_to_configuration':
      return t('admin.activity.agent.agentSkippedConfig');
    case 'handoff_triggered':
      return t('admin.activity.agent.handoffTriggered');
    case 'handoff_accepted':
      return t('admin.activity.agent.handoffAccepted');
    case 'handoff_public_message_failed':
      return t('admin.activity.agent.handoffMessageFailed');
    case 'bot_resumed':
      return t('admin.activity.agent.botResumed');
    case 'tool_called':
      return t('admin.activity.agent.toolCalled', { tool });
    case 'tool_succeeded':
      return t('admin.activity.agent.toolSucceeded', { tool });
    case 'tool_failed':
      return t('admin.activity.agent.toolFailed', { tool });
    case 'tool_data_source_resolved':
      return t('admin.activity.agent.toolDataSourceResolved', { tool });
    case 'data_source_search_completed':
      return t('admin.activity.agent.dataSourceSearchCompleted', { tool });
    case 'data_source_search_unavailable':
      return t('admin.activity.agent.dataSourceSearchUnavailable', { tool });
    case 'data_source_import_queued':
      return t('admin.activity.agent.dataSourceImportQueued');
    case 'data_source_import_started':
      return t('admin.activity.agent.dataSourceImportStarted');
    case 'data_source_import_succeeded':
      return t('admin.activity.agent.dataSourceImportSucceeded');
    case 'data_source_import_failed':
      return t('admin.activity.agent.dataSourceImportFailed');
    case 'sandbox_session_created':
      return t('admin.activity.agent.sandboxSessionCreated');
    case 'sandbox_turn_started':
      return t('admin.activity.agent.sandboxTurnStarted');
    case 'sandbox_turn_completed':
      return t('admin.activity.agent.sandboxTurnCompleted');
    case 'sandbox_session_closed':
      return t('admin.activity.agent.sandboxSessionClosed');
    case 'sandbox_message_rejected':
      return t('admin.activity.agent.sandboxMessageRejected');
    case 'sandbox_message_persisted':
      return t('admin.activity.agent.sandboxMessagePersisted');
    default:
      return t('admin.activity.fallback', { type: humanizeType(event.event_type) });
  }
}

function auditSentence(event: AuditEventLog): string {
  const actor = actorLabel(event);
  const payload = event.payload ?? {};
  const tool = toolLabel(payloadString(payload, 'tool_name') ?? '');

  switch (event.event_type) {
    case 'agent_config_updated':
      return t('admin.activity.audit.agentConfigUpdated', { actor });
    case 'agent_config_deleted':
      return t('admin.activity.audit.agentConfigDeleted', { actor });
    case 'tool_config_updated':
      return t('admin.activity.audit.toolConfigUpdated', { actor, tool });
    case 'tool_config_deleted':
      return t('admin.activity.audit.toolConfigDeleted', { actor, tool });
    case 'tenant_user_added':
      return t('admin.activity.audit.tenantUserAdded', { actor, member: memberName(payload.user_id) });
    case 'tenant_user_role_updated':
      return t('admin.activity.audit.tenantUserRoleUpdated', {
        actor,
        member: memberName(payload.user_id),
        role: roleLabel(payloadString(payload, 'role')),
      });
    case 'tenant_user_removed':
      return t('admin.activity.audit.tenantUserRemoved', { actor, member: memberName(payload.user_id) });
    case 'tenant_created':
      return t('admin.activity.audit.tenantCreated', { actor });
    case 'tenant_updated':
      return t('admin.activity.audit.tenantUpdated', { actor });
    case 'tenant_deleted':
      return t('admin.activity.audit.tenantDeleted', { actor });
    case 'whatsapp_line_created':
      return t('admin.activity.audit.lineCreated', { actor });
    case 'whatsapp_line_updated':
      return t('admin.activity.audit.lineUpdated', { actor });
    case 'whatsapp_line_deleted':
      return t('admin.activity.audit.lineDeleted', { actor });
    case 'credential_upserted':
      return t('admin.activity.audit.credentialUpserted', {
        actor,
        provider: providerLabel(payloadString(payload, 'provider') ?? ''),
      });
    case 'data_source_uploaded':
      return t('admin.activity.audit.dataSourceUploaded', { actor });
    case 'data_source_import_retried':
      return t('admin.activity.audit.dataSourceImportRetried', { actor });
    case 'handoff_triggered':
      return t('admin.activity.audit.handoffTriggered', { actor });
    case 'handoff_accepted':
      return t('admin.activity.audit.handoffAccepted', { actor });
    case 'handoff_reassigned':
      return t('admin.activity.audit.handoffReassigned', { actor });
    case 'manual_reply_sent':
      return t('admin.activity.audit.manualReplySent', { actor });
    case 'bot_resumed':
      return t('admin.activity.audit.botResumed', { actor });
    default:
      return t('admin.activity.fallback', { type: humanizeType(event.event_type) });
  }
}

function toolExecutionSentence(execution: ToolExecutionLog): string {
  const tool = toolLabel(execution.tool_name);

  switch (execution.status) {
    case 'started':
      return t('admin.activity.tool.started', { tool });
    case 'succeeded':
      return t('admin.activity.tool.succeeded', { tool });
    case 'timed_out':
      return t('admin.activity.tool.timedOut', { tool });
    case 'not_enabled':
      return t('admin.activity.tool.notEnabled', { tool });
    case 'unknown_tool':
      return t('admin.activity.tool.unknownTool', { tool });
    case 'not_implemented':
      return t('admin.activity.tool.notImplemented', { tool });
    case 'failed':
      return t('admin.activity.tool.failed', { tool });
    default:
      return t('admin.activity.fallback', { type: humanizeType(execution.status) });
  }
}

function toolExecutionTone(status: string): 'success' | 'warning' | 'danger' {
  if (status === 'succeeded') {
    return 'success';
  }

  if (status === 'started') {
    return 'warning';
  }

  return 'danger';
}

function kindIcon(kind: TimelineKind) {
  if (kind === 'audit') {
    return UserCog;
  }

  if (kind === 'tool') {
    return Wrench;
  }

  return Bot;
}

// --- unified timeline ---------------------------------------------------------

function timestampOf(occurredAt: string | null): number {
  return occurredAt ? new Date(occurredAt).getTime() : 0;
}

const rawEntries = computed<TimelineEntry[]>(() => {
  const overview = adminOverview.value;

  if (!overview) {
    return [];
  }

  const agentEntries: TimelineEntry[] = overview.logs.agent_events.map((event) => ({
    id: `agent-${event.id}`,
    kind: 'agent',
    occurredAt: event.occurred_at,
    lineId: event.whatsapp_line_id,
    sentence: agentSentence(event),
    raw: event,
  }));

  const auditEntries: TimelineEntry[] = overview.logs.audit_events.map((event) => ({
    id: `audit-${event.id}`,
    kind: 'audit',
    occurredAt: event.occurred_at,
    lineId: payloadNumber(event.payload ?? {}, 'whatsapp_line_id'),
    sentence: auditSentence(event),
    raw: event,
  }));

  // Tool executions carry no whatsapp_line_id anywhere in their serialized
  // shape (only a conversation_id, and conversations aren't part of the
  // admin overview) — a line filter can never match these, by design, not
  // by omission.
  const toolEntries: TimelineEntry[] = overview.logs.tool_executions.map((execution) => ({
    id: `tool-${execution.id}`,
    kind: 'tool',
    occurredAt: execution.executed_at ?? execution.created_at,
    lineId: null,
    sentence: toolExecutionSentence(execution),
    raw: execution,
  }));

  return [...agentEntries, ...auditEntries, ...toolEntries].sort(
    (a, b) => timestampOf(b.occurredAt) - timestampOf(a.occurredAt),
  );
});

// --- filters (client-side only — design.md non-goal: no server-side
// pagination, the API already returns a fixed 20-per-stream window) --------

const typeFilter = ref<string>('all');
const periodFilter = ref<string>('all');
const lineFilter = ref<string>('all');

const lineOptions = computed(() => adminOverview.value?.whatsapp_lines ?? []);

const filteredEntries = computed<TimelineEntry[]>(() => {
  const now = Date.now();
  const periodMs = periodFilter.value === '24h' ? 24 * 60 * 60 * 1000 : periodFilter.value === '7d' ? 7 * 24 * 60 * 60 * 1000 : null;
  const lineIdFilter = lineFilter.value === 'all' ? null : Number(lineFilter.value);

  return rawEntries.value.filter((entry) => {
    if (typeFilter.value !== 'all' && entry.kind !== typeFilter.value) {
      return false;
    }

    if (lineIdFilter !== null && entry.lineId !== lineIdFilter) {
      return false;
    }

    if (periodMs !== null) {
      const ts = timestampOf(entry.occurredAt);

      if (ts === 0 || now - ts > periodMs) {
        return false;
      }
    }

    return true;
  });
});

// --- detail drawer -------------------------------------------------------------

const selectedEntryId = ref<string | null>(null);
const selectedEntry = computed<TimelineEntry | null>(
  () => rawEntries.value.find((entry) => entry.id === selectedEntryId.value) ?? null,
);
const selectedAgentEvent = computed<AgentEventLog | null>(() =>
  selectedEntry.value?.kind === 'agent' ? (selectedEntry.value.raw as AgentEventLog) : null,
);
const selectedAuditEvent = computed<AuditEventLog | null>(() =>
  selectedEntry.value?.kind === 'audit' ? (selectedEntry.value.raw as AuditEventLog) : null,
);
const selectedToolExecution = computed<ToolExecutionLog | null>(() =>
  selectedEntry.value?.kind === 'tool' ? (selectedEntry.value.raw as ToolExecutionLog) : null,
);
const detailOpen = computed(() => selectedEntry.value !== null);

function openDetail(id: string): void {
  selectedEntryId.value = id;
}

function closeDetail(): void {
  selectedEntryId.value = null;
}

function onDetailOpenChange(value: boolean): void {
  if (!value) {
    closeDetail();
  }
}
</script>

<template>
  <div class="space-y-5">
    <PanelHeader panel="admin.activity.title" description="admin.activity.description" />

    <div v-if="loading && !adminOverview">
      <SurfaceCard>
        <LoadingState :label="t('admin.loading')" />
      </SurfaceCard>
    </div>

    <template v-else-if="adminOverview">
      <InlineAlert v-if="error" :message="error" tone="danger" />

      <EmptyState
        v-if="rawEntries.length === 0"
        :title="t('admin.activity.empty.title')"
        :description="t('admin.activity.empty.description')"
      />

      <template v-else>
        <SurfaceCard data-settings-key="activity.panel" padding="sm">
          <div class="activity-filters">
            <FormField :label="t('admin.activity.filters.typeLabel')">
              <UiSelect v-model="typeFilter">
                <option value="all">{{ t('admin.activity.filters.typeAll') }}</option>
                <option value="agent">{{ t('admin.activity.filters.typeAgent') }}</option>
                <option value="audit">{{ t('admin.activity.filters.typeAudit') }}</option>
                <option value="tool">{{ t('admin.activity.filters.typeTool') }}</option>
              </UiSelect>
            </FormField>
            <FormField :label="t('admin.activity.filters.periodLabel')">
              <UiSelect v-model="periodFilter">
                <option value="all">{{ t('admin.activity.filters.periodAll') }}</option>
                <option value="24h">{{ t('admin.activity.filters.period24h') }}</option>
                <option value="7d">{{ t('admin.activity.filters.period7d') }}</option>
              </UiSelect>
            </FormField>
            <FormField :label="t('admin.activity.filters.lineLabel')">
              <UiSelect v-model="lineFilter">
                <option value="all">{{ t('admin.activity.filters.lineAll') }}</option>
                <option v-for="line in lineOptions" :key="line.id" :value="String(line.id)">
                  {{ line.name }}
                </option>
              </UiSelect>
            </FormField>
          </div>
        </SurfaceCard>

        <InlineAlert v-if="filteredEntries.length === 0" :message="t('admin.activity.noMatches')" tone="info" />

        <SurfaceCard v-else padding="sm">
          <ul class="timeline-list">
            <li v-for="entry in filteredEntries" :key="entry.id">
              <button type="button" class="timeline-row" @click="openDetail(entry.id)">
                <span class="timeline-icon" :class="`timeline-icon--${entry.kind}`" aria-hidden="true">
                  <component :is="kindIcon(entry.kind)" class="h-4 w-4" :stroke-width="1.75" />
                </span>
                <span class="timeline-body">
                  <span class="text-body timeline-sentence">{{ entry.sentence }}</span>
                  <span class="text-small timeline-meta">
                    {{ formatTimestamp(entry.occurredAt) }} · {{ t(`admin.activity.kinds.${entry.kind}`) }}
                    <template v-if="entry.lineId !== null"> · {{ lineName(entry.lineId) }}</template>
                  </span>
                </span>
              </button>
            </li>
          </ul>
        </SurfaceCard>
      </template>
    </template>

    <!-- Detail drawer -->
    <UiDrawer
      :open="detailOpen"
      :title="t('admin.activity.detail.title')"
      :close-label="t('common.close')"
      @update:open="onDetailOpenChange"
    >
      <template v-if="selectedEntry">
        <p class="text-body">{{ selectedEntry.sentence }}</p>

        <section class="grid gap-3">
          <FormField :label="t('admin.activity.detail.kindLabel')">
            <p class="text-body">{{ t(`admin.activity.kinds.${selectedEntry.kind}`) }}</p>
          </FormField>
          <FormField :label="t('admin.activity.detail.whenLabel')">
            <p class="text-body">{{ formatTimestamp(selectedEntry.occurredAt) }}</p>
          </FormField>
          <FormField :label="t('admin.activity.detail.lineLabel')">
            <p class="text-body">{{ lineName(selectedEntry.lineId) }}</p>
          </FormField>

          <template v-if="selectedAuditEvent">
            <FormField :label="t('admin.activity.detail.actorLabel')">
              <p class="text-body">{{ actorLabel(selectedAuditEvent) }}</p>
            </FormField>
            <FormField v-if="selectedAuditEvent.target_type" :label="t('admin.activity.detail.referenceLabel')">
              <TechValue :value="`${selectedAuditEvent.target_type}#${selectedAuditEvent.target_id ?? ''}`" />
            </FormField>
          </template>

          <template v-if="selectedToolExecution">
            <FormField :label="t('admin.activity.detail.toolLabel')">
              <p class="text-body">{{ toolLabel(selectedToolExecution.tool_name) }}</p>
            </FormField>
            <FormField :label="t('admin.activity.detail.statusLabel')">
              <StatusBadge :label="selectedToolExecution.status" :tone="toolExecutionTone(selectedToolExecution.status)" />
            </FormField>
            <FormField :label="t('admin.activity.detail.durationLabel')">
              <p class="text-body">
                {{ selectedToolExecution.duration_ms !== null ? `${selectedToolExecution.duration_ms} ms` : t('common.notAvailable') }}
              </p>
            </FormField>
            <FormField v-if="selectedToolExecution.error_message" :label="t('admin.activity.detail.errorLabel')">
              <TechValue :value="selectedToolExecution.error_message" />
            </FormField>
          </template>

          <FormField v-if="selectedAgentEvent" :label="t('admin.activity.detail.referenceLabel')">
            <TechValue :value="selectedAgentEvent.event_type" />
          </FormField>
        </section>

        <section class="grid gap-2">
          <h4 class="text-h3">{{ t('admin.activity.detail.payloadTitle') }}</h4>
          <p class="text-small timeline-meta">{{ t('admin.activity.detail.payloadHint') }}</p>
          <pre class="text-mono activity-payload">{{
            prettyJson(
              selectedAgentEvent?.payload ??
              selectedAuditEvent?.payload ??
              (selectedToolExecution
                ? { input_summary: selectedToolExecution.input_summary, output_summary: selectedToolExecution.output_summary, metadata: selectedToolExecution.metadata }
                : {}),
            )
          }}</pre>
        </section>
      </template>
    </UiDrawer>
  </div>
</template>

<style scoped>
.activity-filters {
  display: grid;
  gap: var(--space-3);
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
}

.timeline-list {
  display: grid;
}

.timeline-list li {
  border-bottom: 1px solid var(--border);
}

.timeline-list li:last-child {
  border-bottom: none;
}

.timeline-row {
  display: flex;
  width: 100%;
  align-items: flex-start;
  gap: var(--space-3);
  padding: 12px 8px;
  text-align: left;
}

.timeline-row:hover {
  background: var(--muted);
}

.timeline-icon {
  display: flex;
  height: 28px;
  width: 28px;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  border-radius: var(--radius-md);
  background: var(--muted);
  color: var(--text-mute);
}

.timeline-icon--agent {
  color: var(--accent);
}

.timeline-icon--audit {
  color: var(--text-soft);
}

.timeline-icon--tool {
  color: var(--info);
}

.timeline-body {
  display: grid;
  gap: 2px;
  min-width: 0;
}

.timeline-sentence {
  color: var(--text);
}

.timeline-meta {
  color: var(--text-mute);
}

.activity-payload {
  max-height: 320px;
  overflow: auto;
  white-space: pre-wrap;
  word-break: break-word;
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  background: var(--muted);
  padding: 12px;
  color: var(--text-soft);
}
</style>
