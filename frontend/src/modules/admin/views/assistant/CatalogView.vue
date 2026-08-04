<script setup lang="ts">
import { Plus } from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import DangerZone from '../../../../components/ui/DangerZone.vue';
import DataTable from '../../../../components/ui/DataTable.vue';
import FormField from '../../../../components/ui/FormField.vue';
import InlineAlert from '../../../../components/ui/InlineAlert.vue';
import LoadingState from '../../../../components/ui/LoadingState.vue';
import StatusBadge from '../../../../components/ui/StatusBadge.vue';
import SurfaceCard from '../../../../components/ui/SurfaceCard.vue';
import UiButton from '../../../../components/ui/UiButton.vue';
import UiDrawer from '../../../../components/ui/UiDrawer.vue';
import UiInput from '../../../../components/ui/UiInput.vue';
import UiModal from '../../../../components/ui/UiModal.vue';
import UiSelect from '../../../../components/ui/UiSelect.vue';
import UiSwitch from '../../../../components/ui/UiSwitch.vue';
import UiTextarea from '../../../../components/ui/UiTextarea.vue';
import { useNavigationAccess } from '../../../../composables/useNavigationAccess';
import { useTenantSelection } from '../../../../composables/useTenantSelection';
import PanelHeader from '../../components/PanelHeader.vue';
import { useAdminResource } from '../../composables/useAdminResource';
import type { CatalogItemKind, CatalogItemPriceType, CatalogItemRecord } from '../../types';

// design.md D11 / specs/ui-admin: "Entidades en tablas con drawer" — same
// `DataTable` + `UiDrawer` collection pattern as members/lines/data
// sources, NOT the resumen-primero layout `agentConfig`/`toolConfig`
// screens use. Unlike those sibling screens, catalog items are edited
// with a single shared drawer for both create and edit (design.md calls
// it "su drawer de creación/edición", singular) instead of a separate
// "connect"/"detail" pair: the form itself — kind, price shape, duration,
// assessment link — is identical in both modes, and duplicating that
// many adaptive fields across two templates would just be copy-paste
// drift waiting to happen. Create mode is `editingId === null`.
//
// Adaptivity (D3/D4 of design.md, backend-enforced in
// `UpsertCatalogItemRequest`):
// - `kind: product` hides + never sends `duration_minutes` /
//   `assessment_item_id`.
// - `price_type` selects which price field(s) show: `fixed`/`from` → a
//   single amount (blank allowed when an assessment link is set — that's
//   "precio según valoración"); `range` → min/max.
// - The assessment selector only offers `is_bookable` items (the
//   backend's own agendability derivation) excluding the item being
//   edited — that already encodes "sin cadenas" (an assessment target
//   can never itself have an assessment link, so it's never `is_bookable`
//   once it's tied to one) without duplicating that rule on the frontend.

type ItemForm = {
  kind: CatalogItemKind;
  name: string;
  category: string;
  description: string;
  priceType: CatalogItemPriceType;
  priceAmount: string;
  priceMin: string;
  priceMax: string;
  currency: string;
  durationMinutes: string;
  assessmentItemId: string;
  active: boolean;
};

function defaultForm(): ItemForm {
  return {
    kind: 'service',
    name: '',
    category: '',
    description: '',
    priceType: 'fixed',
    priceAmount: '',
    priceMin: '',
    priceMax: '',
    currency: 'COP',
    durationMinutes: '',
    assessmentItemId: '',
    active: true,
  };
}

function formFromItem(item: CatalogItemRecord): ItemForm {
  return {
    kind: item.kind,
    name: item.name,
    category: item.category ?? '',
    description: item.description ?? '',
    priceType: item.price_type,
    priceAmount: item.price_amount ?? '',
    priceMin: item.price_min ?? '',
    priceMax: item.price_max ?? '',
    currency: item.currency,
    durationMinutes: item.duration_minutes ? String(item.duration_minutes) : '',
    assessmentItemId: item.assessment_item_id ? String(item.assessment_item_id) : '',
    active: item.active,
  };
}

const { t } = useI18n();
const { selectedMembership } = useTenantSelection();
const { canManageAgentConfig } = useNavigationAccess(selectedMembership);

const { overview: adminOverview, overviewLoading: loading, catalogItems } = useAdminResource();
const { loading: saving, error, success } = catalogItems;

// --- table helpers -----------------------------------------------------------

