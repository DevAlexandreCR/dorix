import { appConfig } from '../../config/app';
import { ensureCsrfCookie, getJson, postJson } from '../../lib/api/client';
import type {
  SandboxLineOption,
  SandboxSessionPayload,
  SandboxSessionSummary,
} from './types';

interface CollectionResponse<T> {
  data: T[];
  meta?: {
    available_lines?: SandboxLineOption[];
  };
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

export function fetchSandboxSessions(
  tenantId: number,
): Promise<CollectionResponse<SandboxSessionSummary>> {
  return getJson<CollectionResponse<SandboxSessionSummary>>(
    `${appConfig.agentSandboxBaseUrl}/sessions`,
    {
      headers: tenantHeaders(tenantId),
    },
  );
}

export async function createSandboxSession(
  tenantId: number,
  payload: {
    whatsapp_line_id: number;
    label?: string;
  },
): Promise<ResourceResponse<SandboxSessionPayload>> {
  await ensureCsrfCookie(appConfig.sanctumCsrfCookieUrl);

  return postJson<ResourceResponse<SandboxSessionPayload>>(
    `${appConfig.agentSandboxBaseUrl}/sessions`,
    payload,
    {
      headers: tenantHeaders(tenantId),
    },
  );
}

export function fetchSandboxSession(
  tenantId: number,
  conversationId: number,
): Promise<ResourceResponse<SandboxSessionPayload>> {
  return getJson<ResourceResponse<SandboxSessionPayload>>(
    `${appConfig.agentSandboxBaseUrl}/sessions/${conversationId}`,
    {
      headers: tenantHeaders(tenantId),
    },
  );
}

export async function sendSandboxMessage(
  tenantId: number,
  conversationId: number,
  body: string,
): Promise<ResourceResponse<SandboxSessionPayload>> {
  await ensureCsrfCookie(appConfig.sanctumCsrfCookieUrl);

  return postJson<ResourceResponse<SandboxSessionPayload>>(
    `${appConfig.agentSandboxBaseUrl}/sessions/${conversationId}/messages`,
    {
      body,
    },
    {
      headers: tenantHeaders(tenantId),
    },
  );
}

export async function closeSandboxSession(
  tenantId: number,
  conversationId: number,
): Promise<ResourceResponse<SandboxSessionPayload>> {
  await ensureCsrfCookie(appConfig.sanctumCsrfCookieUrl);

  return postJson<ResourceResponse<SandboxSessionPayload>>(
    `${appConfig.agentSandboxBaseUrl}/sessions/${conversationId}/close`,
    {},
    {
      headers: tenantHeaders(tenantId),
    },
  );
}
