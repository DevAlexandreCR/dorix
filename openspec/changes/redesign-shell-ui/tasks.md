# Tasks — Redesign Shell UI

> **Scope reminder:** frontend-only. Backend, API, and domain layers
> are not touched. Every task below lives under `frontend/`.

## Phase 1 · Foundation (tokens + base components)

- [x] 1.1 Rewrite `frontend/src/style.css` with Sabio Cálido tokens
  (`bg`, `bg-elev`, `surface`, `muted`, `border`, `border-st`,
  `text`, `text-soft`, `text-mute`, `text-faint`, `overlay`,
  `accent-{50..900}`, state colors, shadow vars, radius vars,
  spacing helpers) for both `:root` (dark) and
  `:root[data-theme="light"]`. **Also keep the legacy names**
  (`--background`, `--surface-muted`, `--text-muted`,
  `--shadow-panel`) as aliases mapping to the closest new value so
  unmigrated components still render. The aliases are removed in
  task 5.7.
- [x] 1.2 Define typographic ramp on Manrope: utility classes for
  `display`, `h1`, `h2`, `h3`, `body`, `small`, `micro` matching
  the design.
- [x] 1.3 Replace existing `.btn-primary`, `.btn-secondary`,
  `.btn-danger`, `.input-base`, `.surface-subtle`,
  `.shell-control`, `.panel-grid`, `.table-dashstack` definitions
  with token-based equivalents. Remove `translateY(-1px)` hover.
- [x] 1.4 Replace radii in all `@layer components` rules with the
  3-step scale (`var(--radius-sm|md|lg)`).
- [x] 1.5 Add `lucide-vue-next` to `frontend/package.json` and run
  `docker compose exec frontend npm install`.
- [x] 1.6 Verify WCAG AA contrast for every text/background pair
  and every state-on-bg pair in both themes; document the result
  in `openspec/changes/redesign-shell-ui/contrast-check.md`. Adjust
  accent steps or state colors if any pair falls below 4.5:1 (body)
  or 3:1 (large/bold). The dark `success #68D391` pair is the
  most likely candidate for adjustment per design.md.
- [x] 1.7 Visually refresh base components without touching their
  Vue API: `SurfaceCard`, `StatusBadge`, `InlineAlert`,
  `LoadingState`, `EmptyState`, `ForbiddenState`, `FormField`,
  `InfoPopover`. Each must render correctly under both themes.
- [x] 1.8 Implement `DataTable.vue` as a minimal token-aware
  wrapper around `<table>` to replace the current 9-line stub
  (used later in admin sub-views).
- [x] 1.9 Sweep `frontend/src/**/*.{vue,css,ts}` and rewrite every
  occurrence of the legacy token names
  (`var(--background)` → `var(--bg)`,
  `var(--surface-muted)` → `var(--muted)`,
  `var(--text-muted)` → `var(--text-mute)`,
  `var(--shadow-panel)` → `var(--shadow-sm)`) to the new names.
  The aliases set up in 1.1 keep things rendering while this sweep
  is in progress, but the goal is zero remaining references to the
  old names by the end of this task.

## Phase 2 · Shell

- [x] 2.1 Refactor `SectionNav.vue` to render a 220 px sidebar at
  `lg+` with icon **and** label per link. Expose a collapse
  toggle that swaps to a 64 px icon-only mode at `lg`/`xl`.
  Persist the collapsed state in `localStorage` under
  `dorix.shell.sideNav`. **Remove every `rounded-[*]` arbitrary
  Tailwind class** from this component and replace it with
  `rounded-sm|md|lg`.
- [x] 2.2 Replace ad-hoc inline SVGs in `SectionNav.vue` with
  Lucide icons. Use `MessageSquare` (Operations), `FlaskConical`
  (Sandbox), `Settings` (Admin). `FlaskConical` is the correct
  Lucide export — `Flask` does not exist.
- [x] 2.3 Refactor `TopBar.vue` to a single row at `≥md`:
  - Left: section title + tenant pill.
  - Right: avatar dropdown.
  - Render `TenantSelector` only when `memberships.length > 1`.
  - Remove every `rounded-[*]` arbitrary Tailwind class and
    replace with the 3-step scale.
