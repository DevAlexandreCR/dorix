import { ref } from 'vue';
import type { ComputedRef } from 'vue';
import type { TenantMembership } from '../../app/providers/session';
import { useNavigationAccess } from '../../composables/useNavigationAccess';

export const LEGACY_PANEL_REDIRECTS: Record<string, string> = {
  tenant: '/admin/org/info',
  users: '/admin/org/members',
  lines: '/admin/connect/lines',
  credentials: '/admin/connect/credentials',
  sources: '/admin/connect/data',
  bindings: '/admin/connect/data',
  agent: '/admin/assistant/behavior',
  logs: '/admin/activity',
};

export const ADMIN_ROUTE_REQUIRES: Record<string, readonly string[]> = {
  '/admin/org/info': ['canManageTenant', 'canAccessAdmin'],
  '/admin/org/members': ['canManageTenantUsers'],
  '/admin/connect/lines': ['canManageAgentConfig', 'canManageTenant'],
  '/admin/connect/credentials': ['canViewCredentialMetadata'],
  '/admin/connect/data': ['canManageTenant'],
  '/admin/assistant/behavior': ['canManageAgentConfig'],
  '/admin/assistant/tools': ['canManageAgentConfig'],
  '/admin/activity': ['canManageTenant'],
};

export const ADMIN_FALLBACK_ORDER: readonly string[] = [
  '/admin/org/info',
  '/admin/org/members',
  '/admin/connect/lines',
  '/admin/connect/credentials',
  '/admin/connect/data',
  '/admin/assistant/behavior',
  '/admin/assistant/tools',
  '/admin/activity',
];

type NavigationAccess = Record<string, ComputedRef<boolean>>;

export function resolveAdminFallback(membership: TenantMembership | null): string | null {
  const membershipRef = ref(membership);
  const access = useNavigationAccess(membershipRef) as NavigationAccess;

  for (const path of ADMIN_FALLBACK_ORDER) {
    const requires = ADMIN_ROUTE_REQUIRES[path];
    if (!requires) continue;
    const passes = requires.some((key) => access[key]?.value === true);
    if (passes) return path;
  }

  return null;
}
