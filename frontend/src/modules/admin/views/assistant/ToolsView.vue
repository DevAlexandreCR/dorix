<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import FormField from '../../../../components/ui/FormField.vue';
import InlineAlert from '../../../../components/ui/InlineAlert.vue';
import LoadingState from '../../../../components/ui/LoadingState.vue';
import SettingRow from '../../../../components/ui/SettingRow.vue';
import StatusBadge from '../../../../components/ui/StatusBadge.vue';
import SurfaceCard from '../../../../components/ui/SurfaceCard.vue';
import UiButton from '../../../../components/ui/UiButton.vue';
import UiInput from '../../../../components/ui/UiInput.vue';
import UiModal from '../../../../components/ui/UiModal.vue';
import UiSelect from '../../../../components/ui/UiSelect.vue';
import UiSwitch from '../../../../components/ui/UiSwitch.vue';
import { useNavigationAccess } from '../../../../composables/useNavigationAccess';
import { useTenantSelection } from '../../../../composables/useTenantSelection';
import PanelHeader from '../../components/PanelHeader.vue';
import ScopePicker from '../../components/ScopePicker.vue';
import { useAdminResource } from '../../composables/useAdminResource';
import type { AdminOverview, ToolConfigRecord, WhatsAppLineRecord } from '../../types';

type ToolConfigForm = {
  enabled: boolean;
  timeoutSeconds: string;
  dataSourceId: string;
};

// Business-name copy for every tool registered in ToolRegistry (design/05
// "nombres solo de negocio"). `search_knowledge`/`search_inventory` are the
// only two with `supports_data_source_binding` (AdminPanelDataBuilder), so
// they're the only rows whose nested disclosure shows a source picker.
const toolCopyKeys: Record<string, { title: string; effect: string }> = {
  search_knowledge: {
    title: 'admin.assistant.tools.toolLabels.search_knowledge.title',
    effect: 'admin.assistant.tools.toolLabels.search_knowledge.effect',
  },
  search_inventory: {
    title: 'admin.assistant.tools.toolLabels.search_inventory.title',
    effect: 'admin.assistant.tools.toolLabels.search_inventory.effect',
  },
  save_customer_data: {
    title: 'admin.assistant.tools.toolLabels.save_customer_data.title',
    effect: 'admin.assistant.tools.toolLabels.save_customer_data.effect',
  },
  create_lead: {
    title: 'admin.assistant.tools.toolLabels.create_lead.title',
    effect: 'admin.assistant.tools.toolLabels.create_lead.effect',
  },
  handoff_to_human: {
    title: 'admin.assistant.tools.toolLabels.handoff_to_human.title',
    effect: 'admin.assistant.tools.toolLabels.handoff_to_human.effect',
  },
  get_service_details: {
    title: 'admin.assistant.tools.toolLabels.get_service_details.title',
    effect: 'admin.assistant.tools.toolLabels.get_service_details.effect',
  },
  check_availability: {
    title: 'admin.assistant.tools.toolLabels.check_availability.title',
    effect: 'admin.assistant.tools.toolLabels.check_availability.effect',
  },
  create_appointment: {
    title: 'admin.assistant.tools.toolLabels.create_appointment.title',
    effect: 'admin.assistant.tools.toolLabels.create_appointment.effect',
  },
};

const { t } = useI18n();
const { selectedMembership } = useTenantSelection();
const { canManageAgentConfig } = useNavigationAccess(selectedMembership);

const { overview: adminOverview, overviewLoading: loading, toolConfigs } = useAdminResource();
const { loading: saving, error, success } = toolConfigs;

const tenantToolDrafts = ref<Record<string, ToolConfigForm>>({});
const lineToolDrafts = ref<Record<string, ToolConfigForm>>({});

// --- scope --------------------------------------------------------------
//
// All-or-nothing, PER TOOL ROW — unlike Behavior's single line-wide
// statement, TenantToolConfig::persistConfig forceFill's `enabled`/
// `timeout_seconds`/`bindings` per (scope_key, tool_name) row, and
// toolConfigs.removeLineOverride() takes a `toolName` argument because a
// line can override some tools and not others independently (see
// feedback_line_scope_all_or_nothing memory). So each tool row here carries
// its own "usa la configuración general" / "tiene su propia configuración"
// readout and its own "Restaurar al general" action, instead of one banner
// for the whole screen. It deliberately does NOT use the `InheritanceChip`
// component — spec.md reserves that pattern for Modelo only.

const scope = ref<'tenant' | number>('tenant');
const pendingScope = ref<'tenant' | number | null>(null);
const scopeSwitchConfirmOpen = ref(false);

