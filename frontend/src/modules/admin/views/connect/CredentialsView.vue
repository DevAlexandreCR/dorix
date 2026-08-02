<script setup lang="ts">
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import ForbiddenState from '../../../../components/ui/ForbiddenState.vue';
import FormField from '../../../../components/ui/FormField.vue';
import InlineAlert from '../../../../components/ui/InlineAlert.vue';
import LoadingState from '../../../../components/ui/LoadingState.vue';
import StatusBadge from '../../../../components/ui/StatusBadge.vue';
import SurfaceCard from '../../../../components/ui/SurfaceCard.vue';
import { useNavigationAccess } from '../../../../composables/useNavigationAccess';
import { useTenantSelection } from '../../../../composables/useTenantSelection';
import { fetchAdminOverview, upsertCredential } from '../../api';
import PanelHeader from '../../components/PanelHeader.vue';
import type { AdminOverview, WhatsAppLineRecord } from '../../types';

const { t, locale } = useI18n();
const { selectedTenantId, selectedMembership } = useTenantSelection();
const { canViewCredentialMetadata, canManagePlatform, canAccessAdmin } =
  useNavigationAccess(selectedMembership);

const loading = ref(false);
const saving = ref(false);
const error = ref<string | null>(null);
const success = ref<string | null>(null);
const adminOverview = ref<AdminOverview | null>(null);

const credentialForm = ref({
  scopeType: 'tenant',
  whatsappLineId: '',
  provider: 'whatsapp_meta',
  credentialKey: 'access_token',
  secret: '',
});

function resolveErrorMessage(err: unknown, fallbackKey: string): string {
  return err instanceof Error && err.message !== '' ? err.message : t(fallbackKey);
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

function translateCredentialStatus(hasSecret: boolean): string {
  return t(`common.credentialStatus.${hasSecret ? 'configured' : 'empty'}`);
}

function translateScope(scope: 'tenant' | 'whatsapp_line'): string {
  return t(`common.scopes.${scope}`);
}

function lineLabel(line: Pick<WhatsAppLineRecord, 'name' | 'display_phone_number'> | null): string {
  if (!line) {
    return t('admin.shared.generalLabel');
  }

  return `${line.name}${line.display_phone_number ? ` · ${line.display_phone_number}` : ''}`;
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
  } catch (err) {
    error.value = resolveErrorMessage(err, 'admin.loadFailed');
  } finally {
    loading.value = false;
  }
}

async function saveCredential(): Promise<void> {
  if (!selectedTenantId.value) {
    return;
  }

  const tenantId = selectedTenantId.value;

  await withAction(t('admin.success.credentialSaved'), async () => {
    await upsertCredential(tenantId, {
      scope_type: credentialForm.value.scopeType as 'tenant' | 'whatsapp_line',
      whatsapp_line_id:
        credentialForm.value.scopeType === 'whatsapp_line' && credentialForm.value.whatsappLineId
          ? Number(credentialForm.value.whatsappLineId)
          : null,
      provider: credentialForm.value.provider.trim(),
      credential_key: credentialForm.value.credentialKey.trim(),
      secret: credentialForm.value.secret,
    });

    credentialForm.value.secret = '';
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
      panel="admin.connect.credentials.title"
      description="admin.connect.credentials.description"
    />

    <div v-if="!canViewCredentialMetadata">
      <SurfaceCard>
        <ForbiddenState
          :title="t('states.restrictedTitle')"
          :description="t('admin.noAccess')"
        />
      </SurfaceCard>
    </div>

    <div v-else-if="loading && !adminOverview">
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
          <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-[var(--accent)]">{{ t('admin.credentials.eyebrow') }}</p>
          <h3 class="mt-2 text-xl font-semibold">{{ t('admin.credentials.title') }}</h3>
        </div>

        <div class="mt-6 grid gap-4">
          <article
            v-for="credential in adminOverview.credential_metadata"
            :key="credential.id"
            class="rounded-lg border p-4"
            :style="{ borderColor: 'var(--border)' }"
          >
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
              <div>
                <strong>{{ credential.provider }} / {{ credential.credential_key }}</strong>
                <p class="mt-1 text-sm text-[var(--text-mute)]">
                  {{ credential.scope_type === 'tenant' ? t('common.scopes.tenant') : lineLabel(credential.whatsapp_line) }}
                </p>
              </div>
              <StatusBadge :label="translateCredentialStatus(credential.has_secret)" tone="neutral" />
            </div>

            <div class="mt-4 flex flex-wrap gap-4 text-sm text-[var(--text-mute)]">
              <span>{{ t('admin.credentials.lastUsed', { value: formatTimestamp(credential.last_used_at) }) }}</span>
              <span>{{ t('admin.credentials.updatedAt', { value: formatTimestamp(credential.updated_at) }) }}</span>
            </div>
          </article>
        </div>

        <form
          v-if="canManagePlatform"
          class="mt-8 rounded-lg border p-5"
          :style="{ borderColor: 'var(--border)' }"
          @submit.prevent="saveCredential"
        >
          <strong>{{ t('admin.credentials.formTitle') }}</strong>

          <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <FormField :label="t('admin.credentials.scope')">
              <select v-model="credentialForm.scopeType" class="input-base" :disabled="saving">
                <option value="tenant">{{ translateScope('tenant') }}</option>
                <option value="whatsapp_line">{{ translateScope('whatsapp_line') }}</option>
              </select>
            </FormField>

            <FormField v-if="credentialForm.scopeType === 'whatsapp_line'" :label="t('sandbox.line')">
              <select v-model="credentialForm.whatsappLineId" class="input-base" :disabled="saving">
                <option value="">{{ t('common.selectLine') }}</option>
                <option
                  v-for="line in adminOverview.whatsapp_lines"
                  :key="line.id"
                  :value="String(line.id)"
                >
                  {{ lineLabel(line) }}
                </option>
              </select>
            </FormField>

            <FormField :label="t('admin.credentials.provider')">
              <input v-model="credentialForm.provider" class="input-base" type="text" :disabled="saving" />
            </FormField>

            <FormField :label="t('admin.credentials.credentialKey')">
              <input v-model="credentialForm.credentialKey" class="input-base" type="text" :disabled="saving" />
            </FormField>
          </div>

          <FormField class="mt-5" :label="t('admin.credentials.secret')">
            <textarea
              v-model="credentialForm.secret"
              class="input-base min-h-32 resize-y"
              rows="3"
              :disabled="saving"
            />
          </FormField>

          <button
            class="btn-primary mt-5 w-full justify-center md:w-auto"
            type="submit"
            :disabled="saving"
          >
            {{ t('admin.credentials.save') }}
          </button>
        </form>
      </SurfaceCard>
    </template>
  </div>
</template>
