import { computed, reactive } from 'vue';
import { fetchSession, login, logout } from '../../modules/operations/api';
import type { SessionMembership, SessionResponse } from '../../modules/operations/types';

export type TenantMembership = SessionMembership & {
  tenant_id: number;
  tenant_name: string;
  tenant_slug: string;
};

type SessionState = {
  authError: string | null;
  authLoading: boolean;
  initialized: boolean;
  session: SessionResponse | null;
};

const state = reactive<SessionState>({
  authError: null,
  authLoading: false,
  initialized: false,
  session: null,
});

function resolveErrorMessage(error: unknown, fallback: string): string {
  return error instanceof Error && error.message !== '' ? error.message : fallback;
}

async function refreshSession(): Promise<SessionResponse | null> {
  state.authLoading = true;
  state.authError = null;

  try {
    const payload = await fetchSession();
    state.session = payload;
    return payload;
  } catch (error) {
    state.authError = resolveErrorMessage(error, 'Could not load the session.');
    state.session = null;
    return null;
  } finally {
    state.authLoading = false;
    state.initialized = true;
  }
}

async function ensureSessionLoaded(): Promise<SessionResponse | null> {
  if (state.initialized) {
    return state.session;
  }

  return refreshSession();
}

async function loginWithPassword(email: string, password: string): Promise<SessionResponse | null> {
  state.authLoading = true;
  state.authError = null;

  try {
    const payload = await login(email, password);
    state.session = payload;
    state.initialized = true;
    return payload;
  } catch (error) {
    state.authError = resolveErrorMessage(error, 'Could not sign in.');
    return null;
  } finally {
    state.authLoading = false;
  }
}

async function logoutCurrentSession(): Promise<SessionResponse | null> {
  state.authLoading = true;
  state.authError = null;

  try {
    const payload = await logout();
    state.session = payload;
    state.initialized = true;
    return payload;
  } catch (error) {
    state.authError = resolveErrorMessage(error, 'Could not sign out.');
    return null;
  } finally {
    state.authLoading = false;
  }
}

const currentUser = computed(() => state.session?.user ?? null);
const isAuthenticated = computed(() => state.session?.authenticated === true);
const memberships = computed<TenantMembership[]>(() =>
  (state.session?.memberships ?? []).filter(
    (membership): membership is TenantMembership =>
      membership.tenant_id !== null &&
      membership.tenant_name !== null &&
      membership.tenant_slug !== null,
  ),
);

export function useSessionStore() {
  return {
    state,
    currentUser,
    ensureSessionLoaded,
    isAuthenticated,
    loginWithPassword,
    logoutCurrentSession,
    memberships,
    refreshSession,
  };
}
