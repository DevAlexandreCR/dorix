import { ref } from 'vue';
import type { ComputedRef } from 'vue';
import type { TenantMembership } from '../../app/providers/session';
import { useNavigationAccess } from '../../composables/useNavigationAccess';

export const ADMIN_ROUTE_REQUIRES: Record<string, readonly string[]> = {
  '/admin/org/info': ['canManageTenant'],
  '/admin/org/members': ['canManageTenantUsers'],
  '/admin/connect/lines': ['canManageTenant'],
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

/**
 * Evaluates a `meta.requires` list (OR semantics) against a membership's
 * permissions. Shared by the router guard, `resolveAdminFallback` and
 * `AdminNav` so access decisions can never diverge between them.
 */
export function hasRequiredAccess(
  requires: readonly string[] | undefined,
  membership: TenantMembership | null,
): boolean {
  if (!requires || requires.length === 0) return false;
  const membershipRef = ref(membership);
  const access = useNavigationAccess(membershipRef) as NavigationAccess;
  return requires.some((key) => access[key]?.value === true);
}

export function resolveAdminFallback(membership: TenantMembership | null): string | null {
  for (const path of ADMIN_FALLBACK_ORDER) {
    if (hasRequiredAccess(ADMIN_ROUTE_REQUIRES[path], membership)) return path;
  }

  return null;
}
