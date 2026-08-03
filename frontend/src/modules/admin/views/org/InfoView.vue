<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import DangerZone from '../../../../components/ui/DangerZone.vue';
import FormField from '../../../../components/ui/FormField.vue';
import InlineAlert from '../../../../components/ui/InlineAlert.vue';
import LoadingState from '../../../../components/ui/LoadingState.vue';
import StatusBadge from '../../../../components/ui/StatusBadge.vue';
import SummaryCard from '../../../../components/ui/SummaryCard.vue';
import SurfaceCard from '../../../../components/ui/SurfaceCard.vue';
import TechValue from '../../../../components/ui/TechValue.vue';
import UiButton from '../../../../components/ui/UiButton.vue';
import UiInput from '../../../../components/ui/UiInput.vue';
import UiModal from '../../../../components/ui/UiModal.vue';
import { useNavigationAccess } from '../../../../composables/useNavigationAccess';
import { useSession } from '../../../../composables/useSession';
import { useTenantSelection } from '../../../../composables/useTenantSelection';
import PanelHeader from '../../components/PanelHeader.vue';
import { useAdminResource } from '../../composables/useAdminResource';

// `status` has no backend enum (backend/app/Models/Tenant.php: plain string
// column, default 'active'; UpsertTenantRequest only validates
// `string|max:40`) — same situation as WhatsAppLine.status (see LinesView).
// design.md decision 13 / the ui-admin spec's "Estados nunca como texto
// libre" requirement still applies: the UI treats it as a two-state toggle
// (anything other than 'active' reads as paused), shown as a read-only
// badge here and changed only through the DangerZone action below, never a
// free-text field. Creating tenants is a platform-level action
// (`/platform/tenants`, phase 5) and does not belong on this screen.

const { t } = useI18n();
const { refreshSession } = useSession();
const { selectedMembership } = useTenantSelection();
const { canManageTenant } = useNavigationAccess(selectedMembership);

const { overview: adminOverview, overviewLoading: loading, tenant } = useAdminResource();
const { loading: saving, error, success } = tenant;

const cardOpen = ref(false);
const cardRef = ref<InstanceType<typeof SummaryCard> | null>(null);
const nameForm = ref({ name: '' });

watch(
  () => tenant.data.value,
  (record) => {
    if (!record) {
      return;
    }

    nameForm.value.name = record.name;
  },
  { immediate: true },
);

const isPaused = computed(() => (tenant.data.value?.status ?? 'active') !== 'active');
const statusLabel = computed(() =>
  isPaused.value ? t('admin.org.info.statusPaused') : t('admin.org.info.statusActive'),
);
const statusTone = computed<'success' | 'warning'>(() => (isPaused.value ? 'warning' : 'success'));

function openCard(): void {
  const record = tenant.data.value;

  if (record) {
    nameForm.value.name = record.name;
  }

  cardOpen.value = true;
}

function cancelCard(): void {
  cardOpen.value = false;

  const record = tenant.data.value;

  if (record) {
    nameForm.value.name = record.name;
  }
}

async function saveCard(): Promise<void> {
  const record = tenant.data.value;

  if (!record) {
    return;
  }

  const updated = await tenant.update(
    {
      name: nameForm.value.name.trim(),
      slug: record.slug,
      status: record.status,
    },
    { successMessage: t('admin.success.tenantUpdated') },
  );

  if (updated) {
    cardOpen.value = false;
    cardRef.value?.flash();
    await refreshSession();
  }
}

// --- status toggle (DangerZone) ---------------------------------------------
// Decision 13: Pausar/Reactivar is the ONLY way to change status; it always
// confirms via UiModal, in both directions.

const statusConfirmOpen = ref(false);

function requestStatusToggle(): void {
  statusConfirmOpen.value = true;
}

function cancelStatusToggle(): void {
  statusConfirmOpen.value = false;
}

async function confirmStatusToggle(): Promise<void> {
  const record = tenant.data.value;

  if (!record) {
    statusConfirmOpen.value = false;
    return;
  }

  const nextStatus = isPaused.value ? 'active' : 'paused';

  const updated = await tenant.update(
    { name: record.name, slug: record.slug, status: nextStatus },
    {
      successMessage: isPaused.value
        ? t('admin.success.tenantReactivated')
        : t('admin.success.tenantPaused'),
    },
  );

  statusConfirmOpen.value = false;

  if (updated) {
    await refreshSession();
  }
}
</script>

<template>
  <div class="space-y-5">
    <PanelHeader
      group="admin.nav.org"
      panel="admin.org.info.title"
      description="admin.org.info.description"
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

      <div class="flex max-w-[720px] flex-col gap-3">
        <SummaryCard
          ref="cardRef"
          data-settings-key="org.info.card"
          :open="cardOpen"
          :title="t('admin.org.info.card.title')"
          :edit-label="t('common.edit')"
          :cancel-label="t('common.cancel')"
          :save-label="t('admin.org.info.save')"
          :saving="saving"
          :disabled="!canManageTenant"
          @update:open="(value) => value && openCard()"
          @cancel="cancelCard"
          @save="saveCard"
        >
          <template #state>
            <div class="info-summary-state">
              <div class="info-summary-name-row">
                <span>{{ adminOverview.tenant.name }}</span>
                <StatusBadge :label="statusLabel" :tone="statusTone" />
              </div>
              <div class="info-summary-slug-row text-small">
                <span>{{ t('admin.org.info.identifierLabel') }}:</span>
                <TechValue :value="adminOverview.tenant.slug" />
              </div>
            </div>
          </template>

          <FormField :label="t('admin.org.info.nameLabel')">
            <UiInput v-model="nameForm.name" type="text" required :disabled="!canManageTenant || saving" />
          </FormField>
          <FormField :label="t('admin.org.info.identifierLabel')" :hint="t('admin.org.info.identifierHint')">
            <TechValue :value="adminOverview.tenant.slug" />
          </FormField>
        </SummaryCard>
      </div>

      <DangerZone
        data-settings-key="org.info.dangerZone"
        :description="t('admin.org.info.dangerZone.description')"
      >
        <UiButton
          :variant="isPaused ? 'secondary' : 'danger'"
          size="sm"
          :disabled="!canManageTenant || saving"
          @click="requestStatusToggle"
        >
          {{
            isPaused
              ? t('admin.org.info.dangerZone.reactivateAction')
              : t('admin.org.info.dangerZone.pauseAction')
          }}
        </UiButton>
      </DangerZone>
    </template>

    <UiModal
      :open="statusConfirmOpen"
      :title="
        isPaused ? t('admin.org.info.reactivateConfirm.title') : t('admin.org.info.pauseConfirm.title')
      "
      :message="
        isPaused
          ? t('admin.org.info.reactivateConfirm.message')
          : t('admin.org.info.pauseConfirm.message')
      "
      :confirm-label="
        isPaused ? t('admin.org.info.reactivateConfirm.action') : t('admin.org.info.pauseConfirm.action')
      "
      :cancel-label="t('common.cancel')"
      :danger="!isPaused"
      :confirm-loading="saving"
      @confirm="confirmStatusToggle"
      @cancel="cancelStatusToggle"
    />
  </div>
</template>

<style scoped>
.info-summary-state {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.info-summary-name-row {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.info-summary-slug-row {
  display: flex;
  align-items: center;
  gap: 6px;
  color: var(--text-mute);
  font-weight: 450;
}
</style>
