# 03 · Design System "Pulso"

Identidad: **papel frío + tinta, acento cobalto, verde semántico de
conexión, monospace para lo técnico, densidad operativa.** El nombre viene del
pulso de conversaciones que el negocio atiende por WhatsApp.

## 1. Color

### Neutrales

| Token | Light (default) | Dark |
|---|---|---|
| `--bg` | `#F6F7F9` | `#101216` |
| `--surface` | `#FFFFFF` | `#171A20` |
| `--muted` | `#EFF1F4` | `#1E222A` |
| `--border` | `#E2E5EA` | `#2A2F3A` |
| `--border-st` | `#C9CED7` | `#3B4250` |
| `--text` | `#16181D` | `#E9EBEF` |
| `--text-soft` | `#3D434E` | `#C2C7D1` |
| `--text-mute` | `#5F6672` | `#98A0AE` |
| `--overlay` | `rgba(22,24,29,.5)` | `rgba(0,0,0,.65)` |

Dos fondos (página y superficie + muted para zonas hundidas) y tres
niveles de texto: no existe un nivel de texto "casi ilegible" — si un
texto merece estar, merece leerse (P1).

### Acento — cobalto (interacción)

Uso: botones primarios, links, foco, selección, navegación activa.

| Token | Light | Dark |
|---|---|---|
| `--accent` | `#2B4FD8` | `#8AA3FF` |
| `--accent-hover` | `#2342BE` | `#A3B6FF` |
| `--accent-subtle` (fondos) | `#EBEFFD` | `#1B2340` |
| `--accent-border` | `#B9C6F5` | `#33407A` |

### Verde conexión (semántico, mundo WhatsApp)

Uso EXCLUSIVO: línea conectada, asistente activo/respondiendo,
webhook OK. Nunca en botones, nav ni decoración.

| Token | Light | Dark |
|---|---|---|
| `--live` | `#177A47` | `#4ADE8C` |
| `--live-subtle` | `#E4F5EB` | `#0F2A1D` |

### Estados

| Token | Light | Dark |
|---|---|---|
| `--success` | `#177A47` (comparte con live) | `#4ADE8C` |
| `--warning` | `#96570A` | `#F0B44C` |
| `--danger` | `#BB3129` | `#FF8A80` |
| `--info` | `#0B6E8F` | `#67CBE8` |

> **Verificación obligatoria**: antes de implementar, medir contraste
> de cada par texto/fondo en ambos temas (AA: 4.5:1 cuerpo, 3:1
> grande/bold) y documentarlo en `design/contrast-check.md`,
> ajustando pasos si algo falla.

## 2. Tipografía

| Rol | Fuente | Razón |
|---|---|---|
| UI / cuerpo / títulos | **Instrument Sans** (self-hosted, variable) | Grotesca humanista fresca; distinta del default Inter/Manrope sin perder legibilidad de datos |
| Técnico | **JetBrains Mono** (self-hosted, subset) | Identificadores, teléfonos, IDs de modelo, claves, slugs. La tipografía *es* la explicación: "esto es un valor técnico, cópialo tal cual" |

Ambas se sirven con `@font-face` + `font-display: swap` desde
`assets/fonts/` — sin CDNs externos.

Escala compacta (px / line-height / weight):

| Clase | Spec | Uso |
|---|---|---|
| `.text-display` | 24/30 · 650 | Solo títulos de página |
| `.text-h1` | 18/24 · 650 | Título de panel |
| `.text-h2` | 15/20 · 600 | Grupos de ajustes |
| `.text-h3` | 13/18 · 600 | Sub-grupos, headers de tabla |
| `.text-body` | 13/20 · 450 | Default |
| `.text-small` | 12/16 · 450 | Ayudas, metadatos |
| `.text-micro` | 11/14 · 600 · caps + tracking | Chips, eyebrows |
| `.text-mono` | 12.5/18 · 450 · JetBrains Mono | Valores técnicos |

## 3. Espaciado, radios, sombras, densidad