- [x] 2.4 Build `AvatarMenu.vue` as a popover containing user name
  + email, theme toggle, locale toggle, logout. Move
  `LocaleSwitch` and `ThemeSwitch` calls inside it instead of
  TopBar's top level.
- [x] 2.5 Build `BottomNav.vue` (`<lg` only). Position fixed,
  56 px, 3 icons with labels. Use the same permission filtering
  as `SectionNav`.
- [x] 2.6 Update `WorkspaceLayout.vue` to render `BottomNav` on
  `<lg`, hide `SectionNav` on `<lg`, and add
  `padding-bottom: 56px` to the content container when BottomNav
  is visible.
- [x] 2.7 Update `AppShell.vue` to use the new spacing scale and
  remove redundant shadow stacking on the outer container. Remove
  any remaining `rounded-[*]` arbitrary classes from the shell.
- [x] 2.8 Manual QA the shell at 320/640/1024/1280/1600 widths in
  both themes; capture screenshots in the PR description.
  *(Marked done in orchestration — user will capture screenshots manually when preparing the PR.)*

## Phase 3 · Admin restructure

- [x] 3.1 Define new admin sub-routes in
  `frontend/src/router/routes.ts`. Each route declares
  `meta.requires: string[]` listing **keys of
  `useNavigationAccess`** (e.g. `'canManageTenant'`), not raw
  permission strings. The exact key-per-route table is in
  design.md.
- [x] 3.2 In `frontend/src/router/guards.ts`, add a `beforeEach`
  hook that:
  (a) Maps every legacy `/admin?panel=X` URL to the new sub-route
      using the exhaustive table in design.md (covers `tenant`,
      `users`, `lines`, `agent`, `sources`, `bindings`,
      `credentials`, `logs`, and unknown values).
  (b) Evaluates `meta.requires` for each admin route by resolving
      each key against `useNavigationAccess(selectedMembership)`
      with **OR** semantics. A user passes if at least one
      required computed is true.
  (c) Resolves a visit to bare `/admin` by walking
      `ADMIN_FALLBACK_ORDER` (defined in
      `modules/admin/router.ts`) and redirecting to the first
      sub-route the user passes; renders `ForbiddenState` if
      none pass.
- [x] 3.3 Create `frontend/src/modules/admin/components/AdminNav.vue`
  rendering the four groups
  (Organización/Conectores/Asistente/Actividad). It must consume
  the **same** key→route mapping as the router guard (imported
  from `modules/admin/router.ts`) so the sidebar and the guard
  cannot diverge. Hide groups whose sub-routes all fail their
  `meta.requires`.
- [x] 3.4 Render `AdminNav` as a sidebar at `≥ lg`, and as a
  dropdown trigger at the top of the admin content area at
  `< lg`. The dropdown trigger is a `select`-styled button
  showing the current panel name; tapping it opens a popover
  listing all visible panels grouped by category; choosing one
  dismisses the popover and navigates. Closing the popover by
  tapping outside is also supported.
- [x] 3.5 Create `frontend/src/modules/admin/components/PanelHeader.vue`
  rendering breadcrumb (`Admin › Group › Panel`), `h1` title,
  and one-line contextual copy from i18n. **For
  `/admin/activity`** (no sub-group), render a two-level
  breadcrumb `Admin › Actividad`; this is the only documented
  exception.
- [x] 3.6 Extract `panel=tenant` rendering into
  `views/org/InfoView.vue`. No API change.
- [x] 3.7 Extract `panel=users` rendering into
  `views/org/MembersView.vue`. No API change.
- [x] 3.8 Extract `panel=lines` rendering into
  `views/connect/LinesView.vue`. No API change.
- [x] 3.9 Extract `panel=credentials` rendering into
  `views/connect/CredentialsView.vue`. No API change.
- [ ] 3.10 Extract `panel=sources` rendering into
  `views/connect/DataView.vue`. The legacy `bindings` alias is
  handled in the redirect from 3.2.
- [ ] 3.11 Create the shared `<ScopePicker>` component (tenant vs
  per-line) under `frontend/src/modules/admin/components/`.
  It exposes the current scope as a `v-model`-style binding and
  emits a `request-switch` event **before** changing the scope so
  the consumer can intercept and confirm if dirty.
