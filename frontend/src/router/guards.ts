import type { RouteLocationNormalized, Router } from 'vue-router';
import { useSessionStore } from '../app/providers/session';

function safeRedirect(value: unknown, fallback: string): string {
  return typeof value === 'string' && value.startsWith('/') && !value.startsWith('//')
    ? value
    : fallback;
}

export function registerRouterGuards(router: Router): void {
  router.beforeEach(async (to: RouteLocationNormalized) => {
    const session = useSessionStore();

    await session.ensureSessionLoaded();

    if (to.meta.requiresAuth && !session.isAuthenticated.value) {
      return {
        name: 'login',
        query: {
          redirect: to.fullPath,
        },
      };
    }

    if (to.meta.guestOnly && session.isAuthenticated.value) {
      return safeRedirect(to.query.redirect, '/operations');
    }

    return true;
  });
}
