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
  CredentialMetadataRecord,
  DataSourceRecord,
  TenantRecord,
  TenantUserRecord,
  ToolConfigRecord,
  WhatsAppLineRecord,
} from './types';

interface CollectionResponse<T> {
  data: T[];
}

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

export function fetchAdminTenants(): Promise<CollectionResponse<TenantRecord>> {
  return getJson<CollectionResponse<TenantRecord>>(`${appConfig.adminBaseUrl}/tenants`);
}

export function fetchAdminOverview(
  tenantId: number,
): Promise<ResourceResponse<AdminOverview>> {
  return getJson<ResourceResponse<AdminOverview>>(`${appConfig.adminBaseUrl}/overview`, {
    headers: tenantHeaders(tenantId),
  });
}

export async function createTenant(payload: {
  name: string;
  slug: string;
  status: string;
}): Promise<ResourceResponse<TenantRecord>> {
  await ensureCsrfCookie(appConfig.sanctumCsrfCookieUrl);
  return postJson<ResourceResponse<TenantRecord>>(`${appConfig.adminBaseUrl}/tenants`, payload);
}

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

export async function upsertCredential(
  tenantId: number,
  payload: {
    scope_type: 'tenant' | 'whatsapp_line';
    whatsapp_line_id?: number | null;
    provider: string;
    credential_key: string;
    secret: string;
  },
): Promise<ResourceResponse<CredentialMetadataRecord>> {
  await ensureCsrfCookie(appConfig.sanctumCsrfCookieUrl);
  return putJson<ResourceResponse<CredentialMetadataRecord>>(
    `${appConfig.adminBaseUrl}/credentials`,
    payload,
    {
      headers: tenantHeaders(tenantId),
    },
  );
}

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