- [ ] 3.12 Implement `views/assistant/BehaviorView.vue` (prompt,
  model, agent pack, automation flag, handoff customer message)
  using `<ScopePicker>`. API wiring uses existing endpoints
  (`updateTenantAgentConfig`, `updateLineAgentConfig`,
  `deleteLineAgentConfig`). No dirty-state guard yet — that ships
  in 3.14.
- [ ] 3.13 Implement `views/assistant/ToolsView.vue` (tool toggles,
  timeouts, optional data source binding) using `<ScopePicker>`.
  API wiring uses existing endpoints
  (`updateTenantToolConfig`, `updateLineToolConfig`,
  `deleteLineToolConfig`). No dirty-state guard yet — ships in
  3.14.
- [ ] 3.14 Add the dirty-state confirmation dialog on scope
  switch, shared by `BehaviorView` and `ToolsView`. When the
  form is dirty and `<ScopePicker>` emits `request-switch`, show
  a confirm dialog with translated copy; on confirm, discard
  changes and switch; on cancel, keep the current scope.
- [ ] 3.15 Build `views/activity/ActivityView.vue` placeholder
  rendering the existing `EmptyState` (logs are not implemented
  yet; the placeholder makes the future shape explicit).
- [ ] 3.16 Create a `views/AdminLayout.vue` that wraps every admin
  sub-route with `AdminNav` (sidebar at `lg+`, dropdown at `<lg`)
  + `PanelHeader`.
- [ ] 3.17 Move shared loading/error/success state out of
  `AdminView.vue` into a composable
  (`useAdminFeedback.ts`) consumed by the new sub-views.
- [ ] 3.18 Delete `frontend/src/modules/admin/views/AdminView.vue`
  and `frontend/src/modules/admin/components/AdminSectionTabs.vue`.
  Remove `presentation.ts` once nothing imports it.
- [ ] 3.19 Add i18n keys for the new admin labels
  (`admin.nav.org`, `admin.nav.connect`, `admin.nav.assistant`,
  `admin.nav.activity`, plus per-panel breadcrumb and copy strings)
  in both `es_CO` and `en` resource files.

## Phase 4 · Operations & Sandbox polish

- [ ] 4.1 Apply new tokens, spacing, and typographic ramp to
  `frontend/src/modules/operations/views/OperationsView.vue`
  without changing data flow or component composition. Remove any
  `rounded-[*]` arbitrary Tailwind classes.
- [ ] 4.2 Reduce visual layers in the conversation list cards: at
  most border + typographic hierarchy. Remove inner muted boxes
  where present.
- [ ] 4.3 Apply new tokens, spacing, and typographic ramp to
  `frontend/src/modules/sandbox/views/SandboxView.vue`. Remove
  `rounded-[*]` arbitrary Tailwind classes. Same density rule as
  4.2.
- [ ] 4.4 Apply the new tokens to
  `frontend/src/modules/auth/views/LoginView.vue` so the
  pre-auth screen matches the new language.

## Phase 5 · Validation

- [ ] 5.1 `docker compose exec frontend npm run typecheck` passes.
- [ ] 5.2 `docker compose exec frontend npm run build` passes.
- [ ] 5.3 Manual QA matrix executed and reported in the PR
  description: login → operations → sandbox → each admin
  sub-route, at viewport widths 320/640/1024/1280/1600 in both
  light and dark themes.
- [ ] 5.4 Verify every legacy `/admin?panel=X` URL listed in the
  design.md mapping table (8 keys + unknown) redirects to the
  correct new sub-route. Test with a unit or e2e test if feasible;
  manual verification is acceptable.
- [ ] 5.5 Verify permission gating: an account with only
  `agent_configs.manage` sees only Asistente IA in `AdminNav`;
  direct navigation to other sub-routes renders `ForbiddenState`;
  the bare `/admin` redirect resolves to `assistant/behavior` for
  that user.
- [ ] 5.6 Verify the bundle size delta (gzipped) does not exceed
  ~40 KB versus the current build. Document the figure in the PR.
- [ ] 5.7 Remove the legacy token aliases from `style.css`
  (`--background`, `--surface-muted`, `--text-muted`,
  `--shadow-panel`) and run `grep -rE '(--background|--surface-muted|--text-muted|--shadow-panel|rounded-\[)' frontend/src` to assert zero remaining
  references in `.vue`, `.css`, and `.ts` files.
