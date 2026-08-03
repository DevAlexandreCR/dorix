# Redesign UI Pulso

## Why

Las configuraciones de Dorix (agente IA, herramientas, líneas de
WhatsApp, fuentes, credenciales) son extensas y hoy se presentan como
formularios planos y repetidos (hasta ~36 controles por pantalla), en
un admin monolítico parcialmente reestructurado que dejó rutas que no
renderizan sus vistas. El resultado: nadie sabe qué hace cada cosa sin
entrenamiento, y no existe superficie de platform admin. El diseño
final está definido y centralizado en la carpeta `design/` (sistema
"Pulso"); este cambio lo implementa.

## What Changes

- **Design system Pulso** en todo el frontend: tokens (papel frío +
  tinta, acento cobalto, verde reservado a "conectado"), Instrument
  Sans + JetBrains Mono, densidad compacta (controles 32 px, cuerpo
  13 px), ambos temas AA verificado. Sustituye por completo los
  tokens y la tipografía actuales.
- **Primitivas UI** nuevas (Button, Input, Select, Textarea,
  Checkbox, Switch, Popover, Drawer, Modal, Toast, Tabs, SearchInput)
  y componentes de patrón (SummaryCard, SettingRow, InheritanceChip,
  LiveDot, DangerZone, TechValue); adopción de DataTable.
- **Admin de tenant funcional y rediseñado**: AdminLayout con
  `<RouterView/>` + AdminNav + PanelHeader; guard que evalúa
  `meta.requires`; claves i18n de navegación/paneles; las 8 pantallas
  según `design/05-tenant-admin.md` (resumen-primero, herencia
  organización→línea por diff, tablas + drawer, zonas de peligro,
  búsqueda fuzzy de ajustes). **BREAKING**: se elimina el panel
  monolítico `AdminView.vue` (con `AdminSectionTabs` y
  `presentation.ts`) y el soporte de URLs `/admin?panel=X`.
- **Platform admin nuevo**: sección `/platform` (solo
  `canManagePlatform`) con `/platform/tenants` y
  `/platform/credentials`; las acciones de plataforma desaparecen de
  las pantallas de tenant.
- **Re-skin de Operations, Sandbox y Login** con tokens Pulso, sin
  cambios de lógica.

## Capabilities

### New Capabilities

- `ui-design-system`: tokens de color/tipografía/espaciado, temas
  light/dark AA, primitivas y componentes de patrón, reglas de
  movimiento y accesibilidad (fuente: `design/03-design-system.md` y
  `design/07-patrones-ux.md`).
- `ui-admin`: estructura de navegación del admin de tenant (grupos,
  sub-rutas, gating por permisos, búsqueda de ajustes) y
  comportamiento de sus 8 pantallas (fuente:
  `design/04-arquitectura-informacion.md` y
  `design/05-tenant-admin.md`).
- `ui-platform-admin`: superficie `/platform` para super admins:
  tenants y credenciales, y regla de separación de ámbitos (fuente:
  `design/06-platform-admin.md`).

### Modified Capabilities

<!-- Ninguna: no existen specs previas en openspec/specs/. -->

## Impact

- **Solo frontend** (`frontend/src/**`): `style.css`, `components/ui`,
  `components/shell`, `modules/admin` (reescritura de vistas),
  `modules/platform` (nuevo), `router/`, `i18n/locales/*`,
  `modules/operations|sandbox|auth` (solo estilos), `package.json`
  (fuentes self-hosted).
- **Backend, API y dominio: sin cambios.** Todos los endpoints
  necesarios existen (`fetchAdminTenants`, `createTenant`,
  `updateTenant`, configs de agente/herramientas por tenant y línea,
  upsert de credenciales).
- Presupuesto de bundle: ≤ 80 KB gzip adicionales (fuentes incluidas).
  Se revisó de la cifra original (≤ 60 KB) tras la medición de la
  tarea 6.2: esa cifra se fijó antes de dimensionar el alcance final
  (12 primitivas, 6 componentes de patrón, 8 pantallas reescritas y el
  nuevo módulo `platform`), y las fuentes self-hosted por sí solas ya
  representan ~50 KB de ese total.
- Fuente de verdad de diseño: carpeta `design/` (sistema Pulso) +
  maqueta `design/mockups/admin-pulso.html`.
