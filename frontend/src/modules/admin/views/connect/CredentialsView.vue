<script setup lang="ts">
import { ArrowRight } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import DataTable from '../../../../components/ui/DataTable.vue';
import InlineAlert from '../../../../components/ui/InlineAlert.vue';
import LoadingState from '../../../../components/ui/LoadingState.vue';
import StatusBadge from '../../../../components/ui/StatusBadge.vue';
import SurfaceCard from '../../../../components/ui/SurfaceCard.vue';
import TechValue from '../../../../components/ui/TechValue.vue';
import UiButton from '../../../../components/ui/UiButton.vue';
import { useNavigationAccess } from '../../../../composables/useNavigationAccess';
import { useTenantSelection } from '../../../../composables/useTenantSelection';
import PanelHeader from '../../components/PanelHeader.vue';
import { useAdminResource } from '../../composables/useAdminResource';
import type { CredentialMetadataRecord, WhatsAppLineRecord } from '../../types';

// design/05 "connect/credentials": read-only DataTable + copy explaining who
// to ask for changes + a link to /platform/credentials for platform admins
// (design.md decision 11 — the upsert form lives there, task 5.3; this
// screen never mutates a credential). `upsertCredential` now lives in
// `modules/platform/api.ts` (task 5.4) — this view never imports it.
//
// Route-level gating (`ADMIN_ROUTE_REQUIRES['/admin/connect/credentials']`)
// already renders `ForbiddenState` via `AdminLayout` before this view ever
// mounts, so — like the other sibling screens (Lines/Data/Members) — this
// view does not repeat that check itself.

const router = useRouter();
const { t, locale } = useI18n();
const { selectedMembership } = useTenantSelection();
const { canManagePlatform } = useNavigationAccess(selectedMembership);

const { overview: adminOverview, overviewLoading: loading } = useAdminResource();

// Only known provider in this system today (see
// `MetaGraphOutboundMessageSender::PROVIDER`); falls back to the raw value
// for anything else, same pattern `DataView.sourceTypeLabel` uses for
// unmapped file types.
const providerLabels: Record<string, string> = {
  whatsapp_meta: 'admin.connect.credentials.providers.whatsapp_meta',
};

function providerLabel(provider: string): string {
  const key = providerLabels[provider];
  return key ? t(key) : provider;
}

function lineLabel(line: Pick<WhatsAppLineRecord, 'name' | 'display_phone_number'>): string {
  return `${line.name}${line.display_phone_number ? ` · ${line.display_phone_number}` : ''}`;
}

function scopeLabel(credential: CredentialMetadataRecord): string {
  if (credential.scope_type === 'whatsapp_line' && credential.whatsapp_line) {
    return lineLabel(credential.whatsapp_line);
  }

  return t('admin.connect.credentials.scopeGlobal');
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

// `/platform/credentials` doesn't exist yet (task 5.1 creates it) — no
// route currently matches this path, so vue-router resolves the navigation
// to an empty match instead of throwing. `.catch` is a defensive no-op for
// once the route exists too (navigation-aborted rejections, etc.).
function goToPlatformCredentials(): void {
  router.push('/platform/credentials').catch(() => {});
}
</script>

<template>
  <div class="space-y-5">
    <PanelHeader
      group="admin.nav.connect"
      panel="admin.connect.credentials.title"
      description="admin.connect.credentials.description"
    >
      <template v-if="canManagePlatform" #actions>
        <UiButton variant="secondary" @click="goToPlatformCredentials">
          <template #icon>
            <ArrowRight class="h-4 w-4" :stroke-width="2" aria-hidden="true" />
          </template>
          {{ t('admin.connect.credentials.platformLink') }}
        </UiButton>
      </template>
    </PanelHeader>

    <div v-if="loading && !adminOverview">
      <SurfaceCard>
        <LoadingState :label="t('admin.loading')" />
      </SurfaceCard>
    </div>

    <template v-else-if="adminOverview">
      <InlineAlert tone="info" :message="t('admin.connect.credentials.whoToAsk')" />

      <DataTable
        data-settings-key="connect.credentials.panel"
        :columns="[
          { key: 'provider', label: t('admin.connect.credentials.columns.provider') },
          { key: 'key', label: t('admin.connect.credentials.columns.key') },
          { key: 'scope', label: t('admin.connect.credentials.columns.scope') },
          { key: 'status', label: t('admin.connect.credentials.columns.status') },
          { key: 'lastUsed', label: t('admin.connect.credentials.columns.lastUsed') },
        ]"
      >
        <template #body>
          <tr v-if="adminOverview.credential_metadata.length === 0">
            <td colspan="5" class="data-table-empty">{{ t('admin.connect.credentials.empty') }}</td>
          </tr>
          <tr v-for="credential in adminOverview.credential_metadata" :key="credential.id">
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
  </div>
</template>
