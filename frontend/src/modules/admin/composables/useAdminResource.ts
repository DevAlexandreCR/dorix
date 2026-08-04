import { computed, reactive, ref, watch } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useNavigationAccess } from '../../../composables/useNavigationAccess';
import { useTenantSelection } from '../../../composables/useTenantSelection';
import {
  createCatalogItem,
  createTenantUser,
  createWhatsAppLine,
  deleteCatalogItem,
  deleteLineAgentConfig,
  deleteLineToolConfig,
  deleteTenantUser,
  deleteWhatsAppLine,
  fetchAdminOverview,
  requestCalendarConsentUrl,
  retryDataSourceImport,
  updateCatalogItem,
  updateLineAgentConfig,
  updateLineToolConfig,
  updateTenant,
  updateTenantAgentConfig,
  updateTenantToolConfig,
  updateTenantUser,
  updateWhatsAppLine,
  uploadDataSource,
} from '../api';
import type { CatalogItemPayload } from '../api';
// `upsertCredential` is owned by `modules/platform/api.ts` (task 5.4): the
// only caller of `credentials.upsert()` below is
// `modules/platform/views/CredentialsView.vue` (task 5.3) — no tenant-side
// screen mutates a credential (task 4.7 made `admin/connect/CredentialsView`
// read-only). This composable still hosts the `upsert` *method* because
// reusing its session cache + loading/error/success/toast wiring
// (design.md decision 5) avoids re-implementing all of that in a
// platform-local composable for a single mutation — the cross-module
// composable reuse task 5.3 already established. The underlying API call
// it delegates to, though, belongs with the rest of platform's owned
// surface, not here.
import { upsertCredential } from '../../platform/api';
import type {
  AdminOverview,
  AgentConfigRecord,
  AgentModelOption,
  CatalogItemRecord,
  CredentialMetadataRecord,
  DataSourceRecord,
  TenantRecord,
  TenantUserRecord,
  ToolConfigRecord,
  WhatsAppLineRecord,
} from '../types';
import { useAdminFeedback } from './useAdminFeedback';
import type { AdminActionOptions, AdminFeedback } from './useAdminFeedback';

// ---------------------------------------------------------------------------
// Module-level session cache (design.md decision 5)
//
// `fetchAdminOverview()` is the only combined GET. It is fetched at most once
// per admin session and re-fetched only when the selected tenant changes —
// never on view mount/unmount, and never as a follow-up to a mutation. Every
// component that calls `useAdminResource()` shares this single reactive
// object, so a mutation applied from one view is immediately visible in any
// other mounted view without an extra request.
// ---------------------------------------------------------------------------

interface AdminSessionState {
  tenantId: number | null;
  overview: AdminOverview | null;
}

const sessionState = reactive<AdminSessionState>({
  tenantId: null,
  overview: null,
});

const overviewLoading = ref(false);
const overviewError = ref<string | null>(null);

let pendingLoad: Promise<void> | null = null;
let pendingTenantId: number | null = null;

function ensureOverviewLoaded(tenantId: number, force = false): Promise<void> {
  if (!force && sessionState.tenantId === tenantId && sessionState.overview) {
    return Promise.resolve();
  }

  if (pendingLoad && pendingTenantId === tenantId) {
    return pendingLoad;
  }

  pendingTenantId = tenantId;
  overviewLoading.value = true;

  pendingLoad = fetchAdminOverview(tenantId)
    .then((payload) => {
      sessionState.overview = payload.data;
      sessionState.tenantId = tenantId;
      overviewError.value = null;
    })
    .catch((err) => {
      sessionState.overview = null;
      sessionState.tenantId = null;
      throw err;
    })
    .finally(() => {
      overviewLoading.value = false;
      pendingLoad = null;
      pendingTenantId = null;
    });

  return pendingLoad;
}

