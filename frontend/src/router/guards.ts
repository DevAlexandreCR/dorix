import type { RouteLocationNormalized, Router } from 'vue-router';
import type { TenantMembership } from '../app/providers/session';
import { useSessionStore } from '../app/providers/session';
import { LEGACY_PANEL_REDIRECTS, resolveAdminFallback } from '../modules/admin/router';

function safeRedirect(value: unknown, fallback: string): string {
  return typeof value === 'string' && value.startsWith('/') && !value.startsWith('//')
    ? value
    : fallback;
}

function resolveSelectedMembership(
  to: RouteLocationNormalized,
  memberships: TenantMembership[],
): TenantMembership | null {
  const raw = to.query.tenant;
  const tenantIdStr = Array.isArray(raw) ? raw[0] : raw;
  const tenantId = tenantIdStr ? Number(tenantIdStr) : NaN;

  if (Number.isInteger(tenantId)) {
    return memberships.find((m) => m.tenant_id === tenantId) ?? null;
  }

  return memberships[0] ?? null;
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

    // Legacy /admin?panel=X redirect
    if (to.path === '/admin' && to.query.panel !== undefined) {
      const panelKey = Array.isArray(to.query.panel) ? to.query.panel[0] : to.query.panel;
      const { panel: _panel, ...queryWithoutPanel } = to.query;
      const target = panelKey ? LEGACY_PANEL_REDIRECTS[panelKey] : undefined;

      if (target) {
        return { path: target, query: queryWithoutPanel };
      }

      // Unknown panel value — drop it so bare /admin fallback walker takes over
      return { path: '/admin', query: queryWithoutPanel };
    }

    // Bare /admin redirect — walk fallback order to find first accessible sub-route
    if (to.path === '/admin') {
      const membership = resolveSelectedMembership(to, session.memberships.value);
      const fallback = resolveAdminFallback(membership);

      if (fallback) {
        return { path: fallback, query: to.query };
      }

      // No accessible sub-route — let the existing AdminView render (handles forbidden state)
      return true;
    }

    return true;
  });
}
