<script setup lang="ts">
import { Plus } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import DataTable from '../../../../components/ui/DataTable.vue';
import FormField from '../../../../components/ui/FormField.vue';
import InlineAlert from '../../../../components/ui/InlineAlert.vue';
import LoadingState from '../../../../components/ui/LoadingState.vue';
import StatusBadge from '../../../../components/ui/StatusBadge.vue';
import SurfaceCard from '../../../../components/ui/SurfaceCard.vue';
import TechValue from '../../../../components/ui/TechValue.vue';
import UiButton from '../../../../components/ui/UiButton.vue';
import UiDrawer from '../../../../components/ui/UiDrawer.vue';
import UiInput from '../../../../components/ui/UiInput.vue';
import { useNavigationAccess } from '../../../../composables/useNavigationAccess';
import { useTenantSelection } from '../../../../composables/useTenantSelection';
import { formatBytes } from '../../../../lib/formatters';
import PanelHeader from '../../components/PanelHeader.vue';
import { useAdminResource } from '../../composables/useAdminResource';
import type { AdminOverview, DataSourceRecord, WhatsAppLineRecord } from '../../types';

// design/05 "connect/data": human-verb states (Lista/Procesando/Falló) in
// the table + a metadata drawer with a "usada por" section — no raw JSON,
// no bindings matrix (that lives in assistant/tools, task 4.2). Backend
// truth checked before inventing labels: `data_sources.status` is a plain
// unconstrained string column (no enum, no Rule::in) that only ever takes
// 'pending' -> 'importing' -> 'ready' | 'failed'
// (App\Domain\DataSources\Excel\ExcelDataSourceImporter). The table
// merges 'pending'/'importing' into one "Procesando" verb per design/05's
// exact three-state list ("Lista / Procesando / Falló"); the drawer keeps
// the finer-grained headline/description pair for all four states.
// Retrying requires the *import*'s status to be 'failed'
// (DataSourceController::retryImport), which is always set in lockstep
// with the data source's own 'failed' status, so gating on
// `source.status === 'failed'` matches backend truth.

type SourceTableStatus = 'processing' | 'ready' | 'failed';

type UsageEntry = {
  toolName: string;
  toolTitle: string;
  generalUsing: boolean;
  inheritedLines: WhatsAppLineRecord[];
  customizedLines: WhatsAppLineRecord[];
};

const toolCopyKeys: Record<string, { title: string; description: string }> = {
  search_knowledge: {
    title: 'admin.dataSources.toolLabels.search_knowledge.title',
    description: 'admin.dataSources.toolLabels.search_knowledge.description',
  },
  search_inventory: {
    title: 'admin.dataSources.toolLabels.search_inventory.title',
    description: 'admin.dataSources.toolLabels.search_inventory.description',
  },
};

const fileTypeLabels: Record<string, string> = {
  pdf: 'admin.connect.data.fileTypes.pdf',
  txt: 'admin.connect.data.fileTypes.txt',
  csv: 'admin.connect.data.fileTypes.csv',
  xlsx: 'admin.connect.data.fileTypes.xlsx',
  excel: 'admin.connect.data.fileTypes.excel',
};

const { t, locale } = useI18n();
const { selectedMembership } = useTenantSelection();
const { canManageTenant } = useNavigationAccess(selectedMembership);

const { overview: adminOverview, overviewLoading: loading, dataSources } = useAdminResource();
const { loading: saving, error, success } = dataSources;

// --- shared lookups ----------------------------------------------------------

const bindingTools = computed(() => {
  const overview = adminOverview.value;

  if (!overview) {
    return [];
  }

  const supportedToolNames = overview.available_tools
    .filter((tool) => tool.supports_data_source_binding)
    .map((tool) => tool.name);

  return overview.binding_tools.filter((toolName) => supportedToolNames.includes(toolName));
});

function formatTimestamp(value: string | null): string {
  if (!value) {
    return t('common.noDate');
  }

  return new Intl.DateTimeFormat(locale.value, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(value));
}

function lineLabel(line: Pick<WhatsAppLineRecord, 'name' | 'display_phone_number'>): string {
  return `${line.name}${line.display_phone_number ? ` · ${line.display_phone_number}` : ''}`;
}

function formatLineList(lines: WhatsAppLineRecord[]): string {
  return lines.map((line) => lineLabel(line)).join(', ');
}

function translateToolName(toolName: string): string {
  const entry = toolCopyKeys[toolName];

  return entry ? t(entry.title) : toolName;
}

