# ui-design-system Specification

## Purpose
Sistema visual Pulso: tokens, tipografía, primitivas accesibles y
componentes de patrón compartidos por toda la SPA.

Fuente de diseño: `design/03-design-system.md` y
`design/07-patrones-ux.md`.

## Requirements

### Requirement: Tokens Pulso con dos temas
El frontend SHALL definir todos los colores, radios, sombras y
espaciados como variables CSS según `design/03-design-system.md`, con
light como tema default (`:root`) y dark bajo
`:root[data-theme="dark"]`. Ningún componente SHALL usar colores,
radios ni sombras fuera de esos tokens (cero valores arbitrarios
`rounded-[*]` o hex inline).

#### Scenario: Cambio de tema
- **WHEN** el usuario cambia el tema desde el AvatarMenu
- **THEN** toda la UI re-renderiza con los tokens del tema elegido y
  la preferencia persiste entre sesiones

#### Scenario: Sin tokens huérfanos
- **WHEN** se busca en `frontend/src` cualquier nombre de token ajeno
  a `design/03` o clases `rounded-[`
- **THEN** no hay ninguna ocurrencia

### Requirement: Contraste AA verificado
Todo par texto/fondo y estado/fondo de ambos temas SHALL cumplir WCAG
AA (4.5:1 cuerpo; 3:1 grande/bold), con medición documentada en
`design/contrast-check.md` y ajuste de tokens si algún par falla.

#### Scenario: Verificación documentada
- **WHEN** se implementan los tokens
- **THEN** existe `design/contrast-check.md` con el ratio medido de
  cada par crítico en ambos temas y ningún par queda bajo el mínimo

### Requirement: Tipografía del sistema
La UI SHALL usar Instrument Sans (self-hosted, `font-display: swap`)
con la escala compacta de `design/03` §2, y JetBrains Mono para todo
valor técnico (IDs, teléfonos en datos técnicos, slugs, llaves,
modelos técnicos).

#### Scenario: Valor técnico distinguible
- **WHEN** una pantalla muestra un identificador técnico (p. ej.
  Phone Number ID)
- **THEN** se renderiza en monospace mediante `TechValue`, con acción
  de copiar

### Requirement: Primitivas UI accesibles
El frontend SHALL proveer y usar las primitivas `UiButton`, `UiInput`,
`UiSelect`, `UiTextarea`, `UiCheckbox`, `UiSwitch`, `UiPopover`,
`UiDrawer`, `UiModal`, `UiToast`, `UiTabs` y `SearchInput`, con foco
visible, labels conectados (`for`/`aria-describedby`), y cierre por
Escape + outside-click en popovers/drawers/modales. `UiPopover` SHALL
ser la única implementación de popover de la app.

#### Scenario: Formulario accesible
- **WHEN** un campo de formulario muestra error de validación
- **THEN** el mensaje aparece bajo el campo, asociado por
  `aria-describedby`, y el campo recibe estilo de error

#### Scenario: Drawer con teclado
- **WHEN** un drawer está abierto y el usuario presiona Escape
- **THEN** el drawer cierra y el foco regresa al elemento que lo abrió

### Requirement: Componentes de patrón
El frontend SHALL proveer `SummaryCard` (ficha resumen-primero con
edición bajo demanda y guardado propio), `SettingRow`,
`InheritanceChip`, `LiveDot`, `DangerZone` y `TechValue`, con el
comportamiento definido en `design/07-patrones-ux.md`.

#### Scenario: Guardado por ficha
- **WHEN** el usuario guarda una SummaryCard
- **THEN** la ficha colapsa, el resumen refleja el nuevo estado con un
  flash sutil y aparece un toast con el mismo verbo del botón

### Requirement: Verde reservado a estado de conexión
El color `--live` SHALL usarse únicamente para semántica de conexión/
actividad de WhatsApp (línea conectada, asistente respondiendo) y
SHALL ir siempre acompañado de texto (nunca solo color).

#### Scenario: Línea conectada
- **WHEN** una línea de WhatsApp está conectada y activa
- **THEN** su estado se muestra con `LiveDot` (punto + etiqueta
  "Conectada")

### Requirement: Movimiento reducido
Toda transición SHALL respetar `prefers-reduced-motion` quedando en
0 ms cuando el usuario lo prefiere.

#### Scenario: Usuario con reduced motion
- **WHEN** el sistema operativo reporta `prefers-reduced-motion: reduce`
- **THEN** drawers, toasts y flashes aparecen sin animación

