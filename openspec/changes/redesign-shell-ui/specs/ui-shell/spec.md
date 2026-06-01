# UI Shell — Sabio Cálido (delta)

Defines the visual language and navigation shell for the
authenticated portion of the Dorix frontend. This is a new spec
introduced by `redesign-shell-ui`; there is no prior version.

## Requirements

### Tokens and palette

- The shell **must** expose CSS custom properties on `:root` (dark)
  and `:root[data-theme="light"]` for: `--bg`, `--bg-elev`,
  `--surface`, `--muted`, `--border`, `--border-st`, `--text`,
  `--text-soft`, `--text-mute`, `--text-faint`, `--overlay`,
  accent steps `--accent-50..--accent-900`, and state colors
  `--success`, `--warning`, `--danger`, `--info`.
- Radius **must** be limited to three values exposed as
  `--radius-sm` (6 px), `--radius-md` (10 px), `--radius-lg`
  (14 px). No component may use a hard-coded radius outside this
  set.
- Shadow **must** be limited to four levels: `none`, `xs`, `sm`,
  `md`, exposed as `--shadow-xs|sm|md`.
- Spacing utility classes **must** correspond to a 4-base scale
  (4, 8, 12, 16, 20, 24, 32, 40, 48 px).
- All text/background pairs in both themes **must** satisfy WCAG
  AA contrast (4.5:1 for body text, 3:1 for `≥18 px` or `≥14 px
  bold`). Task 1.6 in tasks.md performs the actual measurement
  and is the authoritative gate; if any pair fails, the
  corresponding token in design.md is updated, not this
  requirement weakened.

### Typography

- The shell **must** apply the Manrope ramp described in the
  design (`display`, `h1`, `h2`, `h3`, `body`, `small`, `micro`).
- No view may introduce ad-hoc text sizes outside the ramp.

### Iconography

- The shell **must** use `lucide-vue-next` for all icons in
  navigation, action menus, and base components.
- Icons **must** be imported individually (named imports) to
  preserve tree shaking.
- Default icon size **must** be `1.25rem` with
  `stroke-width: 1.75`, overridable per component.

### Section navigation

- At viewports `≥ lg`, a vertical SectionNav **must** render each
  authorized section with both an icon and a visible label.
- The SectionNav **must** expose a user-controlled collapsed mode
  (64 px icon-only) on `lg` and `xl` viewports. The collapsed
  state **must** persist in `localStorage` under
  `dorix.shell.sideNav`.
- At viewports `< lg`, the SectionNav **must** be hidden and a
  fixed BottomNav (3 icons, 56 px height) **must** be shown
  instead.
- BottomNav and SectionNav **must** filter sections using
  `useNavigationAccess`. Forbidden sections **must not** appear.

### TopBar

- At viewports `≥ md`, the TopBar **must** render in a single row.
- The TopBar **must** show the section title (`h1`) and the
  current tenant pill on the left.
- The TopBar **must** show an avatar dropdown on the right
  containing user name, email (readonly), theme toggle, locale
  toggle, and logout.
- The TenantSelector **must** render in the TopBar only when the
  authenticated user has more than one membership.

### Motion

- Hover transitions **must** be limited to color and background
  changes, with `150 ms ease-out` duration.
- Buttons **must not** apply translation transforms on hover.
- Theme switching **must** apply a `200 ms ease-out` transition
  on `background-color` and `color` at the document level.
- Focus rings **must** use the accent color at ~22% mix on a 3 px
  outline.