function sourceTypeLabel(source: DataSourceRecord): string {
  const key = fileTypeLabels[source.type];

  return key ? t(key) : source.type.toUpperCase();
}

function sourceTableStatus(source: DataSourceRecord): SourceTableStatus {
  if (source.status === 'ready') {
    return 'ready';
  }

  if (source.status === 'failed') {
    return 'failed';
  }

  return 'processing';
}

function sourceStatusLabel(source: DataSourceRecord): string {
  return t(`admin.connect.data.status.${sourceTableStatus(source)}`);
}

function sourceStatusTone(source: DataSourceRecord): 'danger' | 'success' | 'warning' {
  switch (sourceTableStatus(source)) {
    case 'ready':
      return 'success';
    case 'failed':
      return 'danger';
    default:
      return 'warning';
  }
}

function resolveTenantToolConfig(overview: AdminOverview, toolName: string) {
  return overview.tool_configs.find((config) => config.scope_type === 'tenant' && config.tool_name === toolName) ?? null;
}

function resolveLineToolConfig(overview: AdminOverview, lineId: number, toolName: string) {
  return (
    overview.tool_configs.find(
      (config) =>
        config.scope_type === 'whatsapp_line' &&
        config.whatsapp_line_id === lineId &&
        config.tool_name === toolName,
    ) ?? null
  );
}

// Whole-row usage fact per binding tool: does the general (tenant) config
// use this source, and which lines inherit vs. customize that choice.
// Same resolution `ToolBoundDataSourceResolver` uses on the backend
// (explicit per-tool-config binding first, tenant scope as the line
// fallback) — kept unchanged from the pre-redesign implementation since
// the backend research for this task confirmed it already matches.
function usageEntries(sourceId: number): UsageEntry[] {
  const overview = adminOverview.value;

  if (!overview) {
    return [];
  }

  return bindingTools.value
    .map((toolName): UsageEntry => {
      const tenantConfig = resolveTenantToolConfig(overview, toolName);
      const generalUsing = tenantConfig?.data_source_id === sourceId;
      const inheritedLines: WhatsAppLineRecord[] = [];
      const customizedLines: WhatsAppLineRecord[] = [];

      overview.whatsapp_lines.forEach((line) => {
        const lineConfig = resolveLineToolConfig(overview, line.id, toolName);
        const effectiveConfig = lineConfig ?? tenantConfig;

        if (effectiveConfig?.data_source_id !== sourceId) {
          return;
        }

        if (lineConfig) {
          customizedLines.push(line);
          return;
        }

        inheritedLines.push(line);
      });

      return {
        toolName,
        toolTitle: translateToolName(toolName),
        generalUsing,
        inheritedLines,
        customizedLines,
      };
    })
    .filter((entry) => entry.generalUsing || entry.inheritedLines.length > 0 || entry.customizedLines.length > 0);
}

function sourceUsedByLabel(sourceId: number): string {
  const entries = usageEntries(sourceId);

  return entries.length > 0 ? entries.map((entry) => entry.toolTitle).join(', ') : t('admin.connect.data.usedByNone');
}

function canRetry(source: DataSourceRecord): boolean {
  return source.status === 'failed' && source.latest_import !== null;
}

async function retryImport(source: DataSourceRecord): Promise<void> {
  if (!source.latest_import) {
    return;
  }

  await dataSources.retryImport(source.id, source.latest_import.id, {
    successMessage: t('admin.success.importRetried'),
  });
}

// --- upload drawer -----------------------------------------------------------

const uploadOpen = ref(false);
const uploadName = ref('');
const uploadFile = ref<File | null>(null);

const canSubmitUpload = computed(() => uploadFile.value !== null);

function openUploadDrawer(): void {
  uploadName.value = '';
  uploadFile.value = null;
  uploadOpen.value = true;
}

function closeUploadDrawer(): void {
  uploadOpen.value = false;
}

function onUploadOpenChange(value: boolean): void {
  if (!value) {
    closeUploadDrawer();
  }
}

function onUploadFileChange(event: Event): void {
  const input = event.target as HTMLInputElement;
  uploadFile.value = input.files?.[0] ?? null;
}

async function submitUpload(): Promise<void> {
  if (!uploadFile.value) {
    return;
  }

  const uploaded = await dataSources.upload(
    { name: uploadName.value.trim(), file: uploadFile.value },
    { successMessage: t('admin.success.dataSourceUploaded') },
  );

  if (uploaded) {
    closeUploadDrawer();
  }
}

