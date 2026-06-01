# Design — Redesign Shell UI

## Constraints

- **Frontend-only.** Backend, API contracts, permissions, queue
  jobs, and domain logic stay as-is. No Eloquent or controller
  edits.
- **No regressions** in feature parity: every action available
  today in Operations, Sandbox, and Admin must remain reachable
  after the change.
- **WCAG AA minimum** for all foreground/background pairs in both
  themes. AAA where reachable without compromising the palette.
- **No reliance on icon fonts.** Vector components only.
- **Bundle budget:** the redesign must not add more than ~40 KB
  gzipped to the production bundle (Lucide tree-shaken is well
  under that limit).

## Design tokens — Sabio Cálido

### Neutrals

| Token        | Light       | Dark        |
|--------------|-------------|-------------|
| `bg`         | `#FAF7F0`   | `#161917`   |
| `bg-elev`    | `#FFFFFF`   | `#1E2220`   |
| `surface`    | `#FFFFFF`   | `#232723`   |
| `muted`      | `#F2EDE2`   | `#2C302D`   |
| `border`     | `#E6DFCE`   | `#353A37`   |
| `border-st`  | `#D6CDB8`   | `#4A504D`   |
| `text`       | `#1C2024`   | `#ECEEEB`   |
| `text-soft`  | `#3F4549`   | `#C6CAC7`   |
| `text-mute`  | `#6B6E72`   | `#9AA09D`   |
| `text-faint` | `#98999C`   | `#6E7470`   |
| `overlay`    | `rgba(28,24,18,0.55)` | `rgba(0,0,0,0.65)` |

### Accent (sage green) — scale

| Step | Light     | Dark      |
|------|-----------|-----------|
| 50   | `#F0F5F1` | `#1A2820` |
| 100  | `#DDE9DF` | `#233329` |
| 200  | `#B8D2BD` | `#355244` |
| 300  | `#8FB89A` | `#5C8C70` |
| 400  | `#6B9D7C` | `#7BAE89` *(dark accent)* |
| 500  | `#4F7C5C` *(light accent)* | `#95C3A1` |
| 600  | `#3D6248` | `#B0D4B9` |
| 700  | `#2E4A37` | `#C8E2CE` |
| 900  | `#1F3325` | `#E1EFE4` |

### States (muted, used only where functional)

| State    | Light     | Dark      |
|----------|-----------|-----------|
| success  | `#2F855A` | `#68D391` |
| warning  | `#B7791F` | `#ECC94B` |
| danger   | `#9B2C2C` | `#FC8181` |
| info     | `#2C5282` | `#90CDF8` |

**Contrast verification is performed in task 1.6.** Approximate
pre-checks against `bg #161917` (dark): `success #68D391` ≈ 3.55:1
(below AA body), `danger #FC8181` ≈ 4.6:1 (marginal), `warning`
and `info` pass comfortably. If task 1.6 confirms the failure for
`success`, raise it to a darker-but-still-legible step (candidates:
`#48BB78` ≈ 4.6:1, `#38A169` ≈ 4.5:1) and update this table.
Equivalent re-check is required for every state-on-bg and
state-on-bg-elev pair, light and dark.

### Scales

- **Radius:** `sm 6` · `md 10` · `lg 14`. Replaces 14/18/22/24.
- **Shadow:** `none` · `xs 0 1px 2px rgba(28,24,18,.04)` ·
  `sm 0 4px 12px rgba(28,24,18,.06)` · `md 0 12px 32px rgba(28,24,18,.08)`.
  Dark variants use `rgba(0,0,0,X)` with slightly higher alpha.
- **Spacing:** 4-base scale `1..12` → 4,8,12,16,20,24,32,40,48 px.
- **Typography (Manrope):**
  - `display 32/40 600`
  - `h1 24/32 600`
  - `h2 18/28 600`
  - `h3 15/22 600`
  - `body 14/22 400` (default)
  - `small 13/18 500`
  - `micro 11/16 600` (uppercase chips, brand)

### Motion

- Hover transitions: `150ms ease-out`, color/background only.
- Focus rings: `0 0 0 3px color-mix(in srgb, var(--accent) 22%, transparent)`.
- Theme switch: full-document transition of `200ms ease-out` on
  `background-color` and `color`.
- No `translateY` micro-bumps on hover (current behavior is removed).

## Shell architecture

### Layout

