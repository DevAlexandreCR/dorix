<script setup lang="ts">
import { Plus } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import DataTable from '../../../components/ui/DataTable.vue';
import EmptyState from '../../../components/ui/EmptyState.vue';
import FormField from '../../../components/ui/FormField.vue';
import InlineAlert from '../../../components/ui/InlineAlert.vue';
import LoadingState from '../../../components/ui/LoadingState.vue';
import StatusBadge from '../../../components/ui/StatusBadge.vue';
import SurfaceCard from '../../../components/ui/SurfaceCard.vue';
import TechValue from '../../../components/ui/TechValue.vue';
import UiButton from '../../../components/ui/UiButton.vue';
import UiDrawer from '../../../components/ui/UiDrawer.vue';
import UiInput from '../../../components/ui/UiInput.vue';
import UiSelect from '../../../components/ui/UiSelect.vue';
import UiTextarea from '../../../components/ui/UiTextarea.vue';
import { useSession } from '../../../composables/useSession';
import { useTenantSelection } from '../../../composables/useTenantSelection';
// Cross-module import, intentional and kept as-is by task 5.4:
// `useAdminResource` already does exactly what this screen needs — fetch
// `fetchAdminOverview` for whichever tenant is selected, patch
// `credential_metadata` in memory from the upsert response, and wire
// loading/error/success + toast (task 3.1/3.2). Forking a platform-local
// copy of that session-cache + feedback machinery for this single mutation
// would duplicate design.md decision 5's architecture for no real gain, so
// task 5.4 kept the composable reuse and only relocated the underlying
// `upsertCredential` API call itself into `modules/platform/api.ts` (see
// the import comment inside `useAdminResource.ts`'s `credentials.upsert`
// wiring).
import { useAdminResource } from '../../admin/composables/useAdminResource';
import PlatformPanelHeader from '../components/PlatformPanelHeader.vue';
import type { CredentialMetadataRecord } from '../types';

// design/06 "/platform/credentials": tenant-scoped screen with its own
// selector (design.md decision 11). Credential endpoints require
// X-Tenant-Id, so this screen operates on one tenant at a time,
// independent of the TopBar (whose pill is hidden entirely on
// `/platform/**` per task 5.1). Platform admins have synthetic
// membership in every tenant (`AuthSessionController::buildSessionPayload`
// maps `Tenant::all()` to memberships when `isPlatformAdmin()`), so the
// tenant list is already in session — no new endpoint.
//
// X-Tenant-Id targeting: `useTenantSelection()` derives its selection from
// `route.query.tenant` on the *current* route. Called here, on
// `/platform/credentials`, it reads/writes that route's own query — a
// value with nothing to do with whatever `?tenant=` an `/admin/**` route
// might carry (a different route, a different `route.query`, and
// vue-router does not carry query params across route changes). The one
// `useTenantSelection()` call below drives both this screen's visible
// selector AND — via the identical call made inside `useAdminResource()`
// — the `X-Tenant-Id` header on every fetch/mutation `tenantHeaders()`
// builds in `modules/admin/api.ts`. There is no separate "globally
// selected tenant" for this header to drift from: by construction it
// always targets the tenant chosen in this screen's own selector.

const { t, locale } = useI18n();
const { memberships } = useSession();
const { selectedTenantId, setSelectedTenantId } = useTenantSelection();

const selectedTenantIdString = computed<string>({
  get: () => (selectedTenantId.value !== null ? String(selectedTenantId.value) : ''),
  set: (value) => setSelectedTenantId(value ? Number(value) : null),
});

const { overview, overviewLoading: loading, lines, credentials } = useAdminResource();
const lineOptions = lines.data;
const { loading: saving, error, success } = credentials;

// Only known provider today (`MetaGraphOutboundMessageSender::PROVIDER`,
// same fact already established in `admin/connect/CredentialsView` — task
// 4.7). Kept as a small local array + label map rather than importing the
// admin view's own copy, matching the platform module's "duplicate small
// screen-local copy" convention from task 5.1.
const PROVIDER_OPTIONS = ['whatsapp_meta'] as const;

