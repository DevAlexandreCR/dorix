import { appConfig } from '../../config/app';
import {
  deleteJson,
  ensureCsrfCookie,
  getJson,
  patchJson,
  postForm,
  postJson,
  putJson,
} from '../../lib/api/client';
import type {
  AdminOverview,
  AgentConfigRecord,
  CatalogItemKind,
  CatalogItemPriceType,
  CatalogItemRecord,
  DataSourceRecord,
  TenantRecord,
  TenantUserRecord,
  ToolConfigRecord,
  WhatsAppLineRecord,
} from './types';

interface ResourceResponse<T> {
  data: T;
}

type TenantHeaders = {
  'X-Tenant-Id': string;
};

function tenantHeaders(tenantId: number): TenantHeaders {
  return {
    'X-Tenant-Id': String(tenantId),
  };
}

export function fetchAdminOverview(
  tenantId: number,
): Promise<ResourceResponse<AdminOverview>> {
  return getJson<ResourceResponse<AdminOverview>>(`${appConfig.adminBaseUrl}/overview`, {
    headers: tenantHeaders(tenantId),
  });
}

// `createTenant`/`fetchAdminTenants` moved to `modules/platform/api.ts`
// (task 5.4): creating tenants is a platform-only action (design.md
// decision 9/13), there is no tenant-scoped screen that calls them.
// `updateTenant` stays here — see the comment above its definition.

/**
 * Stays in `modules/admin/api.ts` (task 5.4 decision) rather than moving to
 * `modules/platform/api.ts`: it is legitimately owned by the tenant-scoped
 * admin screen (`org/info`'s Pausar/Reactivar and name edit, via
 * `useAdminResource().tenant.update()`), which is the only current caller.
 * `modules/platform/api.ts` re-exports this same function for the future
 * `platform/tenants` drawer (task 5.2, blocked) instead of duplicating the
 * `PATCH /admin/tenants/{id}` call — one implementation, two call sites.
 */
export async function updateTenant(
  tenantId: number,
  payload: {
    name: string;
    slug: string;
    status: string;
  },
): Promise<ResourceResponse<TenantRecord>> {
  await ensureCsrfCookie(appConfig.sanctumCsrfCookieUrl);
  return patchJson<ResourceResponse<TenantRecord>>(
    `${appConfig.adminBaseUrl}/tenants/${tenantId}`,
    payload,
  );
}

export async function createTenantUser(
  tenantId: number,
  payload: {
    name: string;
    email: string;
    password?: string;
    role: string;
  },
): Promise<ResourceResponse<TenantUserRecord>> {
  await ensureCsrfCookie(appConfig.sanctumCsrfCookieUrl);
  return postJson<ResourceResponse<TenantUserRecord>>(
    `${appConfig.adminBaseUrl}/tenant-users`,
    payload,
    {
      headers: tenantHeaders(tenantId),
    },
  );
}

export async function updateTenantUser(
  tenantId: number,
  tenantUserId: number,
  payload: {
    role: string;
  },
): Promise<ResourceResponse<TenantUserRecord>> {
  await ensureCsrfCookie(appConfig.sanctumCsrfCookieUrl);
  return patchJson<ResourceResponse<TenantUserRecord>>(
    `${appConfig.adminBaseUrl}/tenant-users/${tenantUserId}`,
    payload,
    {
      headers: tenantHeaders(tenantId),
    },
  );
}

export async function deleteTenantUser(
  tenantId: number,
  tenantUserId: number,
): Promise<void> {
  await ensureCsrfCookie(appConfig.sanctumCsrfCookieUrl);
  await deleteJson<void>(`${appConfig.adminBaseUrl}/tenant-users/${tenantUserId}`, {
    headers: tenantHeaders(tenantId),
  });
}

export async function createWhatsAppLine(
  tenantId: number,
  payload: {
    name: string;
    phone_number_id: string;
    display_phone_number?: string;
    waba_id?: string;
    status: string;
    is_enabled: boolean;
  },
): Promise<ResourceResponse<WhatsAppLineRecord>> {
  await ensureCsrfCookie(appConfig.sanctumCsrfCookieUrl);
  return postJson<ResourceResponse<WhatsAppLineRecord>>(
    `${appConfig.adminBaseUrl}/whatsapp-lines`,
    payload,
    {
      headers: tenantHeaders(tenantId),
    },
  );
}