const scopeLineOptions = computed(() =>
  (adminOverview.value?.whatsapp_lines ?? []).map((line) => ({ id: line.id, label: lineLabel(line) })),
);

const readyDataSources = computed(() =>
  (adminOverview.value?.data_sources ?? []).filter((source) => source.status === 'ready'),
);

// Drafts for whichever scope is currently selected, keyed by tool name — an
// indexed lookup (not a function call) so template `v-if`/`v-model`
// expressions on `scopedToolDrafts[tool.name]` narrow correctly, the same
// pattern the pre-4.2 view used with `tenantToolDrafts[toolName]`.
const scopedToolDrafts = computed<Record<string, ToolConfigForm>>(() => {
  const overview = adminOverview.value;
  if (!overview) {
    return {};
  }

  return Object.fromEntries(
    overview.available_tools.map((tool) => [
      tool.name,
      scope.value === 'tenant' ? tenantToolDrafts.value[tool.name] : lineToolDrafts.value[toolDraftKey(scope.value, tool.name)],
    ]),
  );
});

function requestScopeSwitch(target: 'tenant' | number): void {
  if (isCurrentScopeDirty.value) {
    pendingScope.value = target;
    scopeSwitchConfirmOpen.value = true;
    return;
  }

  scope.value = target;
}

function confirmScopeSwitch(): void {
  if (pendingScope.value !== null) {
    scope.value = pendingScope.value;
  }

  scopeSwitchConfirmOpen.value = false;
  pendingScope.value = null;
}

function cancelScopeSwitch(): void {
  scopeSwitchConfirmOpen.value = false;
  pendingScope.value = null;
}

// --- form helpers --------------------------------------------------------

function toolDraftKey(lineId: number, toolName: string): string {
  return `${lineId}:${toolName}`;
}

function defaultToolConfigForm(record?: ToolConfigRecord | null): ToolConfigForm {
  return {
    enabled: record?.enabled ?? true,
    timeoutSeconds: record?.timeout_seconds ? String(record.timeout_seconds) : '',
    dataSourceId: record?.data_source_id ? String(record.data_source_id) : '',
  };
}

function resolveTenantToolConfig(overview: AdminOverview, toolName: string): ToolConfigRecord | null {
  return overview.tool_configs.find((config) => config.scope_type === 'tenant' && config.tool_name === toolName) ?? null;
}

function resolveLineToolConfig(overview: AdminOverview, lineId: number, toolName: string): ToolConfigRecord | null {
  return (
    overview.tool_configs.find(
      (config) =>
        config.scope_type === 'whatsapp_line' &&
        config.whatsapp_line_id === lineId &&
        config.tool_name === toolName,
    ) ?? null
  );
}

function resolveEffectiveLineToolConfig(
  overview: AdminOverview,
  lineId: number,
  toolName: string,
): ToolConfigRecord | null {
  return resolveLineToolConfig(overview, lineId, toolName) ?? resolveTenantToolConfig(overview, toolName);
}

function lineLabel(line: Pick<WhatsAppLineRecord, 'name' | 'display_phone_number'> | null): string {
  if (!line) {
    return t('admin.shared.generalLabel');
  }

  return `${line.name}${line.display_phone_number ? ` · ${line.display_phone_number}` : ''}`;
}

function translateToolTitle(toolName: string): string {
  const entry = toolCopyKeys[toolName];

  return entry ? t(entry.title) : toolName;
}

function translateToolEffect(toolName: string): string {
  const entry = toolCopyKeys[toolName];

  return entry ? t(entry.effect) : '';
}

function lineHasToolCustomization(lineId: number, toolName: string): boolean {
  const overview = adminOverview.value;

  return overview ? resolveLineToolConfig(overview, lineId, toolName) !== null : false;
}

// Per-row line status label — deliberately not the `InheritanceChip`
// component (spec.md reserves that for Modelo only); this is a plain,
// honest readout of the two real states the backend can express.
function lineToolStatusLabel(toolName: string): string {
  if (scope.value === 'tenant') {
    return '';
  }

  return lineHasToolCustomization(scope.value, toolName)
    ? t('admin.dataSources.customizationStatus.customized')
    : t('admin.dataSources.customizationStatus.general');
}

function isLineToolCustomized(toolName: string): boolean {
  return scope.value !== 'tenant' && lineHasToolCustomization(scope.value, toolName);
}

function nestedSaveLabel(toolName: string): string {
  if (scope.value === 'tenant') {
    return t('admin.dataSources.saveGeneralUsage');
  }

  return lineHasToolCustomization(scope.value, toolName)
    ? t('admin.dataSources.saveCustomization')
    : t('admin.dataSources.createCustomization');
}

