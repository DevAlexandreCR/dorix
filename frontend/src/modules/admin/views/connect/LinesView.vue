<script setup lang="ts">
import { Plus } from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import DangerZone from '../../../../components/ui/DangerZone.vue';
import DataTable from '../../../../components/ui/DataTable.vue';
import FormField from '../../../../components/ui/FormField.vue';
import InheritanceChip from '../../../../components/ui/InheritanceChip.vue';
import InlineAlert from '../../../../components/ui/InlineAlert.vue';
import LiveDot from '../../../../components/ui/LiveDot.vue';
import LoadingState from '../../../../components/ui/LoadingState.vue';
import StatusBadge from '../../../../components/ui/StatusBadge.vue';
import SurfaceCard from '../../../../components/ui/SurfaceCard.vue';
import TechValue from '../../../../components/ui/TechValue.vue';
import UiButton from '../../../../components/ui/UiButton.vue';
import UiDrawer from '../../../../components/ui/UiDrawer.vue';
import UiInput from '../../../../components/ui/UiInput.vue';
import UiModal from '../../../../components/ui/UiModal.vue';
import UiSwitch from '../../../../components/ui/UiSwitch.vue';
import { useNavigationAccess } from '../../../../composables/useNavigationAccess';
import { useTenantSelection } from '../../../../composables/useTenantSelection';
import { useToast } from '../../../../composables/useToast';
import PanelHeader from '../../components/PanelHeader.vue';
import { useMetaEmbeddedSignup } from '../../composables/useMetaEmbeddedSignup';
import { useAdminResource } from '../../composables/useAdminResource';
import type { AdminOverview, CalendarConnectionStatus, WhatsAppConnectionMode, WhatsAppLineRecord } from '../../types';

// design.md decision 6 (all-or-nothing line scope): whether a line "has its
// own assistant" is a whole-row fact (an AgentConfigRecord with
// scope_type==='whatsapp_line' either exists for this line or it doesn't).
// This screen never claims per-field customization — see
// feedback_line_scope_all_or_nothing memory. The connection status
// (LiveDot/DangerZone) is a completely separate concept driven by
// `is_enabled`: `status` is a free-text, unconstrained column on the
// backend (no enum, never read anywhere else in the codebase) and is
// deliberately never surfaced for editing here, only passed through
// unchanged on updates and defaulted on create, to comply with the
// "estados nunca como texto libre" rule without inventing an enum the
// backend doesn't actually enforce.

type ConnectForm = {
  name: string;
  phoneNumberId: string;
  displayPhoneNumber: string;
  wabaId: string;
};

type DetailForm = {
  name: string;
};

const { t } = useI18n();
const router = useRouter();
const route = useRoute();
const toast = useToast();
const { selectedMembership } = useTenantSelection();
const { canManageTenant, canManageAgentConfig } = useNavigationAccess(selectedMembership);

const { overview: adminOverview, overviewLoading: loading, lines, reloadOverview } = useAdminResource();
const { loading: saving, error, success } = lines;

// --- table helpers ---------------------------------------------------------

function lineDisplayNumber(line: WhatsAppLineRecord): string {
  return line.display_phone_number || line.phone_number_id;
}

function lineStatusLabel(line: WhatsAppLineRecord): string {
  return line.is_enabled ? t('admin.connect.lines.statusActive') : t('admin.connect.lines.statusPaused');
}

// `connection_mode` (add-meta-embedded-signup design.md D3) is a read-only
// fact set at connect time, never editable here — surfaced as a badge in
// both the table and the drawer, sharing the same short label as the mode
// picker in the Embedded Signup modal below (no separate "badge copy" to
// keep in sync).
function connectionModeLabel(mode: WhatsAppConnectionMode): string {
  return mode === 'coexistence'
    ? t('admin.connect.lines.embeddedSignup.modeCoexistenceLabel')
    : t('admin.connect.lines.embeddedSignup.modeCloudApiLabel');
}

