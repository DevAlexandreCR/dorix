# Redesign Shell UI — Sabio Cálido

## Why

The current frontend inherits a "DashStack" admin template: navy dark
background with four saturated brand accents (`#4880ff`, `#00b69b`,
`#ffb648`, `#f05a7e`) competing for attention. Cards stack five visual
layers (border + radius + shadow + bg-surface + bg-muted-inside).
Border radii oscillate across 14/18/22/24 px without rhythm. The
SectionNav hides labels in `lg+` (icons only), and the TopBar packs 7+
controls in a single row. AdminView is 1853 lines in one file,
juggling 7 different panels via a `?panel=` query param — including
a mixed `agent` panel that combines tenant-level + per-line agent
configs + tool configs.

The result reads as energetic and busy, not calm. New users cannot
identify sections at a glance, and admins drown in dense
configuration screens.

## What changes

Frontend-only rewrite of the visual language and shell, plus a
structural refactor of `/admin`. Backend, API, and domain layers are
**unchanged**.

1. **Design tokens — Sabio Cálido palette.**
   Warm-cream light (`#FAF7F0`) and warm-charcoal dark (`#161917`)
   backgrounds. One sage-green accent (`#4F7C5C` / `#7BAE89`). Four
   muted states (success/warning/danger/info) used only where
   functional. All critical pairs target WCAG AA minimum; task 1.6
   verifies the actual measured contrast and adjusts any token that
   falls short.
   Consistent scales: 3 radii (6/10/14), 3 shadow levels, 4-base
   spacing, 6-step typographic ramp on Manrope.

2. **Shell — calmer, more legible.**
   - Sidebar 220 px with icon + visible label on `lg+`, collapsible
     to 64 px (user preference).
   - TopBar in a single row: section title + tenant pill on the
     left, avatar dropdown on the right containing theme, locale, and
     logout.
   - Fixed bottom-nav (3 icons) on `<lg` viewports.
   - Lucide icons replace ad-hoc inline SVGs across the shell.

3. **Admin restructure — 4 groups by audience.**
   Sub-routes replace the `?panel=` query param. Each group exposes
   its own sub-routes:
   - `/admin/org/{info,members}` — Organización
   - `/admin/connect/{lines,credentials,data}` — Conectores
   - `/admin/assistant/{behavior,tools}` — Asistente IA
   - `/admin/activity` — Actividad (placeholder, currently the `logs`
     stub)

   The existing `panel=agent` is split into `behavior` and `tools`,
   each with an internal tab toggling tenant-level vs per-line
   configuration. Groups without permission are **hidden** in the
   admin sub-nav, not disabled. Each panel header shows a breadcrumb
   plus one line of contextual copy.

4. **Operations and Sandbox** keep their viewport-locked layouts but
   inherit the new tokens, density, and components. **Zero
   functional changes.**

## Non-goals

- No changes to the backend API, controllers, services, jobs, or
  domain logic.
- No new KPIs, metric widgets, or "home" dashboard. After login the
  user still lands on `/operations`.
- No drag-and-drop layout customization or per-user "dashboard
  builder".
- No identity/logo redesign in this change — the existing two-letter
  brand mark stays as a placeholder.
- No new onboarding tour. `EmptyState` is retuned visually but not
  expanded in scope.
- No locale changes beyond what new strings require.

## Impact

**Affected code (frontend only):**
- `frontend/src/style.css` — full rewrite of tokens and base layers.
- `frontend/src/components/ui/*` — visual refresh of every base
  component using new tokens (no API changes to props/slots).
- `frontend/src/components/shell/*` — SectionNav, TopBar, plus new
  BottomNav and AvatarMenu components.
- `frontend/src/app/AppShell.vue`, `frontend/src/layouts/WorkspaceLayout.vue`
  — adjusted breakpoints and shell composition.
- `frontend/src/modules/admin/*` — replace single `AdminView.vue`
  with route-driven sub-views; split `agent` panel; add `AdminNav`.
- `frontend/src/router/routes.ts` — new admin sub-routes plus a
  redirect from legacy `/admin?panel=X` URLs.
- `frontend/package.json` — add `lucide-vue-next`.

**Unchanged:**
- `backend/**` — entirely untouched.
- `frontend/src/modules/operations/api.ts`, `types.ts` and equivalents
  for sandbox and admin — only the view layer changes.
- Permission composables (`useNavigationAccess`) — unchanged contract.

**Risks:**
- Visual regressions in tenant-customized flows. Mitigated by
  pulling QA checklist across all 7 legacy panels and both themes.
- URL breakage if external links point to `/admin?panel=X`. Mitigated
  by adding a router redirect for every legacy key.
- Bundle size growth from Lucide. Mitigated by importing icons
  individually (tree-shaken).
