<script setup lang="ts">
import { computed, nextTick, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import FormField from '../../../../components/ui/FormField.vue';
import InheritanceChip from '../../../../components/ui/InheritanceChip.vue';
import InlineAlert from '../../../../components/ui/InlineAlert.vue';
import LiveDot from '../../../../components/ui/LiveDot.vue';
import LoadingState from '../../../../components/ui/LoadingState.vue';
import SummaryCard from '../../../../components/ui/SummaryCard.vue';
import SurfaceCard from '../../../../components/ui/SurfaceCard.vue';
import TechValue from '../../../../components/ui/TechValue.vue';
import UiButton from '../../../../components/ui/UiButton.vue';
import UiInput from '../../../../components/ui/UiInput.vue';
import UiModal from '../../../../components/ui/UiModal.vue';
import UiSelect from '../../../../components/ui/UiSelect.vue';
import UiSwitch from '../../../../components/ui/UiSwitch.vue';
import UiTextarea from '../../../../components/ui/UiTextarea.vue';
import { useNavigationAccess } from '../../../../composables/useNavigationAccess';
import { useTenantSelection } from '../../../../composables/useTenantSelection';
import PanelHeader from '../../components/PanelHeader.vue';
import ScopePicker from '../../components/ScopePicker.vue';
import { useAdminResource } from '../../composables/useAdminResource';
import type {
  AdminOverview,
  AgentConfigRecord,
  AgentModelOption,
  WhatsAppLineRecord,
} from '../../types';

type AgentConfigForm = {
  name: string;
  modelKey: string;
  promptVersion: string;
  agentPackKey: string;
  isActive: boolean;
  automationEnabled: boolean;
  systemPrompt: string;
  handoffCustomerMessage: string;
};

type CardKey = 'estado' | 'modelo' | 'personalidad' | 'handoff';
type RecipeKey = 'formal' | 'sales' | 'support';

const CARD_KEYS: CardKey[] = ['estado', 'modelo', 'personalidad', 'handoff'];
const RECIPE_KEYS: RecipeKey[] = ['formal', 'sales', 'support'];

const { t } = useI18n();
const { selectedMembership } = useTenantSelection();
const { canManageAgentConfig } = useNavigationAccess(selectedMembership);

const {
  overview: adminOverview,
  overviewLoading: loading,
  availableAgentPacks: agentPackOptions,
  availableModels: modelOptions,
  agentConfigs,
} = useAdminResource();
const { loading: saving, error, success } = agentConfigs;

const tenantAgentConfigForm = ref<AgentConfigForm>(defaultAgentConfigForm());
const lineAgentConfigDrafts = ref<Record<number, AgentConfigForm>>({});

// --- scope (design.md decision 6: line scope is all-or-nothing, except
// Modelo — see the comment above `currentAgentConfigRecordForScope`) -----

const scope = ref<'tenant' | number>('tenant');
const pendingScope = ref<'tenant' | number | null>(null);
const scopeSwitchConfirmOpen = ref(false);

const openCards = reactive<Record<CardKey, boolean>>({
  estado: false,
  modelo: false,
  personalidad: false,
  handoff: false,
});

const estadoCardRef = ref<InstanceType<typeof SummaryCard> | null>(null);
const modeloCardRef = ref<InstanceType<typeof SummaryCard> | null>(null);
const personalidadCardRef = ref<InstanceType<typeof SummaryCard> | null>(null);
const handoffCardRef = ref<InstanceType<typeof SummaryCard> | null>(null);

const cardRefs: Record<CardKey, typeof estadoCardRef> = {
  estado: estadoCardRef,
  modelo: modeloCardRef,
  personalidad: personalidadCardRef,
  handoff: handoffCardRef,
};

const isAnyCardOpen = computed(() => CARD_KEYS.some((key) => openCards[key]));

const scopeLineOptions = computed(() =>
  (adminOverview.value?.whatsapp_lines ?? []).map((line) => ({ id: line.id, label: lineLabel(line) })),
);

const currentForm = computed<AgentConfigForm>(() => {
  if (scope.value === 'tenant') {
    return tenantAgentConfigForm.value;
  }

  return lineAgentConfigDrafts.value[scope.value] ?? defaultAgentConfigForm();
});

const currentModelOption = computed<AgentModelOption | null>(() => findModelOption(currentForm.value.modelKey));

function requestScopeSwitch(target: 'tenant' | number): void {
  if (isAnyCardOpen.value) {
    pendingScope.value = target;
    scopeSwitchConfirmOpen.value = true;
    return;
  }

  scope.value = target;
}

function confirmScopeSwitch(): void {
  if (pendingScope.value !== null) {
    CARD_KEYS.forEach((key) => {
      openCards[key] = false;
    });
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

function preferredModelKey(options: AgentModelOption[] = []): string {
  return options.find((option) => option.recommended)?.key ?? options[0]?.key ?? 'balanced';
}

function findModelOption(modelKey: string): AgentModelOption | null {
  return modelOptions.value.find((option) => option.key === modelKey) ?? null;
}

function modelPriceTier(option: AgentModelOption): string {
  const index = modelOptions.value.findIndex((candidate) => candidate.key === option.key);
  return '$'.repeat(Math.max(1, index + 1));
}

function defaultAgentConfigForm(
  name = '',
  agentPackKey = 'sales_support_v1',
  modelKey = 'balanced',
): AgentConfigForm {
  return {
    name,
    modelKey,
    promptVersion: 'v1',
    agentPackKey,
    isActive: true,
    automationEnabled: true,
    systemPrompt: '',
    handoffCustomerMessage: '',
  };
}

function agentConfigFormFromRecord(
  record: AgentConfigRecord | null | undefined,
  fallbackName: string,
  fallbackAgentPackKey = 'sales_support_v1',
  fallbackModelKey = preferredModelKey(),
): AgentConfigForm {
  if (!record) {
    return defaultAgentConfigForm(fallbackName, fallbackAgentPackKey, fallbackModelKey);
  }

  return {
    name: record.name,
    modelKey: record.model_key ?? record.effective_model_key ?? fallbackModelKey,
    promptVersion: record.prompt_version ?? 'v1',
    agentPackKey: record.agent_pack_key || fallbackAgentPackKey,
    isActive: record.is_active,
    automationEnabled: record.automation_enabled,
    systemPrompt: record.system_prompt,
    handoffCustomerMessage: record.handoff_customer_message,
  };
}

function resolveTenantAgentConfig(overview: AdminOverview): AgentConfigRecord | null {
  return overview.agent_configs.find((config) => config.scope_type === 'tenant') ?? null;
}

function resolveLineAgentConfig(overview: AdminOverview, lineId: number): AgentConfigRecord | null {
  return (
    overview.agent_configs.find(
      (config) => config.scope_type === 'whatsapp_line' && config.whatsapp_line_id === lineId,
    ) ?? null
  );
}

function resolveEffectiveLineAgentConfig(overview: AdminOverview, lineId: number): AgentConfigRecord | null {
  return resolveLineAgentConfig(overview, lineId) ?? resolveTenantAgentConfig(overview);
}

function lineLabel(line: Pick<WhatsAppLineRecord, 'name' | 'display_phone_number'> | null): string {
  if (!line) {
    return t('admin.shared.generalLabel');
  }

  return `${line.name}${line.display_phone_number ? ` · ${line.display_phone_number}` : ''}`;
}

function truncate(value: string, max = 140): string {
  const trimmed = value.trim();

  if (trimmed.length <= max) {
    return trimmed;
  }

  return `${trimmed.slice(0, max).trimEnd()}…`;
}

function syncForms(overview: AdminOverview): void {
  const fallbackAgentPackKey = overview.available_agent_packs[0]?.key ?? 'sales_support_v1';
  const fallbackModelKey = preferredModelKey(overview.available_models);

  tenantAgentConfigForm.value = agentConfigFormFromRecord(
    resolveTenantAgentConfig(overview),
    `${overview.tenant.name} Assistant`,
    fallbackAgentPackKey,
    fallbackModelKey,
  );

  lineAgentConfigDrafts.value = Object.fromEntries(
    overview.whatsapp_lines.map((line) => [
      line.id,
      agentConfigFormFromRecord(
        resolveEffectiveLineAgentConfig(overview, line.id),
        line.name,
        fallbackAgentPackKey,
        fallbackModelKey,
      ),
    ]),
  );
}

// Every mutation to `agent_configs` (tenant or line scoped) replaces the
// collection with a new array reference (see useAdminResource.ts), so this
// watcher re-syncs the local draft forms after each save/remove without any
// follow-up GET — mirroring the old loadData()-after-every-mutation behavior.
watch(
  () => agentConfigs.data.value,
  () => {
    if (adminOverview.value) {
      syncForms(adminOverview.value);
    }
  },
  { immediate: true },
);

// --- line-scope status & Modelo inheritance --------------------------------
//
// design.md decision 6 (reworked): line-scope is all-or-nothing, with a
// single exception for Modelo. The line-scope endpoints only support a
// whole-row upsert/delete — AdminAgentConfigController::persistConfig
// `forceFill`s every column, and UpsertAgentConfigRequest requires `name`,
// `is_active` and `automation_enabled` — so a per-field
// customized-vs-inherited claim for those fields (or system_prompt,
// handoff_customer_message, agent_pack_key, prompt_version) has no backend
// truth to render: the line's row either exists (its own complete config)
// or it doesn't (it uses the organization's). `model_key` is the one column
// that stays genuinely nullable inside an existing row, and the serializer
// resolves it against the tenant's current value
// (AgentModelCatalog::effectiveForConfigs → `model_source`), so Modelo is
// the only field with real per-field inheritance.
const currentAgentConfigRecordForScope = computed<AgentConfigRecord | null>(() => {
  const overview = adminOverview.value;
  if (!overview) {
    return null;
  }

  return scope.value === 'tenant'
    ? resolveTenantAgentConfig(overview)
    : resolveEffectiveLineAgentConfig(overview, scope.value);
});

const isModelCustomizedInLine = computed<boolean>(
  () => scope.value !== 'tenant' && currentAgentConfigRecordForScope.value?.model_source === 'line',
);

const currentLineHasOwnConfig = computed<boolean>(() => {
  const overview = adminOverview.value;
  if (!overview || scope.value === 'tenant') {
    return false;
  }

  return resolveLineAgentConfig(overview, scope.value) !== null;
});

function applyCardFields(target: AgentConfigForm, source: AgentConfigForm, card: CardKey): void {
  switch (card) {
    case 'estado':
      target.isActive = source.isActive;
      target.automationEnabled = source.automationEnabled;
      break;
    case 'modelo':
      target.modelKey = source.modelKey;
      break;
    case 'personalidad':
      target.systemPrompt = source.systemPrompt;
      break;
    case 'handoff':
      target.handoffCustomerMessage = source.handoffCustomerMessage;
      break;
  }
}

// --- estado / model summaries ---------------------------------------------

function estadoState(form: AgentConfigForm): { label: string; live: boolean } {
  if (!form.isActive) {
    return { label: t('admin.agentConfig.estado.pausedLabel'), live: false };
  }

  if (!form.automationEnabled) {
    return { label: t('admin.agentConfig.estado.waitingHumanLabel'), live: false };
  }

  return { label: t('admin.agentConfig.estado.respondingLabel'), live: true };
}

function cardHelpText(card: CardKey): string {
  if (scope.value === 'tenant') {
    switch (card) {
      case 'estado':
        return t('admin.agentConfig.estado.help');
      case 'modelo':
        return currentModelOption.value?.description ?? '';
      case 'personalidad':
        return t('admin.agentConfig.personalidad.help');
      case 'handoff':
        return t('admin.agentConfig.handoffHelp');
      default:
        return '';
    }
  }

  const form = currentForm.value;

  switch (card) {
    case 'estado':
      return estadoState(form).label;
    case 'modelo':
      return findModelOption(form.modelKey)?.label ?? '';
    case 'personalidad':
      return form.systemPrompt ? `"${truncate(form.systemPrompt)}"` : t('admin.agentConfig.systemPromptHelp');
    case 'handoff':
      return form.handoffCustomerMessage
        ? `"${truncate(form.handoffCustomerMessage, 90)}"`
        : t('admin.agentConfig.handoffHelp');
    default:
      return '';
  }
}

function cardEditLabel(): string {
  if (scope.value !== 'tenant' && !currentLineHasOwnConfig.value) {
    return t('admin.shared.personalizeLabel');
  }

  return t('common.edit');
}

// --- personality recipes ---------------------------------------------------

function applyRecipe(recipe: RecipeKey): void {
  const tenantName = adminOverview.value?.tenant.name ?? '';
  currentForm.value.systemPrompt = t(`admin.agentConfig.personalidad.recipes.${recipe}.template`, {
    tenant: tenantName,
  });
}

// --- persistence -----------------------------------------------------------

async function saveTenantAgentSettings(): Promise<boolean> {
  const form = tenantAgentConfigForm.value;

  const result = await agentConfigs.updateTenant(
    {
      name: form.name.trim(),
      model_key: form.modelKey || undefined,
      prompt_version: form.promptVersion.trim() || undefined,
      is_active: form.isActive,
      automation_enabled: form.automationEnabled,
      system_prompt: form.systemPrompt.trim() || undefined,
      agent_pack_key: form.agentPackKey.trim() || undefined,
      handoff_customer_message: form.handoffCustomerMessage.trim() || undefined,
    },
    { successMessage: t('admin.success.tenantAssistantUpdated') },
  );

  return result !== undefined;
}

async function saveLineAgentSettings(lineId: number): Promise<boolean> {
  const draft = lineAgentConfigDrafts.value[lineId];

  if (!draft) {
    return false;
  }

  const overview = adminOverview.value;
  const wasCustomized = overview ? resolveLineAgentConfig(overview, lineId) !== null : false;

  const result = await agentConfigs.updateLine(
    lineId,
    {
      name: draft.name.trim(),
      model_key: draft.modelKey || undefined,
      prompt_version: draft.promptVersion.trim() || undefined,
      is_active: draft.isActive,
      automation_enabled: draft.automationEnabled,
      system_prompt: draft.systemPrompt.trim() || undefined,
      agent_pack_key: draft.agentPackKey.trim() || undefined,
      handoff_customer_message: draft.handoffCustomerMessage.trim() || undefined,
    },
    {
      successMessage: wasCustomized
        ? t('admin.success.lineAssistantUpdated')
        : t('admin.success.lineAssistantCreated'),
    },
  );

  return result !== undefined;
}

async function removeLineAgentCustomization(lineId: number): Promise<void> {
  await agentConfigs.removeLineOverride(lineId, { successMessage: t('admin.success.lineAssistantRemoved') });
}

async function saveScopedSettings(): Promise<boolean> {
  return scope.value === 'tenant' ? saveTenantAgentSettings() : saveLineAgentSettings(scope.value);
}

function resetCardFields(card: CardKey): void {
  const overview = adminOverview.value;
  if (!overview) {
    return;
  }

  const fallbackAgentPackKey = overview.available_agent_packs[0]?.key ?? 'sales_support_v1';
  const fallbackModelKey = preferredModelKey(overview.available_models);

  if (scope.value === 'tenant') {
    const fresh = agentConfigFormFromRecord(
      resolveTenantAgentConfig(overview),
      `${overview.tenant.name} Assistant`,
      fallbackAgentPackKey,
      fallbackModelKey,
    );
    applyCardFields(tenantAgentConfigForm.value, fresh, card);
    return;
  }

  const lineId = scope.value;
  const line = overview.whatsapp_lines.find((candidate) => candidate.id === lineId);
  const draft = lineAgentConfigDrafts.value[lineId];

  if (!line || !draft) {
    return;
  }

  const fresh = agentConfigFormFromRecord(
    resolveEffectiveLineAgentConfig(overview, lineId),
    line.name,
    fallbackAgentPackKey,
    fallbackModelKey,
  );
  applyCardFields(draft, fresh, card);
}

function openCard(card: CardKey): void {
  openCards[card] = true;
}

function cancelCard(card: CardKey): void {
  openCards[card] = false;
  resetCardFields(card);
}

async function saveCard(card: CardKey): Promise<void> {
  const ok = await saveScopedSettings();

  if (!ok) {
    return;
  }

  openCards[card] = false;
  await nextTick();
  cardRefs[card].value?.flash();
}

// "Restaurar al general" at the line-scope level: deletes the line's row
// entirely, moving it back to "uses the organization's configuration".
async function restoreLineToGeneral(): Promise<void> {
  if (scope.value === 'tenant') {
    return;
  }

  await removeLineAgentCustomization(scope.value);
}

// Modelo-only restore: nulls out just the model_key override (sending it
// as undefined omits it from the payload, and persistConfig stores that as
// `null`) while keeping the rest of the line's row untouched — see the
// module-level comment above `currentAgentConfigRecordForScope`.
async function restoreModelToInherited(): Promise<void> {
  if (scope.value === 'tenant') {
    return;
  }

  const lineId = scope.value;
  const draft = lineAgentConfigDrafts.value[lineId];
  if (!draft) {
    return;
  }

  const previousModelKey = draft.modelKey;
  draft.modelKey = '';

  const ok = await saveLineAgentSettings(lineId);

  if (!ok) {
    draft.modelKey = previousModelKey;
  }
}

async function saveAdvanced(): Promise<void> {
  await saveScopedSettings();
}
</script>

<template>
  <div class="space-y-5">
    <PanelHeader
      group="admin.nav.assistant"
      panel="admin.assistant.behavior.title"
      description="admin.assistant.behavior.description"
    />

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

      <ScopePicker :scope="scope" :lines="scopeLineOptions" @request-switch="requestScopeSwitch" />

      <div v-if="scope !== 'tenant'" class="flex max-w-[720px] flex-wrap items-center justify-between gap-3">
        <InlineAlert
          class="flex-1"
          :tone="currentLineHasOwnConfig ? 'info' : 'success'"
          :message="
            currentLineHasOwnConfig
              ? t('admin.shared.lineHasOwnConfigMessage')
              : t('admin.shared.lineUsesGeneralConfigMessage')
          "
        />
        <UiButton
          v-if="currentLineHasOwnConfig"
          variant="secondary"
          size="sm"
          :disabled="!canManageAgentConfig || saving"
          @click="restoreLineToGeneral"
        >
          {{ t('admin.shared.restoreToGeneralLabel') }}
        </UiButton>
      </div>

      <div class="flex max-w-[720px] flex-col gap-3">
        <!-- Estado -->
        <SummaryCard
          ref="estadoCardRef"
          data-settings-key="behavior.estado"
          :open="openCards.estado"
          :title="t('admin.agentConfig.estado.title')"
          :help="cardHelpText('estado')"
          :edit-label="cardEditLabel()"
          :cancel-label="t('common.cancel')"
          :save-label="t('admin.agentConfig.estado.save')"
          :saving="saving"
          :disabled="!canManageAgentConfig"
          @update:open="(value) => value && openCard('estado')"
          @cancel="cancelCard('estado')"
          @save="saveCard('estado')"
        >
          <template #state>
            <LiveDot :label="estadoState(currentForm).label" :live="estadoState(currentForm).live" />
          </template>

          <div class="grid gap-4">
            <UiSwitch
              v-model="currentForm.isActive"
              :label="t('admin.agentConfig.activeLabel')"
              :disabled="!canManageAgentConfig || saving"
            />
            <p class="text-small -mt-2" style="color: var(--text-mute)">{{ t('admin.agentConfig.activeHelp') }}</p>

            <UiSwitch
              v-model="currentForm.automationEnabled"
              :label="t('admin.agentConfig.automationLabel')"
              :disabled="!canManageAgentConfig || saving"
            />
            <p class="text-small -mt-2" style="color: var(--text-mute)">{{ t('admin.agentConfig.automationHelp') }}</p>
          </div>
        </SummaryCard>

        <!-- Modelo -->
        <SummaryCard
          ref="modeloCardRef"
          data-settings-key="behavior.modelo"
          :open="openCards.modelo"
          :title="t('admin.agentConfig.modelo.title')"
          :help="cardHelpText('modelo')"
          :edit-label="cardEditLabel()"
          :cancel-label="t('common.cancel')"
          :save-label="t('admin.agentConfig.modelo.save')"
          :saving="saving"
          :disabled="!canManageAgentConfig"
          @update:open="(value) => value && openCard('modelo')"
          @cancel="cancelCard('modelo')"
          @save="saveCard('modelo')"
        >
          <template #state>
            <InheritanceChip
              v-if="scope !== 'tenant'"
              :customized="isModelCustomizedInLine"
              :inherited-label="t('admin.shared.inheritedFromOrgLabel')"
              :customized-label="t('admin.shared.customizedInLineLabel')"
              :restore-label="canManageAgentConfig ? t('admin.shared.restoreToGeneralLabel') : undefined"
              @restore="restoreModelToInherited"
            />
            <template v-else>
              {{ currentModelOption?.label }}
              <template v-if="currentModelOption?.recommended"> — {{ t('admin.agentConfig.recommendedBadge') }}</template>
            </template>
          </template>

          <div class="model-opts" role="radiogroup" :aria-label="t('admin.agentConfig.modelo.title')">
            <button
              v-for="option in modelOptions"
              :key="option.key"
              type="button"
              class="model-opt"
              :class="{ 'model-opt--selected': currentForm.modelKey === option.key }"
              role="radio"
              :aria-checked="currentForm.modelKey === option.key"
              :disabled="!canManageAgentConfig || saving"
              @click="currentForm.modelKey = option.key"
            >
              <strong class="text-body">{{ option.label }}</strong>
              <span class="text-small" style="color: var(--text-mute)">{{ option.description }}</span>
              <span class="text-mono model-opt-price">{{ modelPriceTier(option) }}</span>
            </button>
          </div>
          <p class="mt-3">
            <TechValue :value="currentModelOption?.model_id ?? ''" />
          </p>
        </SummaryCard>

        <!-- Personalidad -->
        <SummaryCard
          ref="personalidadCardRef"
          data-settings-key="behavior.personalidad"
          :open="openCards.personalidad"
          :title="t('admin.agentConfig.personalidad.title')"
          :help="cardHelpText('personalidad')"
          :edit-label="cardEditLabel()"
          :cancel-label="t('common.cancel')"
          :save-label="t('admin.agentConfig.personalidad.save')"
          :saving="saving"
          :disabled="!canManageAgentConfig"
          @update:open="(value) => value && openCard('personalidad')"
          @cancel="cancelCard('personalidad')"
          @save="saveCard('personalidad')"
        >
          <template #state>{{ currentForm.systemPrompt ? `"${truncate(currentForm.systemPrompt)}"` : '' }}</template>

          <div class="grid gap-3">
            <div class="flex flex-wrap items-center gap-2">
              <span class="text-small" style="color: var(--text-mute)">{{ t('admin.agentConfig.personalidad.recipesLabel') }}</span>
              <UiButton
                v-for="recipe in RECIPE_KEYS"
                :key="recipe"
                variant="secondary"
                size="sm"
                :disabled="!canManageAgentConfig || saving"
                @click="applyRecipe(recipe)"
              >
                {{ t(`admin.agentConfig.personalidad.recipes.${recipe}.label`) }}
              </UiButton>
            </div>

            <FormField :label="t('admin.agentConfig.systemPromptLabel')" :hint="t('admin.agentConfig.systemPromptHelp')">
              <UiTextarea v-model="currentForm.systemPrompt" :rows="6" :disabled="!canManageAgentConfig || saving" />
            </FormField>
          </div>
        </SummaryCard>

        <!-- Handoff -->
        <SummaryCard
          ref="handoffCardRef"
          data-settings-key="behavior.handoff"
          :open="openCards.handoff"
          :title="t('admin.agentConfig.handoff.title')"
          :help="cardHelpText('handoff')"
          :edit-label="cardEditLabel()"
          :cancel-label="t('common.cancel')"
          :save-label="t('admin.agentConfig.handoff.save')"
          :saving="saving"
          :disabled="!canManageAgentConfig"
          @update:open="(value) => value && openCard('handoff')"
          @cancel="cancelCard('handoff')"
          @save="saveCard('handoff')"
        >
          <template #state>{{ currentForm.handoffCustomerMessage ? `"${currentForm.handoffCustomerMessage}"` : '' }}</template>

          <FormField :label="t('admin.agentConfig.handoffLabel')" :hint="t('admin.agentConfig.handoffHelp')">
            <UiTextarea v-model="currentForm.handoffCustomerMessage" :rows="4" :disabled="!canManageAgentConfig || saving" />
          </FormField>
        </SummaryCard>
      </div>

      <details class="advanced-details max-w-[720px]">
        <summary class="cursor-pointer text-small font-semibold">{{ t('admin.shared.advancedTitle') }}</summary>

        <SurfaceCard class="mt-3">
          <p class="text-small" style="color: var(--text-mute)">{{ t('admin.agentConfig.advancedDescription') }}</p>

          <div class="mt-4 grid gap-4 md:grid-cols-2">
            <FormField :label="t('admin.agentConfig.internalName')">
              <UiInput v-model="currentForm.name" type="text" :disabled="!canManageAgentConfig || saving" />
            </FormField>
            <FormField :label="t('admin.agentConfig.modelIdLabel')" :hint="t('admin.agentConfig.modelIdHelp')">
              <TechValue :value="currentModelOption?.model_id ?? ''" />
            </FormField>
            <FormField :label="t('admin.agentConfig.promptVersionLabel')">
              <UiInput v-model="currentForm.promptVersion" type="text" :disabled="!canManageAgentConfig || saving" />
            </FormField>
            <FormField :label="t('admin.agentConfig.agentPackLabel')">
              <UiSelect v-if="agentPackOptions.length > 0" v-model="currentForm.agentPackKey" :disabled="!canManageAgentConfig || saving">
                <option v-for="option in agentPackOptions" :key="option.key" :value="option.key">
                  {{ option.name }}
                </option>
              </UiSelect>
              <UiInput v-else v-model="currentForm.agentPackKey" type="text" :disabled="!canManageAgentConfig || saving" />
            </FormField>
          </div>

          <UiButton
            class="mt-4"
            variant="secondary"
            :loading="saving"
            :disabled="!canManageAgentConfig"
            @click="saveAdvanced"
          >
            {{ t('admin.agentConfig.saveAdvanced') }}
          </UiButton>
        </SurfaceCard>
      </details>
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
.model-opts {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: var(--space-2);
}

.model-opt {
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

.model-opt:hover:not(:disabled) {
  border-color: var(--border-st);
}

.model-opt:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.model-opt--selected {
  border-color: var(--accent);
  background: var(--accent-subtle);
}

.model-opt-price {
  margin-top: 4px;
  font-weight: 600;
  color: var(--text-mute);
}

.advanced-details summary {
  color: var(--text-soft);
}
</style>