export async function updateWhatsAppLine(
  tenantId: number,
  lineId: number,
  payload: {
    name: string;
    phone_number_id: string;
    display_phone_number?: string;
    waba_id?: string;
    status: string;
    is_enabled: boolean;
  },
): Promise<ResourceResponse<WhatsAppLineRecord>> {
  await ensureCsrfCookie(appConfig.sanctumCsrfCookieUrl);
  return patchJson<ResourceResponse<WhatsAppLineRecord>>(
    `${appConfig.adminBaseUrl}/whatsapp-lines/${lineId}`,
    payload,
    {
      headers: tenantHeaders(tenantId),
    },
  );
}

export async function deleteWhatsAppLine(
  tenantId: number,
  lineId: number,
): Promise<void> {
  await ensureCsrfCookie(appConfig.sanctumCsrfCookieUrl);
  await deleteJson<void>(`${appConfig.adminBaseUrl}/whatsapp-lines/${lineId}`, {
    headers: tenantHeaders(tenantId),
  });
}

// Google Calendar connection per line (add-catalog-and-scheduling design.md
// D11): kicks off the OAuth consent flow. Gated server-side by
// `Permission::ManageAgentConfig`, not `tenant.manage` — see
// `useNavigationAccess().canManageAgentConfig` at the call site.
export async function requestCalendarConsentUrl(
  tenantId: number,
  lineId: number,
): Promise<ResourceResponse<{ consent_url: string }>> {
  await ensureCsrfCookie(appConfig.sanctumCsrfCookieUrl);
  return postJson<ResourceResponse<{ consent_url: string }>>(
    `${appConfig.adminBaseUrl}/calendar-connections/${lineId}`,
    {},
    {
      headers: tenantHeaders(tenantId),
    },
  );
}

export async function updateTenantAgentConfig(
  tenantId: number,
  payload: {
    name: string;
    model_key?: string;
    prompt_version?: string;
    is_active: boolean;
    automation_enabled: boolean;
    system_prompt?: string;
    agent_pack_key?: string;
    handoff_customer_message?: string;
  },
): Promise<ResourceResponse<AgentConfigRecord>> {
  await ensureCsrfCookie(appConfig.sanctumCsrfCookieUrl);
  return putJson<ResourceResponse<AgentConfigRecord>>(
    `${appConfig.adminBaseUrl}/agent-configs/tenant`,
    payload,
    {
      headers: tenantHeaders(tenantId),
    },
  );
}

export async function updateLineAgentConfig(
  tenantId: number,
  lineId: number,
  payload: {
    name: string;
    model_key?: string;
    prompt_version?: string;
    is_active: boolean;
    automation_enabled: boolean;
    system_prompt?: string;
    agent_pack_key?: string;
    handoff_customer_message?: string;
  },
): Promise<ResourceResponse<AgentConfigRecord>> {
  await ensureCsrfCookie(appConfig.sanctumCsrfCookieUrl);
  return putJson<ResourceResponse<AgentConfigRecord>>(
    `${appConfig.adminBaseUrl}/agent-configs/lines/${lineId}`,
    payload,
    {
      headers: tenantHeaders(tenantId),
    },
  );
}

export async function deleteLineAgentConfig(
  tenantId: number,
  lineId: number,
): Promise<void> {
  await ensureCsrfCookie(appConfig.sanctumCsrfCookieUrl);
  await deleteJson<void>(`${appConfig.adminBaseUrl}/agent-configs/lines/${lineId}`, {
    headers: tenantHeaders(tenantId),
  });
}

export async function updateTenantToolConfig(
  tenantId: number,
  toolName: string,
  payload: {
    enabled: boolean;
    timeout_seconds?: number | null;
    data_source_id?: number | null;
  },
): Promise<ResourceResponse<ToolConfigRecord>> {
  await ensureCsrfCookie(appConfig.sanctumCsrfCookieUrl);
  return putJson<ResourceResponse<ToolConfigRecord>>(
    `${appConfig.adminBaseUrl}/tool-configs/tenant/${toolName}`,
    payload,
    {
      headers: tenantHeaders(tenantId),
    },
  );
}