function providerLabel(provider: string): string {
  return (PROVIDER_OPTIONS as readonly string[]).includes(provider)
    ? t(`platform.credentials.providers.${provider}`)
    : provider;
}

function scopeLabel(credential: CredentialMetadataRecord): string {
  if (credential.scope_type === 'whatsapp_line' && credential.whatsapp_line) {
    return t('platform.credentials.columns.scopeLineValue', { name: credential.whatsapp_line.name });
  }

  return t('platform.credentials.scopeGlobal');
}

function statusLabel(credential: CredentialMetadataRecord): string {
  return t(`common.credentialStatus.${credential.has_secret ? 'configured' : 'empty'}`);
}

function statusTone(credential: CredentialMetadataRecord): 'success' | 'neutral' {
  return credential.has_secret ? 'success' : 'neutral';
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

// --- upsert drawer -----------------------------------------------------------
// Single form covers both "create a new credential" and "overwrite an
// existing one": the backend upsert key is (tenant, scope, provider,
// credential_key) — re-entering the same three fields with a new secret
// overwrites in place (`ApiCredential::firstOrNew` in
// `AdminCredentialController::upsert`), so no separate edit mode is
// needed. The secret is `required` on every upsert request
// (`UpsertCredentialRequest`), matching "write-only": there is no way to
// change scope/provider/key without also supplying a fresh secret, and
// nothing here ever reads an existing secret back to prefill the field.

type CredentialForm = {
  scopeType: 'tenant' | 'whatsapp_line';
  whatsappLineId: string;
  provider: string;
  credentialKey: string;
  secret: string;
};

function defaultCredentialForm(): CredentialForm {
  return {
    scopeType: 'tenant',
    whatsappLineId: '',
    provider: PROVIDER_OPTIONS[0],
    credentialKey: '',
    secret: '',
  };
}

const credentialDrawerOpen = ref(false);
const credentialForm = ref<CredentialForm>(defaultCredentialForm());

const canSubmitCredential = computed(() => {
  const form = credentialForm.value;

  if (form.credentialKey.trim() === '' || form.secret.trim() === '') {
    return false;
  }

  if (form.scopeType === 'whatsapp_line' && form.whatsappLineId === '') {
    return false;
  }

  return true;
});

function openCredentialDrawer(): void {
  credentialForm.value = defaultCredentialForm();
  credentialDrawerOpen.value = true;
}

function closeCredentialDrawer(): void {
  credentialDrawerOpen.value = false;
}

function onCredentialDrawerOpenChange(value: boolean): void {
  if (!value) {
    closeCredentialDrawer();
  }
}

async function submitCredential(): Promise<void> {
  const form = credentialForm.value;

  const saved = await credentials.upsert(
    {
      scope_type: form.scopeType,
      whatsapp_line_id: form.scopeType === 'whatsapp_line' ? Number(form.whatsappLineId) : null,
      provider: form.provider,
      credential_key: form.credentialKey.trim(),
      secret: form.secret,
    },
    { successMessage: t('platform.credentials.success.saved') },
  );

  // Secret is write-only: clear the whole form (never just close and leave
  // a typed secret sitting in memory) as soon as the save succeeds.
  if (saved) {
    closeCredentialDrawer();
    credentialForm.value = defaultCredentialForm();
  }
}
</script>

<template>
  <div class="space-y-5">
    <PlatformPanelHeader panel="platform.nav.credentials" description="platform.credentials.description">
      <template v-if="memberships.length > 0" #actions>
        <UiButton variant="primary" @click="openCredentialDrawer">
          <template #icon>
            <Plus class="h-4 w-4" :stroke-width="2" aria-hidden="true" />
          </template>
          {{ t('platform.credentials.drawer.submitAction') }}
        </UiButton>
      </template>
    </PlatformPanelHeader>

    <EmptyState
      v-if="memberships.length === 0"
      :title="t('platform.credentials.noTenants.title')"
      :description="t('platform.credentials.noTenants.description')"
    />

    <template v-else>
      <SurfaceCard padding="sm">
        <div class="credentials-tenant-filter">
          <FormField :label="t('platform.credentials.tenantLabel')">
            <UiSelect v-model="selectedTenantIdString">
              <option v-for="membership in memberships" :key="membership.tenant_id ?? 'none'" :value="String(membership.tenant_id)">
                {{ membership.tenant_name }}
              </option>
            </UiSelect>
          </FormField>
        </div>
      </SurfaceCard>

      <div v-if="loading && !overview">
        <SurfaceCard>
          <LoadingState :label="t('admin.loading')" />
        </SurfaceCard>
      </div>

      <template v-else-if="overview">
        <div class="grid gap-3">
          <InlineAlert v-if="error" :message="error" tone="danger" />
          <InlineAlert v-if="success" :message="success" tone="success" />
        </div>

        <DataTable
          :columns="[
            { key: 'provider', label: t('platform.credentials.columns.provider') },
            { key: 'key', label: t('platform.credentials.columns.key') },
            { key: 'scope', label: t('platform.credentials.columns.scope') },
            { key: 'status', label: t('platform.credentials.columns.status') },
            { key: 'lastUsed', label: t('platform.credentials.columns.lastUsed') },
          ]"
        >
          <template #body>
            <tr v-if="overview.credential_metadata.length === 0">
              <td colspan="5" class="data-table-empty">{{ t('platform.credentials.empty') }}</td>
            </tr>
            <tr v-for="credential in overview.credential_metadata" :key="credential.id">
              <td>{{ providerLabel(credential.provider) }}</td>
              <td><TechValue :value="credential.credential_key" /></td>
              <td>{{ scopeLabel(credential) }}</td>
              <td>
                <StatusBadge :label="statusLabel(credential)" :tone="statusTone(credential)" />
              </td>
              <td>{{ formatTimestamp(credential.last_used_at) }}</td>
            </tr>
          </template>
        </DataTable>
      </template>
    </template>

    <!-- Upsert drawer -->
    <UiDrawer
      :open="credentialDrawerOpen"
      :title="t('platform.credentials.drawer.title')"
      :close-label="t('common.close')"
      @update:open="onCredentialDrawerOpenChange"
    >
      <FormField :label="t('platform.credentials.drawer.scopeLabel')">
        <UiSelect v-model="credentialForm.scopeType" :disabled="saving">
          <option value="tenant">{{ t('platform.credentials.drawer.scopeGlobalOption') }}</option>
          <option value="whatsapp_line">{{ t('platform.credentials.drawer.scopeLineOption') }}</option>
        </UiSelect>
      </FormField>

      <FormField v-if="credentialForm.scopeType === 'whatsapp_line'" :label="t('platform.credentials.drawer.lineLabel')">
        <UiSelect v-model="credentialForm.whatsappLineId" :disabled="saving">
          <option value="" disabled>{{ t('platform.credentials.drawer.linePlaceholder') }}</option>
          <option v-for="line in lineOptions" :key="line.id" :value="String(line.id)">
            {{ line.name }}
          </option>
        </UiSelect>
      </FormField>

      <FormField :label="t('platform.credentials.drawer.providerLabel')">
        <UiSelect v-model="credentialForm.provider" :disabled="saving">
          <option v-for="option in PROVIDER_OPTIONS" :key="option" :value="option">
            {{ providerLabel(option) }}
          </option>
        </UiSelect>
      </FormField>

      <FormField :label="t('platform.credentials.drawer.credentialKeyLabel')" :hint="t('platform.credentials.drawer.credentialKeyHint')">
        <UiInput v-model="credentialForm.credentialKey" type="text" required :disabled="saving" />
      </FormField>

      <FormField :label="t('platform.credentials.drawer.secretLabel')" :hint="t('platform.credentials.drawer.secretHint')">
        <UiTextarea v-model="credentialForm.secret" required :disabled="saving" :rows="3" autocomplete="off" />
      </FormField>

      <template #footer>
        <UiButton variant="secondary" :disabled="saving" @click="closeCredentialDrawer">
          {{ t('common.cancel') }}
        </UiButton>
        <UiButton variant="primary" :loading="saving" :disabled="!canSubmitCredential" @click="submitCredential">
          {{ t('platform.credentials.drawer.submitAction') }}
        </UiButton>
      </template>
    </UiDrawer>
  </div>
</template>

<style scoped>
.credentials-tenant-filter {
  max-width: 320px;
}
</style>
