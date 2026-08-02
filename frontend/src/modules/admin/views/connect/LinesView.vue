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
  createWhatsAppLine,
  deleteWhatsAppLine,
  fetchAdminOverview,
  updateWhatsAppLine,
} from '../../api';
import PanelHeader from '../../components/PanelHeader.vue';
import type { AdminOverview, WhatsAppLineRecord } from '../../types';

type LineForm = {
  name: string;
  phoneNumberId: string;
  displayPhoneNumber: string;
  wabaId: string;
  status: string;
  isEnabled: boolean;
};

const { t } = useI18n();
const { selectedTenantId, selectedMembership } = useTenantSelection();
const { canManageTenant, canAccessAdmin } = useNavigationAccess(selectedMembership);

const loading = ref(false);
const saving = ref(false);
const error = ref<string | null>(null);
const success = ref<string | null>(null);
const adminOverview = ref<AdminOverview | null>(null);

const lineDrafts = ref<Record<number, LineForm>>({});
const newLineForm = ref<LineForm>(defaultLineForm());

function defaultLineForm(): LineForm {
  return {
    name: '',
    phoneNumberId: '',
    displayPhoneNumber: '',
    wabaId: '',
    status: 'inactive',
    isEnabled: false,
  };
}

function translateLineStatus(isEnabled: boolean): string {
  return t(`common.lineStatus.${isEnabled ? 'enabled' : 'disabled'}`);
}

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
    return;
  }

  loading.value = true;
  error.value = null;

  try {
    const payload = await fetchAdminOverview(selectedTenantId.value);
    adminOverview.value = payload.data;

    lineDrafts.value = Object.fromEntries(
      payload.data.whatsapp_lines.map((line: WhatsAppLineRecord) => [
        line.id,
        {
          name: line.name,
          phoneNumberId: line.phone_number_id,
          displayPhoneNumber: line.display_phone_number ?? '',
          wabaId: line.waba_id ?? '',
          status: line.status,
          isEnabled: line.is_enabled,
        },
      ]),
    );
  } catch (err) {
    error.value = resolveErrorMessage(err, 'admin.loadFailed');
  } finally {
    loading.value = false;
  }
}

async function createLine(): Promise<void> {
  if (!selectedTenantId.value) {
    return;
  }

  const tenantId = selectedTenantId.value;

  await withAction(t('admin.success.lineCreated'), async () => {
    await createWhatsAppLine(tenantId, {
      name: newLineForm.value.name.trim(),
      phone_number_id: newLineForm.value.phoneNumberId.trim(),
      display_phone_number: newLineForm.value.displayPhoneNumber.trim() || undefined,
      waba_id: newLineForm.value.wabaId.trim() || undefined,
      status: newLineForm.value.status.trim(),
      is_enabled: newLineForm.value.isEnabled,
    });

    newLineForm.value = defaultLineForm();
    await loadData();
  });
}

async function saveLine(lineId: number): Promise<void> {
  if (!selectedTenantId.value) {
    return;
  }

  const tenantId = selectedTenantId.value;
  const draft = lineDrafts.value[lineId];

  if (!draft) {
    return;
  }

  await withAction(t('admin.success.lineUpdated'), async () => {
    await updateWhatsAppLine(tenantId, lineId, {
      name: draft.name.trim(),
      phone_number_id: draft.phoneNumberId.trim(),
      display_phone_number: draft.displayPhoneNumber.trim() || undefined,
      waba_id: draft.wabaId.trim() || undefined,
      status: draft.status.trim(),
      is_enabled: draft.isEnabled,
    });

    await loadData();
  });
}

