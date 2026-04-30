import { computed } from 'vue';
import { useSessionStore } from '../app/providers/session';

export function useSession() {
  const store = useSessionStore();

  return {
    authError: computed(() => store.state.authError),
    authLoading: computed(() => store.state.authLoading),
    currentUser: store.currentUser,
    ensureSessionLoaded: store.ensureSessionLoaded,
    initialized: computed(() => store.state.initialized),
    isAuthenticated: store.isAuthenticated,
    loginWithPassword: store.loginWithPassword,
    logoutCurrentSession: store.logoutCurrentSession,
    memberships: store.memberships,
    refreshSession: store.refreshSession,
    session: computed(() => store.state.session),
  };
}