function toolSuccessMessage(toolName: string): string {
  const area = translateToolTitle(toolName);

  if (scope.value === 'tenant') {
    return t('admin.success.generalSourceUsageUpdated', { area });
  }

  return lineHasToolCustomization(scope.value, toolName)
    ? t('admin.success.lineSourceUsageUpdated', { area })
    : t('admin.success.lineSourceUsageCreated', { area });
}

function syncForms(overview: AdminOverview): void {
  tenantToolDrafts.value = Object.fromEntries(
    overview.available_tools.map((tool) => [tool.name, defaultToolConfigForm(resolveTenantToolConfig(overview, tool.name))]),
  );

  lineToolDrafts.value = Object.fromEntries(
    overview.whatsapp_lines.flatMap((line) =>
      overview.available_tools.map((tool) => [
        toolDraftKey(line.id, tool.name),
        defaultToolConfigForm(resolveEffectiveLineToolConfig(overview, line.id, tool.name)),
      ]),
    ),
  );
}

// Every mutation to `tool_configs` (tenant or line scoped) replaces the
// collection with a new array reference (see useAdminResource.ts), so this
// watcher re-syncs the local draft forms after each save/remove without any
// follow-up GET — mirroring the old loadData()-after-every-mutation behavior.
watch(
  () => toolConfigs.data.value,
  () => {
    if (adminOverview.value) {
      syncForms(adminOverview.value);
    }
  },
  { immediate: true },
);

// A row only has something "unsaved" once its nested source/timeout config
// (staged, not auto-saved — see `saveNestedConfig`) differs from what's
// persisted; the switch itself auto-applies on toggle, so it's never dirty
// for longer than the round trip. Only tools with a nested editor can be
// dirty at all.
const isCurrentScopeDirty = computed<boolean>(() => {
  const overview = adminOverview.value;
  if (!overview) {
    return false;
  }

  return overview.available_tools.some((tool) => {
    if (!tool.supports_data_source_binding) {
      return false;
    }

    const draft = scopedToolDrafts.value[tool.name];
    if (!draft || !draft.enabled) {
      return false;
    }

    const persisted =
      scope.value === 'tenant'
        ? resolveTenantToolConfig(overview, tool.name)
        : resolveEffectiveLineToolConfig(overview, scope.value, tool.name);

    const persistedTimeout = persisted?.timeout_seconds ? String(persisted.timeout_seconds) : '';
    const persistedSource = persisted?.data_source_id ? String(persisted.data_source_id) : '';

    return draft.timeoutSeconds !== persistedTimeout || draft.dataSourceId !== persistedSource;
  });
});

// --- persistence -----------------------------------------------------------

async function persistToolConfig(toolName: string, successMessage: string): Promise<boolean> {
  const draft = scopedToolDrafts.value[toolName];

  if (!draft) {
    return false;
  }

  const payload = {
    enabled: draft.enabled,
    timeout_seconds: draft.timeoutSeconds ? Number(draft.timeoutSeconds) : null,
    data_source_id: draft.dataSourceId ? Number(draft.dataSourceId) : null,
  };

  if (scope.value === 'tenant') {
    const result = await toolConfigs.updateTenant(toolName, payload, { successMessage });
    return result !== undefined;
  }

  const result = await toolConfigs.updateLine(scope.value, toolName, payload, { successMessage });
  return result !== undefined;
}

// The switch is a live control (SettingRow has no open/edit state, unlike
// SummaryCard) — flipping it applies immediately, the same affordance as
// any settings toggle. The rest of the row's current draft (source/timeout)
// travels with it since the backend always forceFill's the whole row.
async function onToggleTool(toolName: string, next: boolean): Promise<void> {
  const draft = scopedToolDrafts.value[toolName];

  if (!draft) {
    return;
  }

  const message = toolSuccessMessage(toolName);
  const previous = draft.enabled;
  draft.enabled = next;

  const ok = await persistToolConfig(toolName, message);

  if (!ok) {
    draft.enabled = previous;
  }
}

// Source + advanced timeout are staged (a free-typed number shouldn't save
// per keystroke) and committed together via this explicit action.
async function saveNestedConfig(toolName: string): Promise<void> {
  await persistToolConfig(toolName, toolSuccessMessage(toolName));
}

async function removeLineToolCustomization(toolName: string): Promise<void> {
  if (scope.value === 'tenant') {
    return;
  }

  await toolConfigs.removeLineOverride(scope.value, toolName, {
    successMessage: t('admin.success.lineSourceUsageRemoved', { area: translateToolTitle(toolName) }),
  });
}
</script>