```
┌─────────────────────────────────────────────────────────────┐
│  AppShell  ·  bg = var(--bg)                                │
│  ┌──────────────────────────────────────────────────────┐   │
│  │  WorkspaceLayout                                     │   │
│  │  ┌──────────┬─────────────────────────────────────┐  │   │
│  │  │ SideNav  │  TopBar (1 row)                     │  │   │
│  │  │  220 px  ├─────────────────────────────────────┤  │   │
│  │  │  (lg+)   │  <router-view />                    │  │   │
│  │  │          │                                     │  │   │
│  │  └──────────┴─────────────────────────────────────┘  │   │
│  └──────────────────────────────────────────────────────┘   │
│                                                             │
│  <lg viewport:                                              │
│  ┌──────────────────────────────────────────────────────┐   │
│  │  TopBar (compact)                                    │   │
│  │  <router-view />                                     │   │
│  │  ───── BottomNav (fixed, 56 px) ─────                │   │
│  └──────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
```

### Breakpoints

| Width        | Sidebar         | TopBar               | Cards      |
|--------------|-----------------|----------------------|------------|
| `< 640`      | hidden (BottomNav) | title + avatar    | 1 col stack |
| `640..1024`  | hidden (BottomNav) | full 1 row        | 1 col stack |
| `1024..1280` | 64 px collapsed (icons) | full 1 row    | 2 col      |
| `≥ 1280`     | 220 px with labels | full 1 row         | 2–3 col    |

The user-controlled collapse toggle is hidden on `<lg` and on
`≥xl`; it is exposed only on `lg`–`xl` where both states make
sense.

### TopBar

- Left: section title (`h1`) + tenant pill (chip with tenant name).
- Right: avatar dropdown. Menu items: name + email (readonly),
  divider, theme toggle, locale toggle, divider, logout.
- The TenantSelector appears in TopBar **only when the user has
  more than one membership**. With one membership, the tenant pill
  to the left is enough.

### BottomNav

- Three icons (Operations, Sandbox if authorized, Admin if
  authorized). Active state uses accent color and a 2 px top
  indicator.
- `position: fixed`, `bottom: 0`, `height: 56 px`. The
  router-view container gets `padding-bottom: 56 px` when the
  BottomNav is visible, so content never hides under it.

## Admin restructure

### Route shape

```
/admin                              redirect → first allowed sub-route
/admin/org/info                     ← was ?panel=tenant
/admin/org/members                  ← was ?panel=users
/admin/connect/lines                ← was ?panel=lines
/admin/connect/credentials          ← was ?panel=credentials
/admin/connect/data                 ← was ?panel=sources (+bindings)
/admin/assistant/behavior           ← was ?panel=agent (default split)
/admin/assistant/tools              ← was ?panel=agent (tools half)
/admin/activity                     ← was ?panel=logs (stub)
```

### Legacy `?panel=` redirect map

A router `beforeEach` hook resolves every legacy `/admin?panel=X`
URL using this exhaustive table. All other query parameters are
preserved on the new URL.

| Legacy `panel=`  | Redirect target               |
|------------------|-------------------------------|
| `tenant`         | `/admin/org/info`             |
| `users`          | `/admin/org/members`          |
| `lines`          | `/admin/connect/lines`        |
| `credentials`    | `/admin/connect/credentials`  |
| `sources`        | `/admin/connect/data`         |
| `bindings`       | `/admin/connect/data`         |
| `agent`          | `/admin/assistant/behavior`   |
| `logs`           | `/admin/activity`             |
| (missing / unknown) | `/admin` (then bare redirect) |

### Bare `/admin` redirect — ordered fallback

A visit to `/admin` (no sub-route, no legacy `?panel`) **must** be
resolved by evaluating this **ordered** list and redirecting to the
first sub-route whose permission requirement passes for the current
user:

1. `/admin/org/info`
2. `/admin/org/members`
3. `/admin/connect/lines`
4. `/admin/connect/credentials`
5. `/admin/connect/data`
6. `/admin/assistant/behavior`
7. `/admin/assistant/tools`
8. `/admin/activity`

If none passes, render `ForbiddenState`. The ordered list is
codified in a constant `ADMIN_FALLBACK_ORDER` in
`frontend/src/modules/admin/router.ts` so the order is reviewable
and stable independent of route declaration order.

### Permission gating mechanism

