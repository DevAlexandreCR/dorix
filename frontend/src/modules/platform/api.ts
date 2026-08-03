import { appConfig } from '../../config/app';
import { ensureCsrfCookie, getJson, postJson, putJson } from '../../lib/api/client';
import type { CredentialMetadataRecord, TenantRecord } from './types';

// Task 5.4 relocation (design.md decision 9): `fetchAdminTenants`,
// `createTenant` and `upsertCredential` are genuinely platform-only —
// creating tenants and editing credential secrets exist only under
// `/platform/**` (design.md decision 11/13, `ui-platform-admin` spec
// "Separación de ámbitos"). They now live here as real implementations,
// not re-exports.
//
// `updateTenant` is the one exception: it stays implemented in
// `modules/admin/api.ts` because the tenant-scoped admin screen
// (`org/info`'s Pausar/Reactivar + name edit, via
// `useAdminResource().tenant.update()`) is its only *current* caller and
// legitimately owns the `PATCH /admin/tenants/{id}` call. This module
// re-exports it for the future `platform/tenants` drawer (task 5.2,
// blocked — see tasks.md) so both surfaces share one implementation
// instead of duplicating the request.
export { updateTenant } from '../admin/api';

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

export async function createTenant(payload: {
  name: string;
  slug: string;
  status: string;
}): Promise<ResourceResponse<TenantRecord>> {
  await ensureCsrfCookie(appConfig.sanctumCsrfCookieUrl);
  return postJson<ResourceResponse<TenantRecord>>(`${appConfig.adminBaseUrl}/tenants`, payload);
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