// ---------------------------------------------------------------------------
// In-memory collection helpers
//
// Every mutation endpoint used below returns the record it just created or
// updated, so the local collection is patched from that response — never
// from a follow-up GET. The 4 endpoints that return void on success
// (deleteTenantUser, deleteWhatsAppLine, deleteLineAgentConfig,
// deleteLineToolConfig) already give the caller everything needed
// (id / lineId / toolName) to remove the right entry locally.
// ---------------------------------------------------------------------------

function upsertById<T extends { id: number }>(list: T[], record: T): T[] {
  const index = list.findIndex((item) => item.id === record.id);

  if (index === -1) {
    return [...list, record];
  }

  const next = list.slice();
  next[index] = record;
  return next;
}

function removeById<T extends { id: number }>(list: T[], id: number): T[] {
  return list.filter((item) => item.id !== id);
}

function removeWhere<T>(list: T[], predicate: (item: T) => boolean): T[] {
  return list.filter((item) => !predicate(item));
}

export interface AdminSingleResource<T> {
  data: ComputedRef<T | null>;
  loading: Ref<boolean>;
  error: Ref<string | null>;
  success: Ref<string | null>;
}

export interface AdminCollectionResource<T> {
  data: ComputedRef<T[]>;
  loading: Ref<boolean>;
  error: Ref<string | null>;
  success: Ref<string | null>;
}

/**
 * Shared data + feedback layer for the admin panel (design.md decision 5).
 *
 * Loads `fetchAdminOverview()` once per admin session / tenant change (see
 * the module-level cache above) and exposes each resource's slice of it
 * (tenant, members, lines, credentials, agentConfigs, toolConfigs,
 * dataSources, events) plus the read-only catalog data the forms need
 * (available roles/models/agent packs/tools). Every mutation method updates
 * its resource's collection in memory from the mutation's own response and
 * drives that resource's own loading/error/success + toast via
 * `useAdminFeedback()` — no view calls `fetchAdminOverview()` itself and no
 * mutation triggers a refetch.
 */