function lineHasOwnAssistant(overview: AdminOverview, lineId: number): boolean {
  return overview.agent_configs.some(
    (config) => config.scope_type === 'whatsapp_line' && config.whatsapp_line_id === lineId,
  );
}

// Google Calendar connection badge (add-catalog-and-scheduling design.md
// D11/D12) — `calendar_connection_status` has no `broken`-only styling
// concept elsewhere in the codebase, so this mirrors DataView's
// `sourceStatusTone` (StatusBadge + a danger InlineAlert only for the
// failing state).
function calendarStatusLabel(status: CalendarConnectionStatus): string {
  return t(`admin.connect.lines.calendarStatus.${status}`);
}

function calendarStatusTone(status: CalendarConnectionStatus): 'danger' | 'neutral' | 'success' {
  switch (status) {
    case 'connected':
      return 'success';
    case 'broken':
      return 'danger';
    default:
      return 'neutral';
  }
}

// --- Embedded Signup (primary connect flow) ---------------------------------
//
// design.md D7 / spec `ui-admin` ("Estados del flujo de conexión con Meta"):
// eligiendo modo -> popup de Meta abierto -> conectando -> éxito/error/
// cancelado. The mode picker is a small modal; once the user confirms it
// closes and `connecting` alone drives the primary button's loading/disabled
// state for the rest of the flow (popup open + backend exchange), so the
// flow truly cannot be relaunched until it resolves either way.
const { launch: launchEmbeddedSignup } = useMetaEmbeddedSignup();

const modeSelectOpen = ref(false);
const selectedMode = ref<WhatsAppConnectionMode>('cloud_api');
const connecting = ref(false);

function openModeSelect(): void {
  selectedMode.value = 'cloud_api';
  modeSelectOpen.value = true;
}

function closeModeSelect(): void {
  modeSelectOpen.value = false;
}

function onModeSelectOpenChange(value: boolean): void {
  if (!value) {
    closeModeSelect();
  }
}

async function confirmModeAndConnect(): Promise<void> {
  const mode = selectedMode.value;
  closeModeSelect();
  connecting.value = true;

  try {
    const outcome = await launchEmbeddedSignup(mode);

    if (outcome.status === 'cancelled') {
      // Spec "Popup cancelado": return to the initial state, no error toast.
      return;
    }

    const { result } = outcome;

    if (!result.phoneNumberId) {
      // `FINISH_ONLY_WABA` (cloud_api): a WABA was authorized but no phone
      // number was provisioned in the popup — nothing to send to `connect`.
      toast.error(t('admin.connect.lines.embeddedSignup.missingPhoneNumberError'));
      return;
    }

    await lines.connect(
      {
        code: result.code,
        phone_number_id: result.phoneNumberId,
        waba_id: result.wabaId,
        connection_mode: result.connectionMode,
      },
      { successMessage: t('admin.success.lineConnected') },
    );
  } catch {
    // Genuine client-side failure from `launchEmbeddedSignup` itself (SDK
    // failed to load, malformed WA_EMBEDDED_SIGNUP payload, flow timed out)
    // — not a Graph/backend error (those are already actionable via
    // `lines.connect`'s own translated ApiError message above).
    toast.error(t('admin.connect.lines.embeddedSignup.error'));
  } finally {
    connecting.value = false;
  }
}

// --- manual connect drawer (secondary fallback) -----------------------------

const connectOpen = ref(false);
const connectForm = ref<ConnectForm>(defaultConnectForm());

function defaultConnectForm(): ConnectForm {
  return { name: '', phoneNumberId: '', displayPhoneNumber: '', wabaId: '' };
}

const canSubmitConnect = computed(
  () => connectForm.value.name.trim() !== '' && connectForm.value.phoneNumberId.trim() !== '',
);

function openConnectDrawer(): void {
  connectForm.value = defaultConnectForm();
  connectOpen.value = true;
}