export async function updateLineToolConfig(
  tenantId: number,
  lineId: number,
  toolName: string,
  payload: {
    enabled: boolean;
    timeout_seconds?: number | null;
    data_source_id?: number | null;
  },
): Promise<ResourceResponse<ToolConfigRecord>> {
  await ensureCsrfCookie(appConfig.sanctumCsrfCookieUrl);
  return putJson<ResourceResponse<ToolConfigRecord>>(
    `${appConfig.adminBaseUrl}/tool-configs/lines/${lineId}/${toolName}`,
    payload,
    {
      headers: tenantHeaders(tenantId),
    },
  );
}

export async function deleteLineToolConfig(
  tenantId: number,
  lineId: number,
  toolName: string,
): Promise<void> {
  await ensureCsrfCookie(appConfig.sanctumCsrfCookieUrl);
  await deleteJson<void>(`${appConfig.adminBaseUrl}/tool-configs/lines/${lineId}/${toolName}`, {
    headers: tenantHeaders(tenantId),
  });
}

export interface CatalogItemPayload {
  kind: CatalogItemKind;
  name: string;
  category?: string;
  description?: string;
  price_type: CatalogItemPriceType;
  price_amount?: number;
  price_min?: number;
  price_max?: number;
  currency?: string;
  duration_minutes?: number;
  assessment_item_id?: number;
  active: boolean;
}

export async function createCatalogItem(
  tenantId: number,
  payload: CatalogItemPayload,
): Promise<ResourceResponse<CatalogItemRecord>> {
  await ensureCsrfCookie(appConfig.sanctumCsrfCookieUrl);
  return postJson<ResourceResponse<CatalogItemRecord>>(
    `${appConfig.adminBaseUrl}/catalog-items`,
    payload,
    {
      headers: tenantHeaders(tenantId),
    },
  );
}

// `PUT` (full replace), matching `AdminCatalogItemController::update()` —
// unlike the other admin resources above, the backend has no PATCH here,
// so the drawer always sends the complete shape (task 5.1).
export async function updateCatalogItem(
  tenantId: number,
  catalogItemId: number,
  payload: CatalogItemPayload,
): Promise<ResourceResponse<CatalogItemRecord>> {
  await ensureCsrfCookie(appConfig.sanctumCsrfCookieUrl);
  return putJson<ResourceResponse<CatalogItemRecord>>(
    `${appConfig.adminBaseUrl}/catalog-items/${catalogItemId}`,
    payload,
    {
      headers: tenantHeaders(tenantId),
    },
  );
}

export async function deleteCatalogItem(
  tenantId: number,
  catalogItemId: number,
): Promise<void> {
  await ensureCsrfCookie(appConfig.sanctumCsrfCookieUrl);
  await deleteJson<void>(`${appConfig.adminBaseUrl}/catalog-items/${catalogItemId}`, {
    headers: tenantHeaders(tenantId),
  });
}

// `upsertCredential` moved to `modules/platform/api.ts` (task 5.4): the
// only caller of `useAdminResource().credentials.upsert()` is
// `modules/platform/views/CredentialsView.vue` (task 5.3) — the tenant-side
// `admin/connect/CredentialsView.vue` is read-only (task 4.7) and never
// calls it. `tenantHeaders` above stays here because `updateTenant` and
// every other tenant-scoped mutation in this file still needs it.

export async function uploadDataSource(
  tenantId: number,
  payload: {
    name: string;
    file: File;
  },
): Promise<ResourceResponse<DataSourceRecord>> {
  await ensureCsrfCookie(appConfig.sanctumCsrfCookieUrl);

  const formData = new FormData();
  formData.set('name', payload.name);
  formData.set('file', payload.file);

  return postForm<ResourceResponse<DataSourceRecord>>(
    appConfig.dataSourcesUrl,
    formData,
    {
      headers: tenantHeaders(tenantId),
    },
  );
}

export const uploadExcelDataSource = uploadDataSource;

export async function retryDataSourceImport(
  tenantId: number,
  dataSourceId: number,
  importId: number,
): Promise<ResourceResponse<DataSourceRecord>> {
  await ensureCsrfCookie(appConfig.sanctumCsrfCookieUrl);
  return postJson<ResourceResponse<DataSourceRecord>>(
    `${appConfig.dataSourcesUrl}/${dataSourceId}/imports/${importId}/retry`,
    {},
    {
      headers: tenantHeaders(tenantId),
    },
  );
}