Routes declare `meta.requires` as an array of **keys from
`useNavigationAccess`** (the names of the exported `computed`
booleans), not raw permission strings. Example:

```ts
{
  path: '/admin/connect/credentials',
  component: () => import('.../CredentialsView.vue'),
  meta: { requires: ['canViewCredentialMetadata'] },
}
```

A new `beforeEach` hook in `router/guards.ts` resolves each entry
in `meta.requires` against `useNavigationAccess(selectedMembership)`.
Multiple entries are joined with **OR** semantics, matching the
spec table (e.g. `connect/lines` requires
`canManageAgentConfig OR canManageTenant`).

The exhaustive key→requirement mapping is:

| Sub-route             | `meta.requires`                                  |
|-----------------------|--------------------------------------------------|
| `org/info`            | `['canManageTenant', 'canAccessAdmin']`          |
| `org/members`         | `['canManageTenantUsers']`                       |
| `connect/lines`       | `['canManageAgentConfig', 'canManageTenant']`    |
| `connect/credentials` | `['canViewCredentialMetadata']`                  |
| `connect/data`        | `['canManageTenant']`                            |
| `assistant/behavior`  | `['canManageAgentConfig']`                       |
| `assistant/tools`     | `['canManageAgentConfig']`                       |
| `activity`            | `['canManageTenant']`                            |

`AdminNav` uses the **same mapping table** (imported from the
same module) so the sidebar's filtering and the router's gating
cannot diverge.

### Permission gating

Each admin sub-route declares a `meta.requires` array of permission
keys. The `AdminNav` sidebar filters its groups using the same
helpers in `useNavigationAccess`. A group whose every entry fails
the permission check is **omitted** from the sub-nav, not rendered
disabled. A direct visit to a forbidden sub-route resolves to
`ForbiddenState`.

| Group       | Sub-route                | Requires                       |
|-------------|--------------------------|--------------------------------|
| Organización | `org/info`              | `tenant.manage` (read sufficient) |
| Organización | `org/members`           | `tenant_users.manage`          |
| Conectores  | `connect/lines`         | `agent_configs.manage` ∨ `tenant.manage` |
| Conectores  | `connect/credentials`   | `credentials.view_metadata`    |
| Conectores  | `connect/data`          | `tenant.manage`                |
| Asistente   | `assistant/behavior`    | `agent_configs.manage`         |
| Asistente   | `assistant/tools`       | `agent_configs.manage`         |
| Actividad   | `activity`              | `tenant.manage` (placeholder)  |

### Splitting the `agent` panel

The legacy `panel=agent` mixes four concerns:

1. Tenant-level agent config (prompt, model, pack, automation).
2. Per-line agent override (one config per WhatsApp line).
3. Tenant-level tool config (toggle + timeout + data source).
4. Per-line tool override.

The new split makes the scope explicit:

```
/admin/assistant/behavior
   ┌─────────────────────────────────────┐
   │ Scope:  [ Tenant ] [ Per line ▼ ]   │
   ├─────────────────────────────────────┤
   │ Single form (prompt, model, pack,   │
   │ automation, handoff message)        │
   └─────────────────────────────────────┘

/admin/assistant/tools
   ┌─────────────────────────────────────┐
   │ Scope:  [ Tenant ] [ Per line ▼ ]   │
   ├─────────────────────────────────────┤
   │ List of available tools with toggle │
   │ and per-tool config (timeout,       │
   │ optional data source binding)       │
   └─────────────────────────────────────┘
```

Both views share an internal `<ScopePicker>` component that exposes
the current scope (tenant or a chosen line) to its children. API
calls dispatch to the existing endpoints
(`updateTenantAgentConfig` / `updateLineAgentConfig`, etc.) — the
backend contract is unchanged.

### Panel header pattern

Every admin sub-route renders a `<PanelHeader>` with:

- Breadcrumb: `Admin › <Group> › <Panel>`.
- Page title (`h1`).
- One line of contextual copy (`text-soft`, max ~120 chars).

This replaces the current horizontal scrolling tabs as the primary
location indicator.

## Component refresh

Components that need a visual pass (no API changes to props/slots):