async function removeLine(lineId: number): Promise<void> {
  if (!selectedTenantId.value) {
    return;
  }

  const tenantId = selectedTenantId.value;

  await withAction(t('admin.success.lineDeleted'), async () => {
    await deleteWhatsAppLine(tenantId, lineId);
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
      group="admin.nav.connect"
      panel="admin.connect.lines.title"
      description="admin.connect.lines.description"
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
          <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-[var(--accent)]">{{ t('admin.lines.eyebrow') }}</p>
          <h3 class="mt-2 text-xl font-semibold">{{ t('admin.lines.title') }}</h3>
        </div>

        <div class="mt-6 grid gap-4">
          <article v-for="line in adminOverview.whatsapp_lines" :key="line.id" class="rounded-lg border p-4" :style="{ borderColor: 'var(--border)' }">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
              <div>
                <strong>{{ line.name }}</strong>
                <p class="mt-1 text-sm text-[var(--text-mute)]">{{ line.display_phone_number || line.phone_number_id }}</p>
              </div>
              <StatusBadge :label="translateLineStatus(line.is_enabled)" :status="line.is_enabled ? 'BOT_ACTIVE' : 'CLOSED'" />
            </div>

            <div v-if="lineDrafts[line.id]" class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
              <FormField :label="t('admin.tenant.name')">
                <input v-model="lineDrafts[line.id].name" class="input-base" type="text" :disabled="!canManageTenant || saving" />
              </FormField>
              <FormField :label="t('admin.lines.phoneNumberId')">
                <input v-model="lineDrafts[line.id].phoneNumberId" class="input-base" type="text" :disabled="!canManageTenant || saving" />
              </FormField>
              <FormField :label="t('admin.lines.displayPhone')">
                <input v-model="lineDrafts[line.id].displayPhoneNumber" class="input-base" type="text" :disabled="!canManageTenant || saving" />
              </FormField>
              <FormField :label="t('admin.lines.wabaId')">
                <input v-model="lineDrafts[line.id].wabaId" class="input-base" type="text" :disabled="!canManageTenant || saving" />
              </FormField>
              <FormField :label="t('admin.tenant.status')">
                <input v-model="lineDrafts[line.id].status" class="input-base" type="text" :disabled="!canManageTenant || saving" />
              </FormField>
              <label class="flex items-end gap-3 rounded-md border px-4 py-3 text-sm" :style="{ borderColor: 'var(--border)' }">
                <input v-model="lineDrafts[line.id].isEnabled" type="checkbox" class="h-4 w-4" :disabled="!canManageTenant || saving" />
                <span>{{ t('admin.lines.automationEnabled') }}</span>
              </label>
            </div>

            <div class="mt-5 flex flex-wrap gap-3">
              <button class="btn-secondary" type="button" :disabled="!canManageTenant || saving" @click="saveLine(line.id)">
                {{ t('admin.lines.save') }}
              </button>
              <button class="btn-danger" type="button" :disabled="!canManageTenant || saving" @click="removeLine(line.id)">
                {{ t('admin.lines.delete') }}
              </button>
            </div>
          </article>
        </div>

        <form class="mt-8 rounded-lg border p-5" :style="{ borderColor: 'var(--border)' }" @submit.prevent="createLine">
          <strong>{{ t('admin.lines.createTitle') }}</strong>
          <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <FormField :label="t('admin.tenant.name')">
              <input v-model="newLineForm.name" class="input-base" type="text" :disabled="!canManageTenant || saving" />
            </FormField>
            <FormField :label="t('admin.lines.phoneNumberId')">
              <input v-model="newLineForm.phoneNumberId" class="input-base" type="text" :disabled="!canManageTenant || saving" />
            </FormField>
            <FormField :label="t('admin.lines.displayPhone')">
              <input v-model="newLineForm.displayPhoneNumber" class="input-base" type="text" :disabled="!canManageTenant || saving" />
            </FormField>
            <FormField :label="t('admin.lines.wabaId')">
              <input v-model="newLineForm.wabaId" class="input-base" type="text" :disabled="!canManageTenant || saving" />
            </FormField>
            <FormField :label="t('admin.tenant.status')">
              <input v-model="newLineForm.status" class="input-base" type="text" :disabled="!canManageTenant || saving" />
            </FormField>
            <label class="flex items-end gap-3 rounded-md border px-4 py-3 text-sm" :style="{ borderColor: 'var(--border)' }">
              <input v-model="newLineForm.isEnabled" type="checkbox" class="h-4 w-4" :disabled="!canManageTenant || saving" />
              <span>{{ t('admin.lines.enabled') }}</span>
            </label>
          </div>

          <button class="btn-primary mt-5 w-full justify-center md:w-auto" type="submit" :disabled="!canManageTenant || saving">
            {{ t('admin.lines.create') }}
          </button>
        </form>
      </SurfaceCard>
    </template>
  </div>
</template>