<template>
  <div class="space-y-5">
    <PanelHeader
      group="admin.nav.assistant"
      panel="admin.assistant.tools.title"
      description="admin.assistant.tools.description"
    />

    <div v-if="loading && !adminOverview">
      <SurfaceCard>
        <LoadingState :label="t('admin.loading')" />
      </SurfaceCard>
    </div>

    <template v-else-if="adminOverview">
      <div class="grid max-w-[720px] gap-3">
        <InlineAlert v-if="error" :message="error" tone="danger" />
        <InlineAlert v-if="success" :message="success" tone="success" />
      </div>

      <ScopePicker :scope="scope" :lines="scopeLineOptions" @request-switch="requestScopeSwitch" />

      <InlineAlert
        v-if="readyDataSources.length === 0"
        class="max-w-[720px]"
        tone="info"
        :message="t('admin.dataSources.noReadySources')"
      />

      <div class="tool-list max-w-[720px]">
        <SettingRow
          v-for="tool in adminOverview.available_tools"
          :key="tool.name"
          :data-settings-key="`tools.${tool.name}`"
          :label="translateToolTitle(tool.name)"
          :help="translateToolEffect(tool.name)"
          :nested-visible="Boolean(tool.supports_data_source_binding && scopedToolDrafts[tool.name]?.enabled)"
          :disabled="!canManageAgentConfig"
        >
          <template #control>
            <UiSwitch
              :model-value="scopedToolDrafts[tool.name]?.enabled ?? false"
              :disabled="!canManageAgentConfig || saving"
              @update:model-value="(value) => onToggleTool(tool.name, value)"
            />
          </template>

          <template #nested>
            <div class="grid gap-3">
              <div v-if="scope !== 'tenant'" class="tool-line-status">
                <StatusBadge :label="lineToolStatusLabel(tool.name)" tone="neutral" />
                <UiButton
                  v-if="isLineToolCustomized(tool.name)"
                  variant="secondary"
                  size="sm"
                  :disabled="!canManageAgentConfig || saving"
                  @click="removeLineToolCustomization(tool.name)"
                >
                  {{ t('admin.shared.restoreToGeneralLabel') }}
                </UiButton>
              </div>

              <template v-if="scopedToolDrafts[tool.name]">
                <FormField
                  :label="scope === 'tenant' ? t('admin.dataSources.generalSourceLabel') : t('admin.dataSources.lineSourceLabel')"
                  :hint="scope === 'tenant' ? t('admin.dataSources.generalSourceHelp') : t('admin.dataSources.lineSourceHelp')"
                >
                  <UiSelect v-model="scopedToolDrafts[tool.name].dataSourceId" :disabled="!canManageAgentConfig || saving">
                    <option value="">
                      {{ scope === 'tenant' ? t('admin.dataSources.noSourceSelected') : t('admin.dataSources.useGeneralSource') }}
                    </option>
                    <option v-for="source in readyDataSources" :key="source.id" :value="String(source.id)">
                      {{ source.name }}
                    </option>
                  </UiSelect>
                </FormField>

                <details class="tool-advanced">
                  <summary class="cursor-pointer text-small font-semibold">{{ t('admin.shared.advancedTitle') }}</summary>
                  <div class="mt-3">
                    <FormField :label="t('admin.dataSources.waitTimeLabel')" :hint="t('admin.dataSources.waitTimeHelp')">
                      <UiInput
                        v-model="scopedToolDrafts[tool.name].timeoutSeconds"
                        type="number"
                        :disabled="!canManageAgentConfig || saving"
                      />
                    </FormField>
                  </div>
                </details>
              </template>

              <UiButton
                variant="secondary"
                size="sm"
                class="justify-self-start"
                :disabled="!canManageAgentConfig || saving"
                @click="saveNestedConfig(tool.name)"
              >
                {{ nestedSaveLabel(tool.name) }}
              </UiButton>
            </div>
          </template>
        </SettingRow>
      </div>
    </template>

    <UiModal
      :open="scopeSwitchConfirmOpen"
      :title="t('admin.shared.scopeSwitchConfirmTitle')"
      :message="t('admin.shared.scopeSwitchConfirmMessage')"
      :confirm-label="t('admin.shared.scopeSwitchConfirmAction')"
      :cancel-label="t('admin.shared.scopeSwitchKeepEditing')"
      @confirm="confirmScopeSwitch"
      @cancel="cancelScopeSwitch"
    />
  </div>
</template>

<style scoped>
.tool-list {
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  background: var(--surface);
  overflow: hidden;
}

.tool-line-status {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-3);
}

.tool-advanced summary {
  color: var(--text-soft);
}
</style>