// --- detail drawer -------------------------------------------------------------

const detailSourceId = ref<number | null>(null);
const detailOpen = computed(() => detailSourceId.value !== null);

const detailSource = computed<DataSourceRecord | null>(() => {
  if (detailSourceId.value === null) {
    return null;
  }

  return (adminOverview.value?.data_sources ?? []).find((source) => source.id === detailSourceId.value) ?? null;
});

const detailUsageEntries = computed<UsageEntry[]>(() => {
  const source = detailSource.value;

  return source ? usageEntries(source.id) : [];
});

function openDetail(sourceId: number): void {
  detailSourceId.value = sourceId;
}

function closeDetail(): void {
  detailSourceId.value = null;
}

function onDetailOpenChange(value: boolean): void {
  if (!value) {
    closeDetail();
  }
}

async function retryFromDetail(): Promise<void> {
  const source = detailSource.value;

  if (source) {
    await retryImport(source);
  }
}
</script>

<template>
  <div class="space-y-5">
    <PanelHeader
      group="admin.nav.connect"
      panel="admin.connect.data.title"
      description="admin.connect.data.description"
    >
      <template #actions>
        <UiButton variant="primary" :disabled="!canManageTenant" @click="openUploadDrawer">
          <template #icon>
            <Plus class="h-4 w-4" :stroke-width="2" aria-hidden="true" />
          </template>
          {{ t('admin.connect.data.uploadAction') }}
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
        data-settings-key="connect.data.panel"
        :columns="[
          { key: 'source', label: t('admin.connect.data.columns.source') },
          { key: 'type', label: t('admin.connect.data.columns.type') },
          { key: 'status', label: t('admin.connect.data.columns.status') },
          { key: 'usedBy', label: t('admin.connect.data.columns.usedBy') },
          { key: 'actions', label: t('admin.connect.data.columns.actions') },
        ]"
      >
        <template #body>
          <tr v-if="adminOverview.data_sources.length === 0">
            <td colspan="5" class="data-table-empty">{{ t('admin.connect.data.empty') }}</td>
          </tr>
          <tr
            v-for="source in adminOverview.data_sources"
            :key="source.id"
            class="source-row"
            @click="openDetail(source.id)"
          >
            <td>
              <button type="button" class="source-name-btn" @click.stop="openDetail(source.id)">
                {{ source.name }}
              </button>
            </td>
            <td>{{ sourceTypeLabel(source) }}</td>
            <td>
              <StatusBadge :label="sourceStatusLabel(source)" :tone="sourceStatusTone(source)" />
            </td>
            <td>{{ sourceUsedByLabel(source.id) }}</td>
            <td>
              <UiButton
                v-if="canRetry(source)"
                variant="secondary"
                size="sm"
                :disabled="!canManageTenant || saving"
                @click.stop="retryImport(source)"
              >
                {{ t('admin.connect.data.retryAction') }}
              </UiButton>
            </td>
          </tr>
        </template>
      </DataTable>
    </template>

    <!-- Detail drawer -->
    <UiDrawer
      :open="detailOpen"
      :title="detailSource?.name ?? ''"
      :close-label="t('common.close')"
      @update:open="onDetailOpenChange"
    >
      <template v-if="detailSource">
        <div class="source-detail-summary">
          <StatusBadge :label="sourceStatusLabel(detailSource)" :tone="sourceStatusTone(detailSource)" />
          <span class="text-small" style="color: var(--text-mute)">{{ sourceTypeLabel(detailSource) }}</span>
        </div>

        <InlineAlert
          v-if="detailSource.status === 'failed'"
          :message="t('admin.connect.data.detail.failedAlert')"
          tone="danger"
        />

        <section class="grid gap-3">
          <h4 class="text-h3">{{ t('admin.connect.data.detail.generalTitle') }}</h4>

          <p class="text-small" style="color: var(--text-mute)">
            {{ t(`admin.connect.data.detail.statusDescriptions.${detailSource.status}`) }}
          </p>

          <FormField :label="t('admin.connect.data.detail.fileLabel')">
            <p class="text-body">{{ detailSource.latest_upload?.original_name ?? t('common.noFile') }}</p>
          </FormField>
          <FormField :label="t('admin.connect.data.detail.sizeLabel')">
            <p class="text-body">{{ formatBytes(detailSource.latest_upload?.size_bytes) }}</p>
          </FormField>
          <FormField :label="t('admin.connect.data.detail.fragmentsLabel')">
            <p class="text-body">{{ t('admin.connect.data.detail.fragmentsValue', { count: detailSource.chunk_count }) }}</p>
          </FormField>
          <FormField :label="t('admin.connect.data.detail.updatedLabel')">
            <p class="text-body">
              {{
                formatTimestamp(
                  detailSource.last_synced_at ?? detailSource.latest_import?.finished_at ?? detailSource.latest_upload?.created_at ?? null,
                )
              }}
            </p>
          </FormField>
          <FormField v-if="detailSource.latest_import" :label="t('admin.connect.data.detail.attemptsLabel')">
            <p class="text-body">{{ detailSource.latest_import.attempts_count }}</p>
          </FormField>
          <FormField
            v-if="detailSource.status === 'failed' && detailSource.latest_import?.error_message"
            :label="t('admin.connect.data.detail.errorDetailLabel')"
          >
            <TechValue :value="detailSource.latest_import.error_message" />
          </FormField>

          <UiButton
            v-if="canRetry(detailSource)"
            class="justify-self-start"
            variant="secondary"
            size="sm"
            :loading="saving"
            :disabled="!canManageTenant"
            @click="retryFromDetail"
          >
            {{ t('admin.connect.data.detail.retryAction') }}
          </UiButton>
        </section>

        <section class="grid gap-3">
          <h4 class="text-h3">{{ t('admin.connect.data.usedBy.title') }}</h4>
          <p class="text-small" style="color: var(--text-mute)">{{ t('admin.connect.data.usedBy.description') }}</p>

          <p v-if="detailUsageEntries.length === 0" class="text-body" style="color: var(--text-mute)">
            {{ t('admin.connect.data.usedBy.none') }}
          </p>
          <ul v-else class="used-by-list">
            <li v-for="entry in detailUsageEntries" :key="entry.toolName">
              <strong>{{ entry.toolTitle }}</strong>
              <p v-if="entry.generalUsing" class="text-small" style="color: var(--text-mute)">
                {{ t('admin.connect.data.usedBy.generalUsage') }}
              </p>
              <p v-if="entry.inheritedLines.length > 0" class="text-small" style="color: var(--text-mute)">
                {{ t('admin.connect.data.usedBy.inheritedLines', { lines: formatLineList(entry.inheritedLines) }) }}
              </p>
              <p v-if="entry.customizedLines.length > 0" class="text-small" style="color: var(--text-mute)">
                {{ t('admin.connect.data.usedBy.customLines', { lines: formatLineList(entry.customizedLines) }) }}
              </p>
            </li>
          </ul>
        </section>
      </template>
    </UiDrawer>

    <!-- Upload drawer -->
    <UiDrawer
      :open="uploadOpen"
      :title="t('admin.connect.data.uploadDrawer.title')"
      :close-label="t('common.close')"
      @update:open="onUploadOpenChange"
    >
      <FormField
        :label="t('admin.connect.data.uploadDrawer.nameLabel')"
        :hint="t('admin.connect.data.uploadDrawer.nameHint')"
      >
        <UiInput v-model="uploadName" type="text" :disabled="saving" />
      </FormField>
      <FormField
        :label="t('admin.connect.data.uploadDrawer.fileLabel')"
        :hint="t('admin.connect.data.uploadDrawer.fileHint')"
      >
        <input class="input-base" type="file" accept=".pdf,.txt,.csv,.xlsx" :disabled="saving" @change="onUploadFileChange" />
      </FormField>

      <template #footer>
        <UiButton variant="secondary" :disabled="saving" @click="closeUploadDrawer">
          {{ t('common.cancel') }}
        </UiButton>
        <UiButton variant="primary" :loading="saving" :disabled="!canSubmitUpload" @click="submitUpload">
          {{ t('admin.connect.data.uploadDrawer.submitAction') }}
        </UiButton>
      </template>
    </UiDrawer>
  </div>
</template>

<style scoped>
.source-row {
  cursor: pointer;
}

.source-name-btn {
  font-weight: 600;
  color: var(--text);
}

.source-name-btn:hover {
  color: var(--accent);
}

.source-detail-summary {
  display: flex;
  align-items: center;
  gap: var(--space-3);
}

.used-by-list {
  display: grid;
  gap: var(--space-3);
}

.used-by-list p {
  margin: 2px 0 0;
}
</style>
