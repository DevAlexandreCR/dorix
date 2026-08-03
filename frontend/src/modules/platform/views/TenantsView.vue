<script setup lang="ts">
import { Plus } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import DangerZone from '../../../components/ui/DangerZone.vue';
import DataTable from '../../../components/ui/DataTable.vue';
import FormField from '../../../components/ui/FormField.vue';
import InlineAlert from '../../../components/ui/InlineAlert.vue';
import LoadingState from '../../../components/ui/LoadingState.vue';
import SearchInput from '../../../components/ui/SearchInput.vue';
import StatusBadge from '../../../components/ui/StatusBadge.vue';
import SurfaceCard from '../../../components/ui/SurfaceCard.vue';
import TechValue from '../../../components/ui/TechValue.vue';
import UiButton from '../../../components/ui/UiButton.vue';
import UiDrawer from '../../../components/ui/UiDrawer.vue';
import UiInput from '../../../components/ui/UiInput.vue';
import UiModal from '../../../components/ui/UiModal.vue';
import UiSelect from '../../../components/ui/UiSelect.vue';
// Generic loading/error/success + toast wiring (design.md decision 5),
// reused cross-module: this screen lists ALL tenants, so unlike every
// tenant-scoped admin screen there is no single "overview" resource to
// hang a `useAdminResource` collection off of. Borrowing just the
// feedback primitive (not the whole resource cache) mirrors the
// cross-module reuse precedent CredentialsView (task 5.3) set one layer
// up with `useAdminResource` itself.
import { useAdminFeedback } from '../../admin/composables/useAdminFeedback';
import { createTenant, fetchAdminTenants, updateTenant } from '../api';
import PlatformPanelHeader from '../components/PlatformPanelHeader.vue';
import type { TenantRecord } from '../types';

// design/06 "/platform/tenants": DataTable over ONLY the fields
// `GET /admin/tenants` returns (`AdminPanelDataBuilder::serializeTenant`:
// id, name, slug, status, metadata, created_at, updated_at) — no line/
// member counts (design.md decision 10, backend not touched). Search and
// status filter are client-side over the already-fetched list (the list
// is small — one row per tenant on the whole platform).
//
// Status is never a free-text/select field (design.md decision 13): the
// only way to change it is the DangerZone Pausar/Reactivar action, always
// confirmed via UiModal, exactly like `admin/views/org/InfoView.vue`'s
// tenant-scoped sibling. `TenantStatus` (backend/app/Enums/TenantStatus.php)
// now genuinely enforces `'active' | 'paused'` — those are the only two
// literal strings this screen ever sends as `status`.

const { t, locale } = useI18n();
const router = useRouter();

const listFeedback = useAdminFeedback();
const actionFeedback = useAdminFeedback();

const tenants = ref<TenantRecord[]>([]);
const loaded = ref(false);

async function loadTenants(): Promise<void> {
  const result = await listFeedback.run(async () => {
    const response = await fetchAdminTenants();
    return response.data;
  });

  if (result) {
    tenants.value = result;
    loaded.value = true;
  }
}

onMounted(loadTenants);

// --- search + status filter (client-side) -----------------------------------

const searchQuery = ref('');
const statusFilter = ref<'all' | 'active' | 'paused'>('all');

const filteredTenants = computed(() => {
  const query = searchQuery.value.trim().toLowerCase();

  return tenants.value.filter((tenant) => {
    if (statusFilter.value !== 'all' && tenant.status !== statusFilter.value) {
      return false;
    }

    if (query === '') {
      return true;
    }

    return tenant.name.toLowerCase().includes(query) || tenant.slug.toLowerCase().includes(query);
  });
});

function statusLabel(status: string): string {
  return status === 'active' ? t('platform.tenants.statusActive') : t('platform.tenants.statusPaused');
}

function statusTone(status: string): 'success' | 'warning' {
  return status === 'active' ? 'success' : 'warning';
}

function formatDate(value: string | null): string {
  if (!value) {
    return t('common.noDate');
  }

  return new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium' }).format(new Date(value));
}

// --- row highlight on create ("fila resaltada" per the ui-platform-admin spec) ---

const highlightedTenantId = ref<number | null>(null);

