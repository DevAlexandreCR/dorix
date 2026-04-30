import type { Router } from 'vue-router';
import { useSessionStore } from '../app/providers/session';

export function registerRouterGuards(router: Router): void {
  router.beforeEach(async (to) => {
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
      const redirect = typeof to.query.redirect === 'string' ? to.query.redirect : '/operations';

      return redirect;
    }

    return true;
  });
}
