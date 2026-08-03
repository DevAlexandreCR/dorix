import type { RouteLocationNormalized, Router } from 'vue-router';
import type { TenantMembership } from '../app/providers/session';
import { useSessionStore } from '../app/providers/session';
import { hasRequiredAccess, resolveAdminFallback } from '../modules/admin/router';

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

    // Bare /admin redirect — walk fallback order to find first accessible sub-route
    if (to.path === '/admin') {
      const membership = resolveSelectedMembership(to, session.memberships.value);
      const fallback = resolveAdminFallback(membership);

      if (fallback) {
        return { path: fallback, query: to.query };
      }

      // No accessible sub-route — AdminLayout renders ForbiddenState for this flag.
      to.meta.forbidden = true;
      return true;
    }

    // Admin and platform sub-route gating — meta.requires
    // (useNavigationAccess keys, OR semantics). Never redirected (that
    // would risk looping with the bare /admin fallback above): AdminLayout
    // / PlatformLayout read `forbidden` and render ForbiddenState in place
    // of the RouterView, without a URL change. Same mechanism for both
    // prefixes (design.md decision 2: "/platform/** usa el mismo mecanismo
    // con canManagePlatform") — no parallel guard implementation.
    if (to.path.startsWith('/admin/') || to.path.startsWith('/platform/')) {
      const requires = Array.isArray(to.meta.requires) ? (to.meta.requires as string[]) : undefined;

      if (requires) {
        const membership = resolveSelectedMembership(to, session.memberships.value);
        if (!hasRequiredAccess(requires, membership)) {
          to.meta.forbidden = true;
        }
      }
    }

    return true;
  });
}