function kindLabel(kind: CatalogItemKind): string {
  return t(`admin.assistant.catalog.kindLabels.${kind}`);
}

function assessmentItemName(assessmentItemId: number): string {
  const target = catalogItems.data.value.find((candidate) => candidate.id === assessmentItemId);
  return target?.name ?? t('common.notAvailable');
}

function schedulingLabel(item: CatalogItemRecord): string {
  if (item.assessment_item_id) {
    return t('admin.assistant.catalog.scheduling.requiresAssessment', {
      name: assessmentItemName(item.assessment_item_id),
    });
  }

  if (item.duration_minutes) {
    return t('admin.assistant.catalog.scheduling.duration', { minutes: item.duration_minutes });
  }

  return t('admin.assistant.catalog.scheduling.notBookable');
}

function statusLabel(item: CatalogItemRecord): string {
  return item.active
    ? t('admin.assistant.catalog.statusActive')
    : t('admin.assistant.catalog.statusInactive');
}

// --- assessment options (is_bookable, same tenant, no chains, excludes self) ---

function assessmentOptions(excludeId: number | null): CatalogItemRecord[] {
  return catalogItems.data.value.filter((item) => item.is_bookable && item.id !== excludeId);
}

// --- form drawer (shared create/edit) ----------------------------------------

const editingId = ref<number | null>(null);
const drawerOpen = ref(false);
const form = reactive<ItemForm>(defaultForm());

const editingItem = computed<CatalogItemRecord | null>(() => {
  if (editingId.value === null) {
    return null;
  }

  return catalogItems.data.value.find((item) => item.id === editingId.value) ?? null;
});

const drawerTitle = computed(() =>
  editingItem.value ? editingItem.value.name : t('admin.assistant.catalog.drawer.createTitle'),
);

const drawerAssessmentOptions = computed(() => assessmentOptions(editingId.value));

// A product never books, so it never needs duration/assessment; a service
// linked to an assessment doesn't book directly either (the agent offers
// the assessment instead), so its own duration is unused and hidden.
const showDurationField = computed(() => form.kind === 'service' && form.assessmentItemId === '');
const showAssessmentField = computed(() => form.kind === 'service');
const showSinglePrice = computed(() => form.priceType === 'fixed' || form.priceType === 'from');
const showPriceRange = computed(() => form.priceType === 'range');

watch(
  () => form.kind,
  (kind) => {
    if (kind === 'product') {
      form.durationMinutes = '';
      form.assessmentItemId = '';
    }
  },
);

const canSubmit = computed(() => {
  if (form.name.trim() === '') {
    return false;
  }

  if (form.kind === 'service' && form.assessmentItemId === '' && form.durationMinutes.trim() === '') {
    return false;
  }

  if (form.priceType === 'range' && (form.priceMin.trim() === '' || form.priceMax.trim() === '')) {
    return false;
  }

  return true;
});

function openCreateDrawer(): void {
  editingId.value = null;
  Object.assign(form, defaultForm());
  drawerOpen.value = true;
}

function openEditDrawer(item: CatalogItemRecord): void {
  editingId.value = item.id;
  Object.assign(form, formFromItem(item));
  drawerOpen.value = true;
}

function closeDrawer(): void {
  drawerOpen.value = false;
  editingId.value = null;
}

function onDrawerOpenChange(value: boolean): void {
  if (!value) {
    closeDrawer();
  }
}

function toNumber(value: string): number | undefined {
  const trimmed = value.trim();
  return trimmed === '' ? undefined : Number(trimmed);
}

function buildPayload() {
  const isService = form.kind === 'service';
  const hasAssessment = isService && form.assessmentItemId !== '';

  return {
    kind: form.kind,
    name: form.name.trim(),
    category: form.category.trim() || undefined,
    description: form.description.trim() || undefined,
    price_type: form.priceType,
    price_amount: showSinglePrice.value ? toNumber(form.priceAmount) : undefined,
    price_min: showPriceRange.value ? toNumber(form.priceMin) : undefined,
    price_max: showPriceRange.value ? toNumber(form.priceMax) : undefined,
    currency: form.currency.trim() || undefined,
    duration_minutes: isService && !hasAssessment ? toNumber(form.durationMinutes) : undefined,
    assessment_item_id: hasAssessment ? toNumber(form.assessmentItemId) : undefined,
    active: form.active,
  };
}

