import { appConfig } from '../../config/app';
import { ensureCsrfCookie, getJson, postJson } from '../../lib/api/client';
import type {
  ConversationDetail,
  ConversationSummary,
  ConversationThreadMessage,
  ConversationThreadPayload,
  SessionResponse,
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

export function fetchSession(): Promise<SessionResponse> {
  return getJson<SessionResponse>(appConfig.authSessionUrl);
}

export async function login(email: string, password: string): Promise<SessionResponse> {
  await ensureCsrfCookie(appConfig.sanctumCsrfCookieUrl);

  return postJson<SessionResponse>(appConfig.authLoginUrl, {
    email,
    password,
  });
}

export async function logout(): Promise<SessionResponse> {
  await ensureCsrfCookie(appConfig.sanctumCsrfCookieUrl);

  return postJson<SessionResponse>(appConfig.authLogoutUrl, {});
}

export function fetchConversations(
  tenantId: number,
  params: {
    status?: string;
    search?: string;
    assignedToMe?: boolean;
  } = {},
): Promise<CollectionResponse<ConversationSummary>> {
  const query = new URLSearchParams();

  if (params.status && params.status !== 'ALL') {
    query.set('status', params.status);
  }

  if (params.search) {
    query.set('search', params.search);
  }

  if (params.assignedToMe) {
    query.set('assigned_to_me', '1');
  }

  const queryString = query.toString();
  const url = queryString
    ? `${appConfig.conversationsUrl}?${queryString}`
    : appConfig.conversationsUrl;

  return getJson<CollectionResponse<ConversationSummary>>(url, {
    headers: tenantHeaders(tenantId),
  });
}

export function fetchConversation(
  tenantId: number,
  conversationId: number,
): Promise<ResourceResponse<ConversationThreadPayload>> {
  return getJson<ResourceResponse<ConversationThreadPayload>>(
    `${appConfig.conversationsUrl}/${conversationId}`,
    {
      headers: tenantHeaders(tenantId),
    },
  );
}

export async function handoffConversation(
  tenantId: number,
  conversationId: number,
  payload: {
    assigned_to_user_id?: number;
    reason?: string;
  },
): Promise<ResourceResponse<ConversationDetail>> {
  await ensureCsrfCookie(appConfig.sanctumCsrfCookieUrl);

  return postJson<ResourceResponse<ConversationDetail>>(
    `${appConfig.conversationsUrl}/${conversationId}/handoff`,
    payload,
    {
      headers: tenantHeaders(tenantId),
    },
  );
}

export async function assignConversation(
  tenantId: number,
  conversationId: number,
  assignedToUserId: number,
): Promise<ResourceResponse<ConversationDetail>> {
  await ensureCsrfCookie(appConfig.sanctumCsrfCookieUrl);

  return postJson<ResourceResponse<ConversationDetail>>(
    `${appConfig.conversationsUrl}/${conversationId}/assign`,
    {
      assigned_to_user_id: assignedToUserId,
    },
    {
      headers: tenantHeaders(tenantId),
    },
  );
}

export async function sendManualReply(
  tenantId: number,
  conversationId: number,
  body: string,
): Promise<ResourceResponse<ConversationThreadMessage>> {
  await ensureCsrfCookie(appConfig.sanctumCsrfCookieUrl);

  return postJson<ResourceResponse<ConversationThreadMessage>>(
    `${appConfig.conversationsUrl}/${conversationId}/manual-reply`,
    {
      body,
    },
    {
      headers: tenantHeaders(tenantId),
    },
  );
}

export async function resumeConversation(
  tenantId: number,
  conversationId: number,
  targetStatus: 'BOT_ACTIVE' | 'WAITING_CUSTOMER',
): Promise<ResourceResponse<ConversationDetail>> {
  await ensureCsrfCookie(appConfig.sanctumCsrfCookieUrl);

  return postJson<ResourceResponse<ConversationDetail>>(
    `${appConfig.conversationsUrl}/${conversationId}/resume`,
    {
      target_status: targetStatus,
    },
    {
      headers: tenantHeaders(tenantId),
    },
  );
}
