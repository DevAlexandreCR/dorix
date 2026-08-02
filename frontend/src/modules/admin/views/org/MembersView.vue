<script setup lang="ts">
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import FormField from '../../../../components/ui/FormField.vue';
import InlineAlert from '../../../../components/ui/InlineAlert.vue';
import LoadingState from '../../../../components/ui/LoadingState.vue';
import StatusBadge from '../../../../components/ui/StatusBadge.vue';
import SurfaceCard from '../../../../components/ui/SurfaceCard.vue';
import { useNavigationAccess } from '../../../../composables/useNavigationAccess';
import { useTenantSelection } from '../../../../composables/useTenantSelection';
import {
  createTenantUser,
  deleteTenantUser,
  fetchAdminOverview,
  updateTenantUser,
} from '../../api';
import PanelHeader from '../../components/PanelHeader.vue';
import type { AdminOverview } from '../../types';

const { t } = useI18n();
const { selectedTenantId } = useTenantSelection();
const { selectedMembership } = useTenantSelection();
const { canManageTenantUsers, canAccessAdmin } = useNavigationAccess(selectedMembership);

const loading = ref(false);
const saving = ref(false);
const error = ref<string | null>(null);
const success = ref<string | null>(null);
const adminOverview = ref<AdminOverview | null>(null);

const tenantUserRoles = ref<Record<number, string>>({});
const newTenantUserForm = ref({
  name: '',
  email: '',
  password: '',
  role: 'operator',
});

function resolveErrorMessage(err: unknown, fallbackKey: string): string {
  return err instanceof Error && err.message !== '' ? err.message : t(fallbackKey);
}

function translateRole(role: string | null | undefined): string {
  return t(`common.roles.${role ?? 'unknown'}`);
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
    return;
  }

  loading.value = true;
  error.value = null;

  try {
    const payload = await fetchAdminOverview(selectedTenantId.value);
    adminOverview.value = payload.data;

    tenantUserRoles.value = Object.fromEntries(
      payload.data.tenant_users.map((membership) => [membership.id, membership.role ?? 'viewer']),
    );
  } catch (err) {
    error.value = resolveErrorMessage(err, 'admin.loadFailed');
  } finally {
    loading.value = false;
  }
}

async function addTenantUser(): Promise<void> {
  if (!selectedTenantId.value) {
    return;
  }

  const tenantId = selectedTenantId.value;

  await withAction(t('admin.success.tenantUserAdded'), async () => {
    await createTenantUser(tenantId, {
      name: newTenantUserForm.value.name.trim(),
      email: newTenantUserForm.value.email.trim(),
      password: newTenantUserForm.value.password.trim() || undefined,
      role: newTenantUserForm.value.role,
    });

    newTenantUserForm.value = {
      name: '',
      email: '',
      password: '',
      role: 'operator',
    };

    await loadData();
  });
}

async function saveTenantUserRole(tenantUserId: number): Promise<void> {
  if (!selectedTenantId.value) {
    return;
  }

  const tenantId = selectedTenantId.value;

  await withAction(t('admin.success.roleUpdated'), async () => {
    await updateTenantUser(tenantId, tenantUserId, {
      role: tenantUserRoles.value[tenantUserId] ?? 'viewer',
    });

    await loadData();
  });
}

async function removeTenantUser(tenantUserId: number): Promise<void> {
  if (!selectedTenantId.value) {
    return;
  }

  const tenantId = selectedTenantId.value;

  await withAction(t('admin.success.tenantUserRemoved'), async () => {
    await deleteTenantUser(tenantId, tenantUserId);
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
      panel="admin.org.members.title"
      description="admin.org.members.description"
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
        <div>
          <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-[var(--accent)]">{{ t('admin.tenantUsers.eyebrow') }}</p>
          <h3 class="mt-2 text-xl font-semibold">{{ t('admin.tenantUsers.title') }}</h3>
        </div>

        <div class="mt-6 grid gap-4">
          <article v-for="membership in adminOverview.tenant_users" :key="membership.id" class="rounded-lg border p-4" :style="{ borderColor: 'var(--border)' }">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
              <div>
                <strong>{{ membership.user?.name || t('admin.tenantUsers.noProfile') }}</strong>
                <p class="mt-1 text-sm text-[var(--text-mute)]">{{ membership.user?.email }}</p>
              </div>
              <StatusBadge :label="translateRole(membership.role)" tone="neutral" />
            </div>

            <div class="mt-4 grid gap-4 lg:grid-cols-[minmax(0,280px)_auto_auto] lg:items-end">
              <FormField :label="t('admin.tenantUsers.role')">
                <select v-model="tenantUserRoles[membership.id]" class="input-base" :disabled="!canManageTenantUsers || saving">
                  <option v-for="role in adminOverview.available_roles" :key="role" :value="role">
                    {{ translateRole(role) }}
                  </option>
                </select>
              </FormField>

              <button class="btn-secondary" type="button" :disabled="!canManageTenantUsers || saving" @click="saveTenantUserRole(membership.id)">
                {{ t('admin.tenantUsers.saveRole') }}
              </button>

              <button class="btn-danger" type="button" :disabled="!canManageTenantUsers || saving" @click="removeTenantUser(membership.id)">
                {{ t('admin.tenantUsers.remove') }}
              </button>
            </div>
          </article>
        </div>

        <form class="mt-8 rounded-lg border p-5" :style="{ borderColor: 'var(--border)' }" @submit.prevent="addTenantUser">
          <strong>{{ t('admin.tenantUsers.addTitle') }}</strong>

          <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <FormField :label="t('admin.tenant.name')">
              <input v-model="newTenantUserForm.name" class="input-base" type="text" :disabled="!canManageTenantUsers || saving" />
            </FormField>
            <FormField :label="t('auth.email')">
              <input v-model="newTenantUserForm.email" class="input-base" type="email" :disabled="!canManageTenantUsers || saving" />
            </FormField>
            <FormField :label="t('auth.password')">
              <input v-model="newTenantUserForm.password" class="input-base" type="password" :disabled="!canManageTenantUsers || saving" />
            </FormField>
            <FormField :label="t('admin.tenantUsers.role')">
              <select v-model="newTenantUserForm.role" class="input-base" :disabled="!canManageTenantUsers || saving">
                <option v-for="role in adminOverview.available_roles" :key="role" :value="role">
                  {{ translateRole(role) }}
                </option>
              </select>
            </FormField>
          </div>

          <button class="btn-primary mt-5 w-full justify-center md:w-auto" type="submit" :disabled="!canManageTenantUsers || saving">
            {{ t('admin.tenantUsers.addToTenant') }}
          </button>
        </form>
      </SurfaceCard>
    </template>
  </div>
</template>