function highlightTenant(tenantId: number): void {
  highlightedTenantId.value = null;
  requestAnimationFrame(() => {
    highlightedTenantId.value = tenantId;
  });
}

function clearHighlight(tenantId: number): void {
  if (highlightedTenantId.value === tenantId) {
    highlightedTenantId.value = null;
  }
}

// --- detail drawer -----------------------------------------------------------

const detailTenantId = ref<number | null>(null);
const detailOpen = computed(() => detailTenantId.value !== null);

const detailTenant = computed<TenantRecord | null>(() => {
  if (detailTenantId.value === null) {
    return null;
  }

  return tenants.value.find((tenant) => tenant.id === detailTenantId.value) ?? null;
});

const detailIsPaused = computed(() => detailTenant.value?.status !== 'active');

function openDetail(tenantId: number): void {
  detailTenantId.value = tenantId;
}

function closeDetail(): void {
  detailTenantId.value = null;
}

function onDetailOpenChange(value: boolean): void {
  if (!value) {
    closeDetail();
  }
}

// Always available, never gated: platform admins have synthetic membership
// in every tenant (`AuthSessionController::buildSessionPayload` maps every
// `Tenant` to a membership when `isPlatformAdmin()`), so there is nothing
// to check beyond the route guard that already gates all of `/platform/**`.
function enterAsAdmin(): void {
  const tenant = detailTenant.value;

  if (!tenant) {
    return;
  }

  router.push({ path: '/admin/org/info', query: { tenant: String(tenant.id) } });
}

// --- pause/reactivate (decision 13: DangerZone is the only way to change status) ---

const statusConfirmOpen = ref(false);

function requestStatusToggle(): void {
  statusConfirmOpen.value = true;
}

function cancelStatusToggle(): void {
  statusConfirmOpen.value = false;
}

async function confirmStatusToggle(): Promise<void> {
  const tenant = detailTenant.value;

  if (!tenant) {
    statusConfirmOpen.value = false;
    return;
  }

  const wasPaused = tenant.status !== 'active';
  const nextStatus = wasPaused ? 'active' : 'paused';

  const updated = await actionFeedback.run(
    async () => {
      const response = await updateTenant(tenant.id, {
        name: tenant.name,
        slug: tenant.slug,
        status: nextStatus,
      });
      return response.data;
    },
    {
      successMessage: wasPaused
        ? t('platform.tenants.success.reactivated')
        : t('platform.tenants.success.paused'),
    },
  );

  statusConfirmOpen.value = false;

  if (updated) {
    const index = tenants.value.findIndex((candidate) => candidate.id === updated.id);

    if (index !== -1) {
      tenants.value.splice(index, 1, updated);
    }
  }
}

// --- create drawer -------------------------------------------------------------
// "Nombre + slug autogenerado editable + estado inicial" (design/06). The
// slug tracks the name field until the user types into the slug field
// directly (`slugTouched`), same "auto until touched" idea `BehaviorView`'s
// ScopePicker dirty-state tracking already uses elsewhere in this module,
// applied here to a plain text derivation instead.

type CreateForm = {
  name: string;
  slug: string;
  status: 'active' | 'paused';
};

function defaultCreateForm(): CreateForm {
  return { name: '', slug: '', status: 'active' };
}

const createOpen = ref(false);
const createForm = reactive<CreateForm>(defaultCreateForm());
const slugTouched = ref(false);

const DIACRITICS_PATTERN = new RegExp('[\\u0300-\\u036f]', 'g');