- `SurfaceCard` — borders + radius only, no shadow stacking.
- `StatusBadge` — uses muted state palette.
- `InlineAlert` — uses muted state palette + 1px left accent.
- `LoadingState`, `EmptyState`, `ForbiddenState` — typography pass.
- `FormField` — labels use `small`, helper text uses `text-soft`.
- `InfoPopover` — softer shadow.
- `DataTable` (currently a 9-line stub) — to be implemented as a
  thin wrapper around a native `<table>` with the new typographic
  scale.
- `btn-primary` / `btn-secondary` / `btn-danger` — new tokens, no
  hover translate.
- `input-base` — new focus ring.

## Iconography

`lucide-vue-next` is added as a dependency. Icons are imported
individually for tree-shaking:

```ts
import { MessageSquare, FlaskConical, Settings } from 'lucide-vue-next';
```

The `FlaskConical` name is intentional: Lucide does not export a
plain `Flask`. Other icon names used in the shell migration are
listed in tasks.md 2.2.

The SVGs currently inlined in `SectionNav.vue` are replaced. All
icons render at `stroke-width: 1.75` and `1.25rem` size unless a
component states otherwise, for visual consistency.

## Token rename strategy

The existing `style.css` defines tokens under names that this
change retires (`--background`, `--surface-muted`, `--text-muted`,
`--shadow-panel`). The new names (`--bg`, `--bg-elev`, `--surface`,
`--muted`, `--text-soft`, `--text-mute`, `--text-faint`,
`--shadow-xs|sm|md`) are different. Existing components reference
the old names directly in their templates (e.g. `TopBar.vue` uses
`var(--text-muted)`).

To prevent silent visual regressions during the migration, the
strategy is:

1. **Task 1.1** defines the new tokens **and** keeps the old token
   names as aliases that resolve to the closest new value
   (e.g. `--background: var(--bg);`,
   `--text-muted: var(--text-mute);`,
   `--shadow-panel: var(--shadow-sm);`).
2. **A new task (1.9)** runs a grep across `frontend/src/**/*.{vue,css,ts}`
   for occurrences of the old token names and rewrites each to the
   new equivalent, in a single sweep.
3. **Task 5.7** removes the alias block from `style.css` and runs
   the grep again to assert zero remaining usages of the old names.

This means a partially completed migration still renders correctly
in both themes.

### Tailwind arbitrary radii in shell components

Current shell components use Tailwind arbitrary-value classes
(`rounded-[14px]`, `rounded-[18px]`, `rounded-[22px]`,
`rounded-[24px]`). These are not in `@layer components` and
therefore are not captured by task 1.4. Tasks 2.1, 2.3, and 2.7
explicitly remove all `rounded-[*]` arbitrary classes from shell
components and replace them with the 3-step radius scale
(`rounded-md` for inputs/buttons/small cards, `rounded-lg` for
panels/modals/sidebars, `rounded-sm` for chips/badges). A final
sweep in task 5.7 asserts no `rounded-\[` arbitrary values remain
in `frontend/src/**/*.vue`.

## Migration approach

The change is large but the cost of staging it under a feature flag
exceeds the value: it is purely cosmetic with no behavioral
toggles, and a half-migrated state would itself be a regression.
The migration is therefore staged by phase but lands as a single
branch:

1. **Foundation** — tokens, base components, icons. Visible
   immediately across all three sections; safe because no view
   logic changes.
2. **Shell** — SideNav expand, TopBar refactor, BottomNav. Visible
   change to navigation; covered by manual QA on each viewport.
3. **Admin refactor** — sub-routes, AdminNav, agent split. Largest
   risk; covered by mapping every legacy panel to its new route
   plus the redirect.
4. **Operations / Sandbox polish** — apply new density and tokens
   to existing views without touching their logic.
5. **Validation** — type check, build, manual QA matrix.

## Rejected alternatives

- **Keep one admin page with internal tabs but re-skin it.**
  Reduces effort but does not address the root issue (1853-line
  file with mixed concerns). Rejected because "calm" requires
  reducing per-screen density, not just changing colors.
- **Bottom-nav drawer hamburger on mobile.** Saves 56 px of
  viewport but adds a click for every section change in a context
  (mobile inbox) where fast nav matters more than vertical space.
  Rejected.
- **Drop dark mode to reduce complexity.** Many operators work in
  low-light contexts; dropping dark mode would be a real
  regression. Both themes are first-class.
- **Adopt Phosphor icons instead of Lucide.** Phosphor is bigger
  and offers more styles than this app needs. Lucide's single
  stroke style is the better fit for "calm" and "professional".