function closeConnectDrawer(): void {
  connectOpen.value = false;
}

function onConnectOpenChange(value: boolean): void {
  if (!value) {
    closeConnectDrawer();
  }
}

async function submitConnect(): Promise<void> {
  const created = await lines.create(
    {
      name: connectForm.value.name.trim(),
      phone_number_id: connectForm.value.phoneNumberId.trim(),
      display_phone_number: connectForm.value.displayPhoneNumber.trim() || undefined,
      waba_id: connectForm.value.wabaId.trim() || undefined,
      status: 'active',
      is_enabled: true,
    },
    { successMessage: t('admin.success.lineConnected') },
  );

  if (created) {
    closeConnectDrawer();
  }
}

// --- detail drawer -----------------------------------------------------------

const detailLineId = ref<number | null>(null);
const detailOpen = computed(() => detailLineId.value !== null);
const detailForm = reactive<DetailForm>({ name: '' });

const detailLine = computed<WhatsAppLineRecord | null>(() => {
  if (detailLineId.value === null) {
    return null;
  }

  return (adminOverview.value?.whatsapp_lines ?? []).find((line) => line.id === detailLineId.value) ?? null;
});

const detailLineHasOwnAssistant = computed<boolean>(() => {
  const overview = adminOverview.value;
  const line = detailLine.value;

  if (!overview || !line) {
    return false;
  }

  return lineHasOwnAssistant(overview, line.id);
});

function openDetail(lineId: number): void {
  const line = (adminOverview.value?.whatsapp_lines ?? []).find((candidate) => candidate.id === lineId);

  if (!line) {
    return;
  }

  detailForm.name = line.name;
  detailLineId.value = lineId;
}

function closeDetail(): void {
  detailLineId.value = null;
}

function onDetailOpenChange(value: boolean): void {
  if (!value) {
    closeDetail();
  }
}

// Every field the drawer doesn't expose for editing (phone_number_id,
// display_phone_number, waba_id, status) travels through unchanged from the
// current record — the backend's PATCH accepts partial payloads, but the
// resource's typed `update()` (task 3.1) always takes the full shape, same
// convention every other migrated view (Behavior, Tools) already follows.
async function saveDetail(): Promise<void> {
  const line = detailLine.value;

  if (!line) {
    return;
  }

  await lines.update(
    line.id,
    {
      name: detailForm.name.trim(),
      phone_number_id: line.phone_number_id,
      display_phone_number: line.display_phone_number ?? undefined,
      waba_id: line.waba_id ?? undefined,
      status: line.status,
      is_enabled: line.is_enabled,
    },
    { successMessage: t('admin.success.lineUpdated') },
  );
}

async function toggleLineEnabled(nextValue: boolean): Promise<void> {
  const line = detailLine.value;

  if (!line) {
    return;
  }

  await lines.update(
    line.id,
    {
      name: line.name,
      phone_number_id: line.phone_number_id,
      display_phone_number: line.display_phone_number ?? undefined,
      waba_id: line.waba_id ?? undefined,
      status: line.status,
      is_enabled: nextValue,
    },
    { successMessage: nextValue ? t('admin.success.lineEnabled') : t('admin.success.lineDisabled') },
  );
}

function goToLineAssistant(): void {
  const line = detailLine.value;

  if (!line) {
    return;
  }

  router.push({ path: '/admin/assistant/behavior', query: { line: String(line.id) } });
}

// --- Google Calendar connection ------------------------------------------

const connectingCalendar = ref(false);

function calendarConnectActionLabel(status: CalendarConnectionStatus): string {
  return status === 'none'
    ? t('admin.connect.lines.detail.calendar.connectAction')
    : t('admin.connect.lines.detail.calendar.reconnectAction');
}