function slugify(value: string): string {
  return value
    .toLowerCase()
    .normalize('NFD')
    .replace(DIACRITICS_PATTERN, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
}

watch(
  () => createForm.name,
  (name) => {
    if (!slugTouched.value) {
      createForm.slug = slugify(name);
    }
  },
);

function onSlugInput(): void {
  slugTouched.value = true;
}

function openCreateDrawer(): void {
  Object.assign(createForm, defaultCreateForm());
  slugTouched.value = false;
  createOpen.value = true;
}

function closeCreateDrawer(): void {
  createOpen.value = false;
}

function onCreateOpenChange(value: boolean): void {
  if (!value) {
    closeCreateDrawer();
  }
}

const canSubmitCreate = computed(() => createForm.name.trim() !== '' && createForm.slug.trim() !== '');

async function submitCreate(): Promise<void> {
  const created = await actionFeedback.run(
    async () => {
      const response = await createTenant({
        name: createForm.name.trim(),
        slug: createForm.slug.trim(),
        status: createForm.status,
      });
      return response.data;
    },
    { successMessage: t('platform.tenants.success.created') },
  );

  // Spec scenario: toast + the new row highlighted in the table, without
  // changing the platform admin's own active tenant anywhere else in the
  // app (nothing here touches useTenantSelection/useSession).
  if (created) {
    tenants.value = [...tenants.value, created];
    closeCreateDrawer();
    highlightTenant(created.id);
  }
}
</script>

<template>
  <div class="space-y-5">
    <PlatformPanelHeader panel="platform.nav.tenants" description="platform.tenants.description">
      <template #actions>
        <UiButton variant="primary" @click="openCreateDrawer">
          <template #icon>
            <Plus class="h-4 w-4" :stroke-width="2" aria-hidden="true" />
          </template>
          {{ t('platform.tenants.createAction') }}
        </UiButton>
      </template>
    </PlatformPanelHeader>

    <div v-if="listFeedback.loading.value && !loaded">
      <SurfaceCard>
        <LoadingState :label="t('admin.loading')" />
      </SurfaceCard>
    </div>

    <template v-else>
      <div class="grid gap-3">
        <InlineAlert v-if="listFeedback.error.value" :message="listFeedback.error.value" tone="danger" />
        <InlineAlert v-if="actionFeedback.error.value" :message="actionFeedback.error.value" tone="danger" />
        <InlineAlert v-if="actionFeedback.success.value" :message="actionFeedback.success.value" tone="success" />
      </div>

      <div class="tenants-filters">
        <SearchInput
          v-model="searchQuery"
          class="tenants-search"
          :placeholder="t('platform.tenants.searchPlaceholder')"
          :aria-label="t('platform.tenants.searchPlaceholder')"
          :clear-label="t('common.dismiss')"
        />
        <UiSelect
          v-model="statusFilter"
          class="tenants-status-filter"
          :aria-label="t('platform.tenants.filters.statusLabel')"
        >
          <option value="all">{{ t('platform.tenants.filters.allStatuses') }}</option>
          <option value="active">{{ t('platform.tenants.statusActive') }}</option>
          <option value="paused">{{ t('platform.tenants.statusPaused') }}</option>
        </UiSelect>
      </div>

      <DataTable
        :columns="[
          { key: 'name', label: t('platform.tenants.columns.name') },
          { key: 'slug', label: t('platform.tenants.columns.slug') },
          { key: 'status', label: t('platform.tenants.columns.status') },
          { key: 'created', label: t('platform.tenants.columns.created') },
        ]"
      >
        <template #body>
          <tr v-if="filteredTenants.length === 0">
            <td colspan="4" class="data-table-empty">{{ t('platform.tenants.empty') }}</td>
          </tr>
          <tr
            v-for="tenant in filteredTenants"
            :key="tenant.id"
            class="tenant-row"
            :class="{ 'tenant-row--flash': highlightedTenantId === tenant.id }"
            @click="openDetail(tenant.id)"
            @animationend="clearHighlight(tenant.id)"
          >
            <td>
              <button type="button" class="tenant-name-btn" @click.stop="openDetail(tenant.id)">
                {{ tenant.name }}
              </button>
            </td>
            <td><TechValue :value="tenant.slug" /></td>
            <td>
              <StatusBadge :label="statusLabel(tenant.status)" :tone="statusTone(tenant.status)" />
            </td>
            <td>{{ formatDate(tenant.created_at) }}</td>
          </tr>
        </template>
      </DataTable>
    </template>

    <!-- Detail drawer -->
    <UiDrawer
      :open="detailOpen"
      :title="detailTenant?.name ?? ''"
      :close-label="t('common.close')"
      @update:open="onDetailOpenChange"
    >
      <template v-if="detailTenant">
        <div class="tenant-detail-summary">
          <StatusBadge :label="statusLabel(detailTenant.status)" :tone="statusTone(detailTenant.status)" />
        </div>

        <section class="grid gap-3">
          <FormField :label="t('platform.tenants.detail.slugLabel')">
            <TechValue :value="detailTenant.slug" />
          </FormField>
          <FormField :label="t('platform.tenants.detail.createdLabel')">
            <span class="text-body">{{ formatDate(detailTenant.created_at) }}</span>
          </FormField>
        </section>

        <UiButton variant="secondary" size="sm" class="justify-self-start" @click="enterAsAdmin">
          {{ t('platform.tenants.detail.enterAsAdminAction') }}
        </UiButton>

        <DangerZone :description="t('platform.tenants.dangerZone.description')">
          <UiButton
            :variant="detailIsPaused ? 'secondary' : 'danger'"
            size="sm"
            :disabled="actionFeedback.loading.value"
            @click="requestStatusToggle"
          >
            {{
              detailIsPaused
                ? t('platform.tenants.dangerZone.reactivateAction')
                : t('platform.tenants.dangerZone.pauseAction')
            }}
          </UiButton>
        </DangerZone>
      </template>
    </UiDrawer>

    <!-- Create drawer -->
    <UiDrawer
      :open="createOpen"
      :title="t('platform.tenants.createDrawer.title')"
      :close-label="t('common.close')"
      @update:open="onCreateOpenChange"
    >
      <FormField :label="t('platform.tenants.createDrawer.nameLabel')">
        <UiInput v-model="createForm.name" type="text" required :disabled="actionFeedback.loading.value" />
      </FormField>
      <FormField
        :label="t('platform.tenants.createDrawer.slugLabel')"
        :hint="t('platform.tenants.createDrawer.slugHint')"
      >
        <UiInput
          v-model="createForm.slug"
          type="text"
          required
          :disabled="actionFeedback.loading.value"
          @input="onSlugInput"
        />
      </FormField>
      <FormField :label="t('platform.tenants.createDrawer.statusLabel')">
        <UiSelect v-model="createForm.status" :disabled="actionFeedback.loading.value">
          <option value="active">{{ t('platform.tenants.statusActive') }}</option>
          <option value="paused">{{ t('platform.tenants.statusPaused') }}</option>
        </UiSelect>
      </FormField>

      <template #footer>
        <UiButton variant="secondary" :disabled="actionFeedback.loading.value" @click="closeCreateDrawer">
          {{ t('common.cancel') }}
        </UiButton>
        <UiButton
          variant="primary"
          :loading="actionFeedback.loading.value"
          :disabled="!canSubmitCreate"
          @click="submitCreate"
        >
          {{ t('platform.tenants.createDrawer.submitAction') }}
        </UiButton>
      </template>
    </UiDrawer>

    <!-- Pause/Reactivate confirmation -->
    <UiModal
      :open="statusConfirmOpen"
      :title="
        detailIsPaused ? t('platform.tenants.reactivateConfirm.title') : t('platform.tenants.pauseConfirm.title')
      "
      :message="
        detailTenant
          ? detailIsPaused
            ? t('platform.tenants.reactivateConfirm.message', { name: detailTenant.name })
            : t('platform.tenants.pauseConfirm.message', { name: detailTenant.name })
          : ''
      "
      :confirm-label="
        detailIsPaused ? t('platform.tenants.reactivateConfirm.action') : t('platform.tenants.pauseConfirm.action')
      "
      :cancel-label="t('common.cancel')"
      :danger="!detailIsPaused"
      :confirm-loading="actionFeedback.loading.value"
      @confirm="confirmStatusToggle"
      @cancel="cancelStatusToggle"
    />
  </div>
</template>

<style scoped>
.tenants-filters {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.tenants-search {
  flex: 1;
  min-width: 220px;
  max-width: 360px;
}

.tenants-status-filter {
  max-width: 180px;
}

.tenant-row {
  cursor: pointer;
}

.tenant-name-btn {
  font-weight: 600;
  color: var(--text);
}

.tenant-name-btn:hover {
  color: var(--accent);
}

.tenant-row--flash {
  animation: tenant-row-flash 1200ms ease-out;
}

@keyframes tenant-row-flash {
  0% {
    background: color-mix(in srgb, var(--accent) 18%, transparent);
  }
  100% {
    background: transparent;
  }
}

.tenant-detail-summary {
  display: flex;
  align-items: center;
  gap: var(--space-3);
}
</style>
