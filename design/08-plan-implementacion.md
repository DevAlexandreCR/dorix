# 08 · Plan de implementación

Fases para llevar el frontend al estado final descrito en esta
carpeta. Frontend-only: la API actual alcanza para todo el plan.

## Fase 1 · Estructura del admin funcional

El esqueleto de rutas del admin (4 grupos + sub-rutas) ya existe;
esta fase lo deja operativo de punta a punta:

1. `AdminLayout.vue` como componente padre de las sub-rutas de
   `/admin`: monta `AdminNav` + `PanelHeader` + `<RouterView/>`.
2. Guard de router que evalúa `meta.requires` (claves de
   `useNavigationAccess`, semántica OR) en cada navegación de admin;
   sin permiso → `ForbiddenState`.
3. Claves i18n de navegación y paneles (`admin.nav.*`,
   `admin.{org,connect,assistant,activity}.*.{title,description}`)
   en `es-CO.ts` y `en.ts`.
4. Eliminar el panel monolítico (`AdminView.vue`,
   `AdminSectionTabs.vue`, `presentation.ts`) y el redirect
   `?panel=` — la app queda solo con las sub-rutas.

## Fase 2 · Tokens y primitivas Pulso

- Tokens de color/tipografía/espaciado/radios/sombras de
  [03-design-system.md](03-design-system.md) en `style.css`
  (light = `:root`, dark = `data-theme="dark"`), con verificación de
  contraste AA medida y documentada en `design/contrast-check.md`.
- Fuentes self-hosted: Instrument Sans (variable) + JetBrains Mono
  (subset), `font-display: swap`.
- Primitivas: `UiButton`, `UiInput`, `UiSelect`, `UiTextarea`,
  `UiCheckbox`, `UiSwitch`, `UiPopover`, `UiDrawer`, `UiModal`,
  `UiToast`, `UiTabs`, `SearchInput`.
- Componentes de patrón: `SummaryCard`, `SettingRow`,
  `InheritanceChip`, `LiveDot`, `DangerZone`, `TechValue`.
- Adopción de `DataTable` como forma primaria de listar entidades.

## Fase 3 · Capa de datos del admin

- Composable compartido de feedback (loading / error / éxito + toast).
- Fetch por recurso en vez de un `AdminOverview` completo por vista y
  por mutación.
- Requisito previo a la Fase 4 para no reescribir pantallas dos veces.

## Fase 4 · Pantallas del admin de tenant

Según [05-tenant-admin.md](05-tenant-admin.md), en orden por impacto:

1. `assistant/behavior` — fichas resumen-primero + ámbito con herencia
2. `assistant/tools` — SettingRows + fuentes por herramienta
3. `connect/lines` — tabla + drawer
4. `connect/data` — tabla de fuentes + drawer
5. `org/members` — tabla + drawer de invitación
6. `org/info` — ficha única + zona de peligro
7. `connect/credentials` — tabla solo lectura
8. `activity` — timeline unificado con filtros

Incluye la búsqueda fuzzy de ajustes en `AdminNav` y el drill-down
móvil.

## Fase 5 · Plataforma

Según [06-platform-admin.md](06-platform-admin.md): sección
`/platform` en el SectionNav (solo `canManagePlatform`),
`/platform/tenants` y `/platform/credentials`. Las pantallas de
tenant no contienen acciones de plataforma.

## Fase 6 · Resto de la app y validación

- Tokens y densidad Pulso en Operations, Sandbox y Login (sin cambios
  de lógica ni de composición de datos).
- `npm run typecheck` y `npm run build` en verde.
- QA manual: cada ruta a 360/768/1280/1600 en ambos temas.
- Gating por permisos verificado con una cuenta de cada rol.
- Presupuesto de bundle: ≤ 60 KB gzip adicionales (fuentes incluidas).