// design.md D11: `LinesView` itself is gated by `canManageTenant`, but the
// consent-URL endpoint is gated by `Permission::ManageAgentConfig`. Today
// only `tenant_admin` has both, but this button carries its own
// `canManageAgentConfig` check so it degrades safely if the role matrix
// ever separates the two.
async function connectCalendar(): Promise<void> {
  const line = detailLine.value;

  if (!line || !canManageAgentConfig.value) {
    return;
  }

  connectingCalendar.value = true;
  const consentUrl = await lines.requestCalendarConnection(line.id);
  connectingCalendar.value = false;

  if (consentUrl) {
    window.location.href = consentUrl;
  }
}

// Handles the browser landing back on this view after Google's OAuth
// consent screen: the public callback redirects to
// `/admin/connect/lines?calendar_connection=success|error` (no line id in
// the query — the table/badges already re-render for whichever line just
// finished the flow once the overview reloads). Mirrors
// `useSettingsHighlight`'s destructure-and-replace approach to strip the
// query param afterwards.
function clearCalendarConnectionQuery(): void {
  const { calendar_connection: _removed, ...rest } = route.query;
  void router.replace({ path: route.path, query: rest });
}

watch(
  () => route.query.calendar_connection,
  (value) => {
    if (value === 'success') {
      toast.success(t('admin.connect.lines.calendarConnectionSuccess'));
      void reloadOverview();
      clearCalendarConnectionQuery();
    } else if (value === 'error') {
      toast.error(t('admin.connect.lines.calendarConnectionError'));
      clearCalendarConnectionQuery();
    }
  },
  { immediate: true },
);

// --- delete confirmation -------------------------------------------------------

const deleteConfirmOpen = ref(false);

function requestDelete(): void {
  deleteConfirmOpen.value = true;
}

function cancelDelete(): void {
  deleteConfirmOpen.value = false;
}

async function confirmDelete(): Promise<void> {
  const line = detailLine.value;

  if (!line) {
    deleteConfirmOpen.value = false;
    return;
  }

  const removed = await lines.remove(line.id, { successMessage: t('admin.success.lineDeleted') });

  deleteConfirmOpen.value = false;

  if (removed) {
    closeDetail();
  }
}

// Keep the detail drawer's draft in sync whenever the lines collection is
// patched from a mutation's own response (task 3.1) — e.g. the switch
// toggling `is_enabled` should not clobber an in-progress name edit made
// through a different tab/drawer re-open.
watch(
  () => lines.data.value,
  () => {
    const line = detailLine.value;

    if (line) {
      detailForm.name = line.name;
    }
  },
);
</script>