export function useAdminResource() {
  const { t } = useI18n();
  const { selectedTenantId, selectedMembership } = useTenantSelection();
  const { canAccessAdmin } = useNavigationAccess(selectedMembership);

  watch(
    [selectedTenantId, canAccessAdmin],
    ([tenantId, hasAccess]) => {
      if (!tenantId || !hasAccess) {
        sessionState.tenantId = null;
        sessionState.overview = null;
        overviewError.value = null;
        return;
      }

      ensureOverviewLoaded(tenantId).catch((err) => {
        overviewError.value = err instanceof Error && err.message !== '' ? err.message : t('admin.loadFailed');
      });
    },
    { immediate: true },
  );

  function currentTenantId(): number | null {
    return selectedTenantId.value;
  }

  function mutateOverview(mutator: (overview: AdminOverview) => void): void {
    if (!sessionState.overview) {
      return;
    }

    mutator(sessionState.overview);
  }

  /** Escape hatch to force a fresh overview load (e.g. retrying after a failed initial load). Never call this after a mutation — mutations update collections in memory instead. */
  function reloadOverview(): Promise<void> | undefined {
    const tenantId = currentTenantId();

    if (!tenantId) {
      return undefined;
    }

    return ensureOverviewLoaded(tenantId, true).catch((err) => {
      overviewError.value = err instanceof Error && err.message !== '' ? err.message : t('admin.loadFailed');
    });
  }

  // --- catalog (read-only, part of the overview, never mutated) ----------

  const availableRoles = computed<string[]>(() => sessionState.overview?.available_roles ?? []);
  const availableAgentPacks = computed<{ key: string; name: string }[]>(
    () => sessionState.overview?.available_agent_packs ?? [],
  );
  const availableModels = computed<AgentModelOption[]>(() => sessionState.overview?.available_models ?? []);
  const bindingTools = computed<string[]>(() => sessionState.overview?.binding_tools ?? []);
  const availableTools = computed<{ name: string; description: string; supports_data_source_binding: boolean }[]>(
    () => sessionState.overview?.available_tools ?? [],
  );

  // --- tenant ---------------------------------------------------------------

  const tenantFeedback = useAdminFeedback();
  const tenant: AdminSingleResource<TenantRecord> & {
    update(payload: { name: string; slug: string; status: string }, options?: AdminActionOptions): Promise<TenantRecord | undefined>;
  } = {
    data: computed(() => sessionState.overview?.tenant ?? null),
    loading: tenantFeedback.loading,
    error: tenantFeedback.error,
    success: tenantFeedback.success,
    async update(payload, options) {
      const tenantId = currentTenantId();
      if (!tenantId) return undefined;

      return tenantFeedback.run(async () => {
        const response = await updateTenant(tenantId, payload);
        mutateOverview((overview) => {
          overview.tenant = response.data;
        });
        return response.data;
      }, options);
    },
  };

  // --- members (tenant_users) ------------------------------------------------

  const membersFeedback = useAdminFeedback();
  const members: AdminCollectionResource<TenantUserRecord> & {
    create(
      payload: { name: string; email: string; password?: string; role: string },
      options?: AdminActionOptions,
    ): Promise<TenantUserRecord | undefined>;
    update(
      tenantUserId: number,
      payload: { role: string },
      options?: AdminActionOptions,
    ): Promise<TenantUserRecord | undefined>;
    remove(tenantUserId: number, options?: AdminActionOptions): Promise<boolean>;
  } = {
    data: computed(() => sessionState.overview?.tenant_users ?? []),
    loading: membersFeedback.loading,
    error: membersFeedback.error,
    success: membersFeedback.success,
    async create(payload, options) {
      const tenantId = currentTenantId();
      if (!tenantId) return undefined;

      return membersFeedback.run(async () => {
        const response = await createTenantUser(tenantId, payload);
        mutateOverview((overview) => {
          overview.tenant_users = upsertById(overview.tenant_users, response.data);
        });
        return response.data;
      }, options);
    },
    async update(tenantUserId, payload, options) {
      const tenantId = currentTenantId();
      if (!tenantId) return undefined;

      return membersFeedback.run(async () => {
        const response = await updateTenantUser(tenantId, tenantUserId, payload);
        mutateOverview((overview) => {
          overview.tenant_users = upsertById(overview.tenant_users, response.data);
        });
        return response.data;
      }, options);
    },
    async remove(tenantUserId, options) {
      const tenantId = currentTenantId();
      if (!tenantId) return false;

      const result = await membersFeedback.run(async () => {
        await deleteTenantUser(tenantId, tenantUserId);
        mutateOverview((overview) => {
          overview.tenant_users = removeById(overview.tenant_users, tenantUserId);
        });
        return true;
      }, options);

      return result ?? false;
    },
  };

  // --- lines (whatsapp_lines) -------------------------------------------------

  const linesFeedback = useAdminFeedback();
  type LinePayload = {
    name: string;
    phone_number_id: string;
    display_phone_number?: string;
    waba_id?: string;
    status: string;
    is_enabled: boolean;
  };
  const lines: AdminCollectionResource<WhatsAppLineRecord> & {
    create(payload: LinePayload, options?: AdminActionOptions): Promise<WhatsAppLineRecord | undefined>;
    update(lineId: number, payload: LinePayload, options?: AdminActionOptions): Promise<WhatsAppLineRecord | undefined>;
    remove(lineId: number, options?: AdminActionOptions): Promise<boolean>;
    requestCalendarConnection(lineId: number, options?: AdminActionOptions): Promise<string | undefined>;
  } = {
    data: computed(() => sessionState.overview?.whatsapp_lines ?? []),
    loading: linesFeedback.loading,
    error: linesFeedback.error,
    success: linesFeedback.success,
    async create(payload, options) {
      const tenantId = currentTenantId();
      if (!tenantId) return undefined;

      return linesFeedback.run(async () => {
        const response = await createWhatsAppLine(tenantId, payload);
        mutateOverview((overview) => {
          overview.whatsapp_lines = upsertById(overview.whatsapp_lines, response.data);
        });
        return response.data;
      }, options);
    },
    async update(lineId, payload, options) {
      const tenantId = currentTenantId();
      if (!tenantId) return undefined;

      return linesFeedback.run(async () => {
        const response = await updateWhatsAppLine(tenantId, lineId, payload);
        mutateOverview((overview) => {
          overview.whatsapp_lines = upsertById(overview.whatsapp_lines, response.data);
        });
        return response.data;
      }, options);
    },
    async remove(lineId, options) {
      const tenantId = currentTenantId();
      if (!tenantId) return false;

      const result = await linesFeedback.run(async () => {
        await deleteWhatsAppLine(tenantId, lineId);
        mutateOverview((overview) => {
          overview.whatsapp_lines = removeById(overview.whatsapp_lines, lineId);
        });
        return true;
      }, options);

      return result ?? false;
    },
    // Only requests the consent URL — the caller navigates the browser to
    // it. The actual `calendar_connection_status` change happens on Google's
    // callback redirect, not here, so this never patches `sessionState`;
    // the view reloads the overview after the browser returns.
    async requestCalendarConnection(lineId, options) {
      const tenantId = currentTenantId();
      if (!tenantId) return undefined;

      return linesFeedback.run(async () => {
        const response = await requestCalendarConsentUrl(tenantId, lineId);
        return response.data.consent_url;
      }, options);
    },
  };

  // --- credentials (credential_metadata) --------------------------------------

  const credentialsFeedback = useAdminFeedback();
  const credentials: AdminCollectionResource<CredentialMetadataRecord> & {
    upsert(
      payload: {
        scope_type: 'tenant' | 'whatsapp_line';
        whatsapp_line_id?: number | null;
        provider: string;
        credential_key: string;
        secret: string;
      },
      options?: AdminActionOptions,
    ): Promise<CredentialMetadataRecord | undefined>;
  } = {
    data: computed(() => sessionState.overview?.credential_metadata ?? []),
    loading: credentialsFeedback.loading,
    error: credentialsFeedback.error,
    success: credentialsFeedback.success,
    async upsert(payload, options) {
      const tenantId = currentTenantId();
      if (!tenantId) return undefined;

      return credentialsFeedback.run(async () => {
        const response = await upsertCredential(tenantId, payload);
        mutateOverview((overview) => {
          overview.credential_metadata = upsertById(overview.credential_metadata, response.data);
        });
        return response.data;
      }, options);
    },
  };

  // --- agentConfigs -------------------------------------------------------------

  const agentConfigsFeedback = useAdminFeedback();
  type AgentConfigPayload = {
    name: string;
    model_key?: string;
    prompt_version?: string;
    is_active: boolean;
    automation_enabled: boolean;
    system_prompt?: string;
    agent_pack_key?: string;
    handoff_customer_message?: string;
  };
  const agentConfigs: AdminCollectionResource<AgentConfigRecord> & {
    updateTenant(payload: AgentConfigPayload, options?: AdminActionOptions): Promise<AgentConfigRecord | undefined>;
    updateLine(
      lineId: number,
      payload: AgentConfigPayload,
      options?: AdminActionOptions,
    ): Promise<AgentConfigRecord | undefined>;
    removeLineOverride(lineId: number, options?: AdminActionOptions): Promise<boolean>;
  } = {
    data: computed(() => sessionState.overview?.agent_configs ?? []),
    loading: agentConfigsFeedback.loading,
    error: agentConfigsFeedback.error,
    success: agentConfigsFeedback.success,
    async updateTenant(payload, options) {
      const tenantId = currentTenantId();
      if (!tenantId) return undefined;

      return agentConfigsFeedback.run(async () => {
        const response = await updateTenantAgentConfig(tenantId, payload);
        mutateOverview((overview) => {
          overview.agent_configs = upsertById(overview.agent_configs, response.data);
        });
        return response.data;
      }, options);
    },
    async updateLine(lineId, payload, options) {
      const tenantId = currentTenantId();
      if (!tenantId) return undefined;

      return agentConfigsFeedback.run(async () => {
        const response = await updateLineAgentConfig(tenantId, lineId, payload);
        mutateOverview((overview) => {
          overview.agent_configs = upsertById(overview.agent_configs, response.data);
        });
        return response.data;
      }, options);
    },
    async removeLineOverride(lineId, options) {
      const tenantId = currentTenantId();
      if (!tenantId) return false;

      const result = await agentConfigsFeedback.run(async () => {
        await deleteLineAgentConfig(tenantId, lineId);
        mutateOverview((overview) => {
          overview.agent_configs = removeWhere(
            overview.agent_configs,
            (config) => config.scope_type === 'whatsapp_line' && config.whatsapp_line_id === lineId,
          );
        });
        return true;
      }, options);

      return result ?? false;
    },
  };

  // --- toolConfigs ----------------------------------------------------------------

  const toolConfigsFeedback = useAdminFeedback();
  type ToolConfigPayload = {
    enabled: boolean;
    timeout_seconds?: number | null;
    data_source_id?: number | null;
  };
  const toolConfigs: AdminCollectionResource<ToolConfigRecord> & {
    updateTenant(
      toolName: string,
      payload: ToolConfigPayload,
      options?: AdminActionOptions,
    ): Promise<ToolConfigRecord | undefined>;
    updateLine(
      lineId: number,
      toolName: string,
      payload: ToolConfigPayload,
      options?: AdminActionOptions,
    ): Promise<ToolConfigRecord | undefined>;
    removeLineOverride(lineId: number, toolName: string, options?: AdminActionOptions): Promise<boolean>;
  } = {
    data: computed(() => sessionState.overview?.tool_configs ?? []),
    loading: toolConfigsFeedback.loading,
    error: toolConfigsFeedback.error,
    success: toolConfigsFeedback.success,
    async updateTenant(toolName, payload, options) {
      const tenantId = currentTenantId();
      if (!tenantId) return undefined;

      return toolConfigsFeedback.run(async () => {
        const response = await updateTenantToolConfig(tenantId, toolName, payload);
        mutateOverview((overview) => {
          overview.tool_configs = upsertById(overview.tool_configs, response.data);
        });
        return response.data;
      }, options);
    },
    async updateLine(lineId, toolName, payload, options) {
      const tenantId = currentTenantId();
      if (!tenantId) return undefined;

      return toolConfigsFeedback.run(async () => {
        const response = await updateLineToolConfig(tenantId, lineId, toolName, payload);
        mutateOverview((overview) => {
          overview.tool_configs = upsertById(overview.tool_configs, response.data);
        });
        return response.data;
      }, options);
    },
    async removeLineOverride(lineId, toolName, options) {
      const tenantId = currentTenantId();
      if (!tenantId) return false;

      const result = await toolConfigsFeedback.run(async () => {
        await deleteLineToolConfig(tenantId, lineId, toolName);
        mutateOverview((overview) => {
          overview.tool_configs = removeWhere(
            overview.tool_configs,
            (config) =>
              config.scope_type === 'whatsapp_line' &&
              config.whatsapp_line_id === lineId &&
              config.tool_name === toolName,
          );
        });
        return true;
      }, options);

      return result ?? false;
    },
  };

  // --- catalogItems (catalog_items) --------------------------------------------------

  const catalogItemsFeedback = useAdminFeedback();
  const catalogItems: AdminCollectionResource<CatalogItemRecord> & {
    create(payload: CatalogItemPayload, options?: AdminActionOptions): Promise<CatalogItemRecord | undefined>;
    update(
      catalogItemId: number,
      payload: CatalogItemPayload,
      options?: AdminActionOptions,
    ): Promise<CatalogItemRecord | undefined>;
    remove(catalogItemId: number, options?: AdminActionOptions): Promise<boolean>;
  } = {
    data: computed(() => sessionState.overview?.catalog_items ?? []),
    loading: catalogItemsFeedback.loading,
    error: catalogItemsFeedback.error,
    success: catalogItemsFeedback.success,
    async create(payload, options) {
      const tenantId = currentTenantId();
      if (!tenantId) return undefined;

      return catalogItemsFeedback.run(async () => {
        const response = await createCatalogItem(tenantId, payload);
        mutateOverview((overview) => {
          overview.catalog_items = upsertById(overview.catalog_items, response.data);
        });
        return response.data;
      }, options);
    },
    async update(catalogItemId, payload, options) {
      const tenantId = currentTenantId();
      if (!tenantId) return undefined;

      return catalogItemsFeedback.run(async () => {
        const response = await updateCatalogItem(tenantId, catalogItemId, payload);
        mutateOverview((overview) => {
          overview.catalog_items = upsertById(overview.catalog_items, response.data);
        });
        return response.data;
      }, options);
    },
    async remove(catalogItemId, options) {
      const tenantId = currentTenantId();
      if (!tenantId) return false;

      const result = await catalogItemsFeedback.run(async () => {
        await deleteCatalogItem(tenantId, catalogItemId);
        mutateOverview((overview) => {
          overview.catalog_items = removeById(overview.catalog_items, catalogItemId);
        });
        return true;
      }, options);

      return result ?? false;
    },
  };

  // --- dataSources ------------------------------------------------------------------

  const dataSourcesFeedback = useAdminFeedback();
  const dataSources: AdminCollectionResource<DataSourceRecord> & {
    upload(payload: { name: string; file: File }, options?: AdminActionOptions): Promise<DataSourceRecord | undefined>;
    retryImport(
      dataSourceId: number,
      importId: number,
      options?: AdminActionOptions,
    ): Promise<DataSourceRecord | undefined>;
  } = {
    data: computed(() => sessionState.overview?.data_sources ?? []),
    loading: dataSourcesFeedback.loading,
    error: dataSourcesFeedback.error,
    success: dataSourcesFeedback.success,
    async upload(payload, options) {
      const tenantId = currentTenantId();
      if (!tenantId) return undefined;

      return dataSourcesFeedback.run(async () => {
        const response = await uploadDataSource(tenantId, payload);
        mutateOverview((overview) => {
          overview.data_sources = upsertById(overview.data_sources, response.data);
        });
        return response.data;
      }, options);
    },
    async retryImport(dataSourceId, importId, options) {
      const tenantId = currentTenantId();
      if (!tenantId) return undefined;

      return dataSourcesFeedback.run(async () => {
        const response = await retryDataSourceImport(tenantId, dataSourceId, importId);
        mutateOverview((overview) => {
          overview.data_sources = upsertById(overview.data_sources, response.data);
        });
        return response.data;
      }, options);
    },
  };

  // --- events (agent_events / audit_events / tool_executions) -----------------------
  // Read-only: the timeline has no mutations, so there is nothing to patch in
  // memory beyond what the overview already loaded.

  const events: AdminSingleResource<AdminOverview['logs']> = {
    data: computed(
      () => sessionState.overview?.logs ?? { agent_events: [], audit_events: [], tool_executions: [] },
    ),
    loading: overviewLoading,
    error: overviewError,
    success: ref<string | null>(null),
  };

  return {
    overview: computed(() => sessionState.overview),
    overviewLoading,
    overviewError,
    reloadOverview,

    availableRoles,
    availableAgentPacks,
    availableModels,
    bindingTools,
    availableTools,

    tenant,
    members,
    lines,
    credentials,
    agentConfigs,
    toolConfigs,
    catalogItems,
    dataSources,
    events,
  };
}

export type { AdminFeedback };
