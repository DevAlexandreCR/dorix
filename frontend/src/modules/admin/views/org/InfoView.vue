<script setup lang="ts">
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import FormField from '../../../../components/ui/FormField.vue';
import InlineAlert from '../../../../components/ui/InlineAlert.vue';
import LoadingState from '../../../../components/ui/LoadingState.vue';
import StatusBadge from '../../../../components/ui/StatusBadge.vue';
import SurfaceCard from '../../../../components/ui/SurfaceCard.vue';
import { useNavigationAccess } from '../../../../composables/useNavigationAccess';
import { useSession } from '../../../../composables/useSession';
import { useTenantSelection } from '../../../../composables/useTenantSelection';
import {
  createTenant,
  fetchAdminOverview,
  fetchAdminTenants,
  updateTenant,
} from '../../api';
import PanelHeader from '../../components/PanelHeader.vue';
import type { AdminOverview, TenantRecord } from '../../types';

const { t } = useI18n();
const { refreshSession } = useSession();
const { selectedTenantId } = useTenantSelection();
const { selectedMembership } = useTenantSelection();
const { canManageTenant, canManagePlatform, canAccessAdmin } = useNavigationAccess(selectedMembership);

const loading = ref(false);
const saving = ref(false);
const error = ref<string | null>(null);
const success = ref<string | null>(null);
const adminOverview = ref<AdminOverview | null>(null);
const adminTenants = ref<TenantRecord[]>([]);

const tenantForm = ref({
  name: '',
  slug: '',
  status: 'active',
});

const createTenantForm = ref({
  name: '',
  slug: '',
  status: 'active',
});

function resolveErrorMessage(err: unknown, fallbackKey: string): string {
  return err instanceof Error && err.message !== '' ? err.message : t(fallbackKey);
}

async function withAction(successMessage: string, action: () => Promise<void>): Promise<void> {
  saving.value = true;
  error.value = null;
  success.value = null;

  try {
    await action();
    success.value = successMessage;
  } catch (err) {
    error.value = resolveErrorMessage(err, 'admin.actionFailed');
  } finally {
    saving.value = false;
  }
}

async function loadData(): Promise<void> {
  if (!selectedTenantId.value || !canAccessAdmin.value) {
    adminOverview.value = null;
    adminTenants.value = [];
    return;
  }

  loading.value = true;
  error.value = null;

  try {
    const payload = await fetchAdminOverview(selectedTenantId.value);
    adminOverview.value = payload.data;

    tenantForm.value = {
      name: payload.data.tenant.name,
      slug: payload.data.tenant.slug,
      status: payload.data.tenant.status,
    };

    adminTenants.value = canManagePlatform.value ? (await fetchAdminTenants()).data : [];
  } catch (err) {
    error.value = resolveErrorMessage(err, 'admin.loadFailed');
  } finally {
    loading.value = false;
  }
}

async function saveTenantSettings(): Promise<void> {
  if (!selectedTenantId.value) {
    return;
  }

  const tenantId = selectedTenantId.value;

  await withAction(t('admin.success.tenantUpdated'), async () => {
    await updateTenant(tenantId, {
      name: tenantForm.value.name.trim(),
      slug: tenantForm.value.slug.trim(),
      status: tenantForm.value.status.trim(),
    });

    await refreshSession();
    await loadData();
  });
}

async function createNewTenant(): Promise<void> {
  await withAction(t('admin.success.tenantCreated'), async () => {
    await createTenant({
      name: createTenantForm.value.name.trim(),
      slug: createTenantForm.value.slug.trim(),
      status: createTenantForm.value.status.trim(),
    });

    createTenantForm.value = {
      name: '',
      slug: '',
      status: 'active',
    };

    await refreshSession();
    await loadData();
  });
}

watch(
  [selectedTenantId, () => canAccessAdmin.value],
  async () => {
    success.value = null;
    error.value = null;
    await loadData();
  },
  { immediate: true },
);
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

      <SurfaceCard padding="lg">
        <div class="flex items-start justify-between gap-3">
          <div>
            <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-[var(--accent)]">{{ t('admin.tenant.eyebrow') }}</p>
            <h3 class="mt-2 text-xl font-semibold">{{ t('admin.tenant.title') }}</h3>
          </div>
          <StatusBadge :label="adminOverview.tenant.slug" tone="neutral" />
        </div>

        <form class="mt-6 grid gap-4" @submit.prevent="saveTenantSettings">
          <div class="grid gap-4 md:grid-cols-3">
            <FormField :label="t('admin.tenant.name')">
              <input v-model="tenantForm.name" class="input-base" type="text" :disabled="!canManageTenant || saving" />
            </FormField>
            <FormField :label="t('admin.tenant.slug')">
              <input v-model="tenantForm.slug" class="input-base" type="text" :disabled="!canManageTenant || saving" />
            </FormField>
            <FormField :label="t('admin.tenant.status')">
              <input v-model="tenantForm.status" class="input-base" type="text" :disabled="!canManageTenant || saving" />
            </FormField>
          </div>

          <button class="btn-primary w-full justify-center md:w-auto" type="submit" :disabled="!canManageTenant || saving">
            {{ t('admin.tenant.save') }}
          </button>
        </form>

        <div v-if="canManagePlatform" class="mt-8 rounded-lg border p-5" :style="{ borderColor: 'var(--border)' }">
          <div class="flex items-center justify-between gap-3">
            <strong>{{ t('admin.tenant.createTitle') }}</strong>
            <span class="text-sm text-[var(--text-mute)]">{{ t('admin.tenant.visible', { count: adminTenants.length }) }}</span>
          </div>

          <form class="mt-5 grid gap-4" @submit.prevent="createNewTenant">
            <div class="grid gap-4 md:grid-cols-3">
              <FormField :label="t('admin.tenant.name')">
                <input v-model="createTenantForm.name" class="input-base" type="text" :disabled="saving" />
              </FormField>
              <FormField :label="t('admin.tenant.slug')">
                <input v-model="createTenantForm.slug" class="input-base" type="text" :disabled="saving" />
              </FormField>
              <FormField :label="t('admin.tenant.status')">
                <input v-model="createTenantForm.status" class="input-base" type="text" :disabled="saving" />
              </FormField>
            </div>

            <button class="btn-secondary w-full justify-center md:w-auto" type="submit" :disabled="saving">
              {{ t('admin.tenant.create') }}
            </button>
          </form>
        </div>
      </SurfaceCard>
    </template>
  </div>
</template>
