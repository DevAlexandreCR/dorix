# UI Admin — Structural redesign (delta)

Defines the structure of the `/admin` section after the rewrite
introduced by `redesign-shell-ui`. This is a new spec; there is no
prior version. Functional behavior of every admin operation is
unchanged — only navigation, permission gating, and information
density are redesigned.

## Requirements

### Route structure

- The admin section **must** expose the following sub-routes under
  `/admin`:
  - `/admin/org/info`
  - `/admin/org/members`
  - `/admin/connect/lines`
  - `/admin/connect/credentials`
  - `/admin/connect/data`
  - `/admin/assistant/behavior`
  - `/admin/assistant/tools`
  - `/admin/activity`
- A visit to `/admin` (no sub-route, no legacy `?panel`) **must**
  redirect to the first sub-route allowed by the current user's
  permissions, evaluated against an ordered fallback list
  codified in `modules/admin/router.ts` as
  `ADMIN_FALLBACK_ORDER`. The order is exactly:
  1. `/admin/org/info`
  2. `/admin/org/members`
  3. `/admin/connect/lines`
  4. `/admin/connect/credentials`
  5. `/admin/connect/data`
  6. `/admin/assistant/behavior`
  7. `/admin/assistant/tools`
  8. `/admin/activity`
  If no sub-route is allowed, `ForbiddenState` **must** render.
- A visit to a legacy `/admin?panel=<key>` URL **must** redirect
  according to this exhaustive mapping, preserving every other
  query parameter:

  | Legacy `panel=` | Redirects to                 |
  |-----------------|------------------------------|
  | `tenant`        | `/admin/org/info`            |
  | `users`         | `/admin/org/members`         |
  | `lines`         | `/admin/connect/lines`       |
  | `credentials`   | `/admin/connect/credentials` |
  | `sources`       | `/admin/connect/data`        |
  | `bindings`      | `/admin/connect/data`        |
  | `agent`         | `/admin/assistant/behavior`  |
  | `logs`          | `/admin/activity`            |
  | unknown value   | `/admin` (then bare redirect) |

### Navigation

- An `AdminNav` component **must** render the four groups
  (Organización, Conectores, Asistente IA, Actividad) and their
  sub-routes as a secondary sidebar at `≥ lg` viewports.
- At `< lg` viewports, `AdminNav` **must** render as a dropdown
  trigger at the top of the admin content area. The trigger
  **must** show the current panel name, open a popover listing
  all visible panels grouped by category, navigate on selection,
  and dismiss on outside tap.
- Each group **must** be omitted from the navigation when **all**
  of its sub-routes are forbidden for the current user. Groups
  are hidden, not disabled.

### Permission gating

- Each admin route **must** declare `meta.requires` as an array
  of strings, where each string is the name of a `computed`
  exported by `useNavigationAccess` (e.g. `'canManageTenant'`).
  Raw permission strings (`'tenant.manage'`) are **not** used in
  `meta.requires`.
- A `beforeEach` router guard **must** evaluate `meta.requires`
  with **OR** semantics: the user passes if at least one entry
  resolves to `true` against
  `useNavigationAccess(selectedMembership)`.
- Direct navigation to a forbidden sub-route **must** render the
  existing `ForbiddenState` component instead of the panel.
- `AdminNav` **must** import the same `meta.requires` mapping
  used by the guard (from `modules/admin/router.ts`) so that
  sidebar filtering and route gating cannot diverge.
- Required keys per route (OR semantics within each row):

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

### Panel header

- Every admin sub-route **must** render a `PanelHeader` that
  contains:
  - A breadcrumb in the form `Admin › <Group> › <Panel>`.
  - The page title as `h1`.
  - One line of contextual copy (`text-soft`) describing the
    purpose of the panel.
- The `/admin/activity` route is the documented exception: it
  has no sub-group, so its breadcrumb **must** render as
  `Admin › Actividad` (two levels). No other route may render a
  two-level breadcrumb.

### Agent panel split

- The legacy combined `panel=agent` view **must** be split into
  two sub-routes:
  - `/admin/assistant/behavior` — prompt, model, agent pack,
    automation flag, handoff customer message.
  - `/admin/assistant/tools` — tool toggles, timeouts, and data
    source bindings.
- Each sub-route **must** expose a `<ScopePicker>` that switches
  between tenant-level configuration and a specific WhatsApp
  line's configuration. `<ScopePicker>` **must** emit a
  `request-switch` event before the scope actually changes, so a
  consumer can intercept and prompt for confirmation when the
  current form is dirty. When the form is dirty, scope switching
  **must** show a confirmation dialog; cancelling **must** keep
  the current scope; confirming **must** discard the unsaved
  changes and proceed with the switch.
- API calls dispatched from these sub-routes **must** continue to
  use the existing endpoints
  (`updateTenantAgentConfig`, `updateLineAgentConfig`,
  `updateTenantToolConfig`, `updateLineToolConfig`,
  `deleteLineAgentConfig`, `deleteLineToolConfig`). No new backend
  routes are introduced by this change.

### Functional parity

- Every action available in the legacy `AdminView.vue`
  (`tenant`, `users`, `lines`, `agent`, `sources`, `credentials`
  panels) **must** remain reachable from a new admin sub-route.
- Loading, error, and success feedback **must** be preserved at
  least at parity with the legacy view; a shared composable is
  acceptable.
- The `logs` placeholder **must** be migrated to
  `/admin/activity` rendering `EmptyState` until the feature is
  implemented.