- **Espaciado**: base 4 → `4, 8, 12, 16, 20, 24, 32, 40`.
- **Radios**: `sm 6` (chips, inputs), `md 8` (botones, cards internas),
  `lg 12` (paneles, drawers). Ningún `rounded-[*]` arbitrario.
- **Sombras**: casi ninguna. `xs 0 1px 2px rgba(16,18,22,.05)` para
  cards, `md 0 8px 24px rgba(16,18,22,.12)` solo para popovers, drawers
  y modales. La separación se hace con borde de 1 px, no con sombra.
- **Densidad**: inputs y botones **32 px** de alto (`px-3`), filas de
  tabla **40 px**, headers de tabla 32 px, sidebar items 32 px. En
  móvil los targets táctiles suben a 44 px.

## 4. Movimiento

- Transiciones 120–150 ms ease-out, solo `color/background/opacity`.
- Drawers/popovers: 160 ms con leve translate (8 px).
- Un solo momento orquestado: al guardar, el resumen de la ficha se
  actualiza con un flash sutil de `--accent-subtle` (600 ms) — el
  usuario ve que "la frase" cambió. Nada más se anima.
- `prefers-reduced-motion`: todo a 0 ms.

## 5. Inventario de componentes

### Primitivas nuevas (hoy no existen)

| Componente | Notas |
|---|---|
| `UiButton` | variantes `primary/secondary/ghost/danger`, tamaños `sm(28)/md(32)`, estado loading integrado. Reemplaza `class="btn-*"` crudo |
| `UiInput`, `UiSelect`, `UiTextarea`, `UiCheckbox`, `UiSwitch` | 32 px, wiring de `id`/`aria-describedby` con FormField, estado error |
| `UiPopover` | UNA implementación (outside-click + Escape + focus-trap); AvatarMenu, AdminNav e InfoPopover la consumen |
| `UiDrawer` | panel lateral derecho (480 px, full-screen `<lg`) para editar/crear entidades desde tablas |
| `UiModal` | solo confirmaciones |
| `UiToast` | feedback de guardado (reemplaza InlineAlert pinned como único feedback) |
| `UiTabs` | tabs accesibles (roving tabindex) |
| `SearchInput` | búsqueda fuzzy de ajustes en AdminNav |

### Componentes de patrón (firma del sistema)

| Componente | Rol |
|---|---|
| `SummaryCard` | Ficha resumen-primero: título + frase de estado + chips + acción "Editar" (ver 07-patrones) |
| `SettingRow` | Fila de ajuste: label + help de una frase + control alineado a la derecha; base de toda pantalla de config |
| `InheritanceChip` | `Heredado de la organización` / `Personalizado` + acción "restaurar" |
| `LiveDot` | punto + label de estado de conexión (usa `--live`, con `aria-label`, no solo color) |
| `DangerZone` | contenedor rojo aislado al final de la pantalla |
| `TechValue` | valor técnico en mono + botón copiar |

### Componentes existentes que continúan (con tokens Pulso)

`SurfaceCard`, `StatusBadge`, `EmptyState`, `ForbiddenState`
(traducir "Restricted"), `LoadingState` (→ skeletons), `FormField`
(añadir error + `forId` real), `DataTable` (por fin adoptado como
forma primaria de listar entidades), `PanelHeader`, `AdminNav`.

## 6. Accesibilidad (piso de calidad)

- Foco visible siempre: anillo `0 0 0 2px var(--bg), 0 0 0 4px var(--accent)`.
- Estado nunca solo por color: LiveDot y StatusBadge llevan texto.
- Labels conectados por `for`/`id`; ayudas por `aria-describedby`.
- Tablas con `<th scope>`; drawers y modales con focus-trap y `Esc`.
- AA medido en ambos temas (ver §1).

## 7. Implementación de tokens

Variables CSS en `style.css`: **light es `:root` (default)**, dark en
`:root[data-theme="dark"]` (atributo puesto por `initTheme()` según
preferencia guardada o `prefers-color-scheme`). Ningún otro nombre de
token vive en el código: los componentes referencian exclusivamente
los tokens de este documento.