async function submitForm(): Promise<void> {
  const payload = buildPayload();

  const saved = editingId.value
    ? await catalogItems.update(editingId.value, payload, { successMessage: t('admin.success.catalogItemUpdated') })
    : await catalogItems.create(payload, { successMessage: t('admin.success.catalogItemCreated') });

  if (saved) {
    closeDrawer();
  }
}

// --- delete confirmation -----------------------------------------------------

const deleteConfirmOpen = ref(false);

function requestDelete(): void {
  deleteConfirmOpen.value = true;
}

function cancelDelete(): void {
  deleteConfirmOpen.value = false;
}

async function confirmDelete(): Promise<void> {
  const item = editingItem.value;

  if (!item) {
    deleteConfirmOpen.value = false;
    return;
  }

  const removed = await catalogItems.remove(item.id, { successMessage: t('admin.success.catalogItemDeleted') });

  deleteConfirmOpen.value = false;

  if (removed) {
    closeDrawer();
  }
}
</script>

<template>
  <div class="space-y-5">
    <PanelHeader
      group="admin.nav.assistant"
      panel="admin.assistant.catalog.title"
      description="admin.assistant.catalog.description"
    >
      <template #actions>
        <UiButton variant="primary" :disabled="!canManageAgentConfig" @click="openCreateDrawer">
          <template #icon>
            <Plus class="h-4 w-4" :stroke-width="2" aria-hidden="true" />
          </template>
          {{ t('admin.assistant.catalog.createAction') }}
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
        data-settings-key="catalog.panel"
        :columns="[
          { key: 'name', label: t('admin.assistant.catalog.columns.name') },
          { key: 'kind', label: t('admin.assistant.catalog.columns.kind') },
          { key: 'price', label: t('admin.assistant.catalog.columns.price') },
          { key: 'scheduling', label: t('admin.assistant.catalog.columns.scheduling') },
          { key: 'status', label: t('admin.assistant.catalog.columns.status') },
        ]"
      >
        <template #body>
          <tr v-if="adminOverview.catalog_items.length === 0">
            <td colspan="5" class="data-table-empty">{{ t('admin.assistant.catalog.empty') }}</td>
          </tr>
          <tr
            v-for="item in adminOverview.catalog_items"
            :key="item.id"
            class="catalog-row"
            @click="openEditDrawer(item)"
          >
            <td>
              <button type="button" class="catalog-name-btn" @click.stop="openEditDrawer(item)">
                {{ item.name }}
              </button>
            </td>
            <td>{{ kindLabel(item.kind) }}</td>
            <td>{{ item.price_label }}</td>
            <td>{{ schedulingLabel(item) }}</td>
            <td>
              <StatusBadge :label="statusLabel(item)" :tone="item.active ? 'success' : 'neutral'" />
            </td>
          </tr>
        </template>
      </DataTable>
    </template>

    <!-- Create/edit drawer -->
    <UiDrawer
      :open="drawerOpen"
      :title="drawerTitle"
      :close-label="t('common.close')"
      @update:open="onDrawerOpenChange"
    >
      <FormField :label="t('admin.assistant.catalog.drawer.kindLabel')" :hint="t('admin.assistant.catalog.drawer.kindHint')">
        <UiSelect v-model="form.kind" :disabled="saving">
          <option value="service">{{ kindLabel('service') }}</option>
          <option value="product">{{ kindLabel('product') }}</option>
        </UiSelect>
      </FormField>

      <FormField :label="t('admin.assistant.catalog.drawer.nameLabel')">
        <UiInput v-model="form.name" type="text" required :disabled="saving" />
      </FormField>

      <FormField
        :label="t('admin.assistant.catalog.drawer.categoryLabel')"
        :hint="t('admin.assistant.catalog.drawer.categoryHint')"
      >
        <UiInput v-model="form.category" type="text" :disabled="saving" />
      </FormField>

      <FormField
        :label="t('admin.assistant.catalog.drawer.descriptionLabel')"
        :hint="t('admin.assistant.catalog.drawer.descriptionHint')"
      >
        <UiTextarea v-model="form.description" :rows="3" :disabled="saving" />
      </FormField>

      <FormField :label="t('admin.assistant.catalog.drawer.priceTypeLabel')">
        <UiSelect v-model="form.priceType" :disabled="saving">
          <option value="fixed">{{ t('admin.assistant.catalog.priceTypeLabels.fixed') }}</option>
          <option value="from">{{ t('admin.assistant.catalog.priceTypeLabels.from') }}</option>
          <option value="range">{{ t('admin.assistant.catalog.priceTypeLabels.range') }}</option>
        </UiSelect>
      </FormField>

      <FormField
        v-if="showSinglePrice"
        :label="t('admin.assistant.catalog.drawer.priceAmountLabel')"
        :hint="t('admin.assistant.catalog.drawer.priceAmountHint')"
      >
        <UiInput v-model="form.priceAmount" type="number" min="0" step="0.01" :disabled="saving" />
      </FormField>

      <template v-if="showPriceRange">
        <FormField :label="t('admin.assistant.catalog.drawer.priceMinLabel')">
          <UiInput v-model="form.priceMin" type="number" min="0" step="0.01" :disabled="saving" />
        </FormField>
        <FormField :label="t('admin.assistant.catalog.drawer.priceMaxLabel')">
          <UiInput v-model="form.priceMax" type="number" min="0" step="0.01" :disabled="saving" />
        </FormField>
      </template>

      <FormField
        :label="t('admin.assistant.catalog.drawer.currencyLabel')"
        :hint="t('admin.assistant.catalog.drawer.currencyHint')"
      >
        <UiInput v-model="form.currency" type="text" maxlength="3" :disabled="saving" />
      </FormField>

      <FormField
        v-if="showDurationField"
        :label="t('admin.assistant.catalog.drawer.durationLabel')"
        :hint="t('admin.assistant.catalog.drawer.durationHint')"
      >
        <UiInput v-model="form.durationMinutes" type="number" min="1" :disabled="saving" />
      </FormField>

      <FormField
        v-if="showAssessmentField"
        :label="t('admin.assistant.catalog.drawer.assessmentLabel')"
        :hint="t('admin.assistant.catalog.drawer.assessmentHint')"
      >
        <UiSelect v-model="form.assessmentItemId" :disabled="saving">
          <option value="">{{ t('admin.assistant.catalog.drawer.assessmentNone') }}</option>
          <option v-for="option in drawerAssessmentOptions" :key="option.id" :value="String(option.id)">
            {{ option.name }} ({{ option.price_label }})
          </option>
        </UiSelect>
      </FormField>

      <UiSwitch
        v-model="form.active"
        :label="t('admin.assistant.catalog.drawer.activeLabel')"
        :disabled="saving"
      />
      <p class="text-small catalog-active-hint">{{ t('admin.assistant.catalog.drawer.activeHint') }}</p>

      <DangerZone
        v-if="editingItem"
        :description="t('admin.assistant.catalog.dangerZone.description', { name: editingItem.name })"
      >
        <UiButton variant="danger" size="sm" :disabled="!canManageAgentConfig || saving" @click="requestDelete">
          {{ t('admin.assistant.catalog.dangerZone.deleteAction') }}
        </UiButton>
      </DangerZone>

      <template #footer>
        <UiButton variant="secondary" :disabled="saving" @click="closeDrawer">
          {{ t('common.cancel') }}
        </UiButton>
        <UiButton variant="primary" :loading="saving" :disabled="!canSubmit || !canManageAgentConfig" @click="submitForm">
          {{ editingItem ? t('admin.assistant.catalog.drawer.saveSubmit') : t('admin.assistant.catalog.drawer.createSubmit') }}
        </UiButton>
      </template>
    </UiDrawer>

    <!-- Delete confirmation -->
    <UiModal
      :open="deleteConfirmOpen"
      :title="t('admin.assistant.catalog.deleteConfirm.title')"
      :message="
        editingItem
          ? t('admin.assistant.catalog.deleteConfirm.message', { name: editingItem.name })
          : ''
      "
      :confirm-label="t('admin.assistant.catalog.deleteConfirm.action')"
      :cancel-label="t('common.cancel')"
      danger
      :confirm-loading="saving"
      @confirm="confirmDelete"
      @cancel="cancelDelete"
    />
  </div>
</template>

<style scoped>
.catalog-row {
  cursor: pointer;
}

.catalog-name-btn {
  font-weight: 600;
  color: var(--text);
}

.catalog-name-btn:hover {
  color: var(--accent);
}

.catalog-active-hint {
  color: var(--text-mute);
  margin-top: -6px;
}
</style>