<template>
  <div class="space-y-5">
    <PanelHeader
      group="admin.nav.connect"
      panel="admin.connect.lines.title"
      description="admin.connect.lines.description"
    >
      <template #actions>
        <UiButton
          variant="secondary"
          :disabled="!canManageTenant || connecting"
          @click="openConnectDrawer"
        >
          {{ t('admin.connect.lines.manualConnectAction') }}
        </UiButton>
        <UiButton
          variant="primary"
          :loading="connecting"
          :disabled="!canManageTenant"
          @click="openModeSelect"
        >
          <template #icon>
            <Plus class="h-4 w-4" :stroke-width="2" aria-hidden="true" />
          </template>
          {{ t('admin.connect.lines.connectAction') }}
        </UiButton>
      </template>
    </PanelHeader>

    <div v-if="loading && !adminOverview">
      <SurfaceCard>
        <LoadingState :label="t('admin.loading')" />
      </SurfaceCard>
    </div>

    <template v-else-if="adminOverview">
      <div class="grid gap-3">
        <InlineAlert v-if="error" :message="error" tone="danger" />
        <InlineAlert v-if="success" :message="success" tone="success" />
      </div>

      <DataTable
        data-settings-key="connect.lines.panel"
        :columns="[
          { key: 'line', label: t('admin.connect.lines.columns.line') },
          { key: 'number', label: t('admin.connect.lines.columns.number') },
          { key: 'status', label: t('admin.connect.lines.columns.status') },
          { key: 'mode', label: t('admin.connect.lines.columns.mode') },
          { key: 'assistant', label: t('admin.connect.lines.columns.assistant') },
          { key: 'calendar', label: t('admin.connect.lines.columns.calendar') },
        ]"
      >
        <template #body>
          <tr v-if="adminOverview.whatsapp_lines.length === 0">
            <td colspan="6" class="data-table-empty">{{ t('admin.connect.lines.empty') }}</td>
          </tr>
          <tr
            v-for="line in adminOverview.whatsapp_lines"
            :key="line.id"
            class="line-row"
            @click="openDetail(line.id)"
          >
            <td>
              <button type="button" class="line-name-btn" @click.stop="openDetail(line.id)">
                {{ line.name }}
              </button>
            </td>
            <td class="text-mono">{{ lineDisplayNumber(line) }}</td>
            <td>
              <LiveDot :label="lineStatusLabel(line)" :live="line.is_enabled" />
            </td>
            <td>
              <StatusBadge :label="connectionModeLabel(line.connection_mode)" tone="neutral" />
            </td>
            <td>
              <InheritanceChip
                :customized="lineHasOwnAssistant(adminOverview, line.id)"
                :inherited-label="t('admin.connect.lines.assistantInherited')"
                :customized-label="t('admin.connect.lines.assistantCustomized')"
              />
            </td>
            <td>
              <StatusBadge
                :label="calendarStatusLabel(line.calendar_connection_status)"
                :tone="calendarStatusTone(line.calendar_connection_status)"
              />
            </td>
          </tr>
        </template>
      </DataTable>
    </template>

    <!-- Detail drawer -->
    <UiDrawer
      :open="detailOpen"
      :title="detailLine?.name ?? ''"
      :close-label="t('common.close')"
      @update:open="onDetailOpenChange"
    >
      <template v-if="detailLine">
        <div class="line-detail-summary">
          <LiveDot :label="lineStatusLabel(detailLine)" :live="detailLine.is_enabled" />
          <span class="text-mono text-small" style="color: var(--text-mute)">{{ lineDisplayNumber(detailLine) }}</span>
        </div>

        <section class="grid gap-3">
          <h4 class="text-h3">{{ t('admin.connect.lines.detail.generalTitle') }}</h4>
          <FormField :label="t('admin.connect.lines.detail.nameLabel')" :hint="t('admin.connect.lines.detail.nameHint')">
            <UiInput v-model="detailForm.name" type="text" :disabled="!canManageTenant || saving" />
          </FormField>
          <UiButton
            class="justify-self-start"
            variant="secondary"
            size="sm"
            :loading="saving"
            :disabled="!canManageTenant"
            @click="saveDetail"
          >
            {{ t('admin.connect.lines.detail.save') }}
          </UiButton>
        </section>

        <details class="meta-data-details">
          <summary class="cursor-pointer text-small font-semibold">{{ t('admin.connect.lines.detail.metaDataTitle') }}</summary>
          <div class="mt-3 grid gap-3">
            <p class="text-small" style="color: var(--text-mute)">{{ t('admin.connect.lines.detail.metaDataHelp') }}</p>
            <FormField :label="t('admin.connect.lines.detail.phoneNumberIdLabel')">
              <TechValue :value="detailLine.phone_number_id" />
            </FormField>
            <FormField :label="t('admin.connect.lines.detail.wabaIdLabel')">
              <TechValue :value="detailLine.waba_id ?? t('common.notAvailable')" />
            </FormField>
            <FormField :label="t('admin.connect.lines.detail.connectionModeLabel')">
              <StatusBadge :label="connectionModeLabel(detailLine.connection_mode)" tone="neutral" />
            </FormField>
          </div>
        </details>

        <section class="grid gap-3">
          <h4 class="text-h3">{{ t('admin.connect.lines.detail.assistantTitle') }}</h4>
          <InlineAlert
            :tone="detailLineHasOwnAssistant ? 'info' : 'success'"
            :message="
              detailLineHasOwnAssistant
                ? t('admin.shared.lineHasOwnConfigMessage')
                : t('admin.shared.lineUsesGeneralConfigMessage')
            "
          />
          <UiButton class="justify-self-start" variant="secondary" size="sm" @click="goToLineAssistant">
            {{ t('admin.connect.lines.detail.personalizeAction') }}
          </UiButton>
        </section>

        <section class="grid gap-3">
          <h4 class="text-h3">{{ t('admin.connect.lines.detail.calendar.title') }}</h4>
          <StatusBadge
            class="justify-self-start"
            :label="calendarStatusLabel(detailLine.calendar_connection_status)"
            :tone="calendarStatusTone(detailLine.calendar_connection_status)"
          />
          <InlineAlert
            v-if="detailLine.calendar_connection_status === 'broken'"
            tone="danger"
            :message="t('admin.connect.lines.detail.calendar.brokenAlert')"
          />
          <p v-else class="text-small" style="color: var(--text-mute)">
            {{
              detailLine.calendar_connection_status === 'connected'
                ? t('admin.connect.lines.detail.calendar.connectedHint')
                : t('admin.connect.lines.detail.calendar.noneHint')
            }}
          </p>
          <UiButton
            class="justify-self-start"
            variant="secondary"
            size="sm"
            :loading="connectingCalendar"
            :disabled="!canManageAgentConfig"
            @click="connectCalendar"
          >
            {{ calendarConnectActionLabel(detailLine.calendar_connection_status) }}
          </UiButton>
        </section>

        <DangerZone :description="t('admin.connect.lines.dangerZone.description', { number: lineDisplayNumber(detailLine) })">
          <UiSwitch
            :model-value="detailLine.is_enabled"
            :label="t('admin.connect.lines.dangerZone.activeSwitchLabel')"
            :disabled="!canManageTenant || saving"
            @update:model-value="toggleLineEnabled"
          />
          <p class="text-small danger-zone-hint">{{ t('admin.connect.lines.dangerZone.activeSwitchHelp') }}</p>
          <UiButton variant="danger" size="sm" :disabled="!canManageTenant || saving" @click="requestDelete">
            {{ t('admin.connect.lines.dangerZone.deleteAction') }}
          </UiButton>
        </DangerZone>
      </template>
    </UiDrawer>

    <!-- Mode select modal (Embedded Signup — primary connect flow) -->
    <UiModal
      :open="modeSelectOpen"
      :title="t('admin.connect.lines.embeddedSignup.modalTitle')"
      :message="t('admin.connect.lines.embeddedSignup.modalDescription')"
      :confirm-label="t('admin.connect.lines.embeddedSignup.continueAction')"
      :cancel-label="t('common.cancel')"
      @confirm="confirmModeAndConnect"
      @cancel="closeModeSelect"
      @update:open="onModeSelectOpenChange"
    >
      <div class="mode-opts" role="radiogroup" :aria-label="t('admin.connect.lines.embeddedSignup.modalTitle')">
        <button
          type="button"
          class="mode-opt"
          :class="{ 'mode-opt--selected': selectedMode === 'cloud_api' }"
          role="radio"
          :aria-checked="selectedMode === 'cloud_api'"
          @click="selectedMode = 'cloud_api'"
        >
          <strong class="text-body">{{ t('admin.connect.lines.embeddedSignup.modeCloudApiLabel') }}</strong>
          <span class="text-small" style="color: var(--text-mute)">
            {{ t('admin.connect.lines.embeddedSignup.modeCloudApiDescription') }}
          </span>
        </button>
        <button
          type="button"
          class="mode-opt"
          :class="{ 'mode-opt--selected': selectedMode === 'coexistence' }"
          role="radio"
          :aria-checked="selectedMode === 'coexistence'"
          @click="selectedMode = 'coexistence'"
        >
          <strong class="text-body">{{ t('admin.connect.lines.embeddedSignup.modeCoexistenceLabel') }}</strong>
          <span class="text-small" style="color: var(--text-mute)">
            {{ t('admin.connect.lines.embeddedSignup.modeCoexistenceDescription') }}
          </span>
        </button>
      </div>
    </UiModal>

    <!-- Manual connect drawer (secondary fallback) -->
    <UiDrawer
      :open="connectOpen"
      :title="t('admin.connect.lines.connectDrawer.title')"
      :close-label="t('common.close')"
      @update:open="onConnectOpenChange"
    >
      <FormField
        :label="t('admin.connect.lines.connectDrawer.nameLabel')"
        :hint="t('admin.connect.lines.connectDrawer.nameHint')"
      >
        <UiInput v-model="connectForm.name" type="text" required :disabled="saving" />
      </FormField>
      <FormField
        :label="t('admin.connect.lines.connectDrawer.phoneNumberIdLabel')"
        :hint="t('admin.connect.lines.connectDrawer.phoneNumberIdHint')"
      >
        <UiInput v-model="connectForm.phoneNumberId" type="text" required :disabled="saving" />
      </FormField>
      <FormField
        :label="t('admin.connect.lines.connectDrawer.displayPhoneLabel')"
        :hint="t('admin.connect.lines.connectDrawer.displayPhoneHint')"
      >
        <UiInput v-model="connectForm.displayPhoneNumber" type="text" :disabled="saving" />
      </FormField>
      <FormField
        :label="t('admin.connect.lines.connectDrawer.wabaIdLabel')"
        :hint="t('admin.connect.lines.connectDrawer.wabaIdHint')"
      >
        <UiInput v-model="connectForm.wabaId" type="text" :disabled="saving" />
      </FormField>

      <template #footer>
        <UiButton variant="secondary" :disabled="saving" @click="closeConnectDrawer">
          {{ t('common.cancel') }}
        </UiButton>
        <UiButton variant="primary" :loading="saving" :disabled="!canSubmitConnect" @click="submitConnect">
          {{ t('admin.connect.lines.connectDrawer.submitAction') }}
        </UiButton>
      </template>
    </UiDrawer>

    <!-- Delete confirmation -->
    <UiModal
      :open="deleteConfirmOpen"
      :title="t('admin.connect.lines.deleteConfirm.title')"
      :message="
        detailLine
          ? t('admin.connect.lines.deleteConfirm.message', {
              name: detailLine.name,
              number: lineDisplayNumber(detailLine),
            })
          : ''
      "
      :confirm-label="t('admin.connect.lines.deleteConfirm.action')"
      :cancel-label="t('common.cancel')"
      danger
      :confirm-loading="saving"
      @confirm="confirmDelete"
      @cancel="cancelDelete"
    />
  </div>
</template>

<style scoped>
.line-row {
  cursor: pointer;
}

.line-name-btn {
  font-weight: 600;
  color: var(--text);
}

.line-name-btn:hover {
  color: var(--accent);
}

.line-detail-summary {
  display: flex;
  align-items: center;
  gap: var(--space-3);
}

.meta-data-details summary {
  color: var(--text-soft);
}

.danger-zone-hint {
  color: var(--text-mute);
  margin-top: -6px;
}

/* Mode picker cards inside the Embedded Signup modal — mirrors the
   `.model-opt` radiogroup pattern from BehaviorView's model selector. */
.mode-opts {
  display: grid;
  gap: var(--space-2);
}

.mode-opt {
  display: flex;
  flex-direction: column;
  gap: 2px;
  text-align: left;
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  background: var(--bg);
  padding: 10px 12px;
  transition: border-color 150ms ease-out, background-color 150ms ease-out;
}

.mode-opt:hover {
  border-color: var(--border-st);
}

.mode-opt--selected {
  border-color: var(--accent);
  background: var(--accent-subtle);
}
</style>
