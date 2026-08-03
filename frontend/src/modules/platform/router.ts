// Mirrors modules/admin/router.ts's ADMIN_ROUTE_REQUIRES table (design.md
// decision 2: "/platform/** usa el mismo mecanismo con canManagePlatform").
// router/guards.ts evaluates this with the same `hasRequiredAccess` helper
// admin uses — no parallel guard implementation.
export const PLATFORM_ROUTE_REQUIRES: Record<string, readonly string[]> = {
  '/platform/tenants': ['canManagePlatform'],
  '/platform/credentials': ['canManagePlatform'],
};
