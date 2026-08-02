# Tasks — Redesign UI Pulso

> Frontend-only (`frontend/src/**`). Fuente de diseño: `design/`.
> Gate por fase: `docker compose exec frontend npm run typecheck` y
> `npm run build` en verde.

## 1. Estructura del admin funcional

- [ ] 1.1 Crear `modules/admin/views/AdminLayout.vue` (AdminNav +
  PanelHeader + `<RouterView/>`) y registrarlo como componente padre
  de las sub-rutas de `/admin` en `router/routes.ts`. Verificar que
  cada sub-ruta monta su vista: las 4 existentes con su contenido y
  los 4 stubs con un marcador de texto temporal visible (que 1.4
  reemplaza), para probar de verdad el wiring del RouterView.
- [ ] 1.2 Implementar en `router/guards.ts` la evaluación de
  `meta.requires` (claves de `useNavigationAccess`, OR) para
  `/admin/**`; sin permiso → `ForbiddenState` sin loops con el
  fallback de `/admin`. Corregir `ADMIN_ROUTE_REQUIRES`:
  `/admin/org/info` → `['canManageTenant']` (hoy incluye
  `canAccessAdmin`) y `/admin/connect/lines` → `['canManageTenant']`
  (hoy incluye `canManageAgentConfig`); ambas entradas dejarían
  pasar a usuarios solo-asistente y romperían el QA 6.4 (design.md
  decisión 12).
- [ ] 1.3 Añadir claves i18n de navegación y paneles (`admin.nav.*`,
  `admin.{org,connect,assistant,activity}.*.{title,description}`) en
  `es-CO.ts` y `en.ts`; AdminNav y PanelHeader dejan de renderizar
  claves crudas.
- [ ] 1.4 Ports mínimos para paridad temporal: `DataView` (upload +
  estado de fuentes), `BehaviorView` (config de agente),
  `ToolsView` (bindings de herramientas), `ActivityView` (eventos),
  extraídos del monolito sin rediseño.
- [ ] 1.5 Eliminar `AdminView.vue`, `AdminSectionTabs.vue`,
  `presentation.ts` y el manejo de `?panel=` en guards; grep sin
  referencias restantes.

## 2. Tokens y primitivas Pulso

- [ ] 2.1 Reescribir `style.css` con los tokens de `design/03`
  (light = `:root`, dark = `data-theme="dark"`), escala tipográfica y
  recipes de componentes; eliminar todo token del sistema anterior.
- [ ] 2.2 Medir contraste AA de todos los pares en ambos temas y
  documentarlo en `design/contrast-check.md`; ajustar tokens que
  fallen (actualizar `design/03`).
- [ ] 2.3 Self-host Instrument Sans (variable) y JetBrains Mono
  (subset) con `@font-face` + `font-display: swap`; registrar el
  delta de bundle.
- [ ] 2.4 Crear primitivas: `UiButton`, `UiInput`, `UiSelect`,
  `UiTextarea`, `UiCheckbox`, `UiSwitch` (32 px, error +
  `aria-describedby`, foco visible).
- [ ] 2.5 Crear `UiPopover` (outside-click, Escape, focus-trap) y
  migrar `AvatarMenu`, `AdminNav` e `InfoPopover` a él; eliminar las
  implementaciones duplicadas.
- [ ] 2.6 Crear `UiDrawer` (480 px, full-screen `<lg`), `UiModal`
  (confirmaciones), `UiToast` y `UiTabs`.
- [ ] 2.7 Crear componentes de patrón: `SummaryCard`, `SettingRow`,
  `InheritanceChip`, `LiveDot`, `DangerZone`, `TechValue` según
  `design/07`, cada uno correcto en ambos temas.
- [ ] 2.8 Refresh con tokens Pulso de los componentes existentes
  (`SurfaceCard`, `StatusBadge`, `EmptyState`, `ForbiddenState` —
  traducir su chip —, `LoadingState` → skeletons, `FormField` con
  error/`forId`, `DataTable` 40 px, shell completo). Grep final: cero
  `rounded-[` y cero estilos inline de borde.

## 3. Capa de datos del admin

- [ ] 3.1 Crear composable compartido de recursos + feedback
  (loading/error/éxito + toast): el overview se carga una vez por
  sesión de admin / cambio de tenant y cada mutación actualiza su
  colección en memoria desde la respuesta de la propia mutación —
  sin GETs de seguimiento (design.md decisión 5).
- [ ] 3.2 Migrar las vistas de admin existentes al composable;
  ninguna vista llama `fetchAdminOverview()` directamente ni
  refetchea todo tras mutar.

## 4. Pantallas del admin de tenant (`design/05`)

- [ ] 4.1 `assistant/behavior`: fichas resumen-primero (Estado,
  Modelo con 3 opciones y costo relativo, Personalidad con recetas,
  Mensaje de handoff, avanzadas colapsadas) + ScopePicker con
  herencia por diff, restaurar-al-general y confirmación de ámbito
  sucio.
- [ ] 4.2 `assistant/tools`: SettingRows con switch + frase de
  efecto, config anidada (fuente, timeout) visible solo al activar,
  ámbito por línea con chips de herencia; nombres solo de negocio.
- [ ] 4.3 `connect/lines`: DataTable (estado con LiveDot, columna de
  asistente con chip de herencia) + drawer (general / datos técnicos
  de Meta con TechValue / asistente en esta línea / zona de peligro)
  + drawer de conexión con ayuda por campo.
- [ ] 4.4 `connect/data`: DataTable de fuentes (estados humanos,
  reintentar en fila) + drawer de metadatos legibles y "usada por";
  sin JSON crudo; sin matriz de bindings.
- [ ] 4.5 `org/members`: DataTable + menú de fila (cambiar rol /
  quitar acceso con confirmación) + drawer de invitación con roles
  descritos.
- [ ] 4.6 `org/info`: SummaryCard única (nombre editable, slug
  TechValue, estado como badge de solo lectura) + DangerZone
  "Pausar/Reactivar la organización" como única vía de cambio de
  estado; sin bloque de crear tenant.
- [ ] 4.7 `connect/credentials`: DataTable de solo lectura + copy de
  a quién pedir cambios + link a Plataforma para platform admins.
- [ ] 4.8 `activity`: timeline unificado (agente + auditoría +
  herramientas) con filtros tipo/período/línea, frases humanas,
  payload en drawer.
- [ ] 4.9 Búsqueda fuzzy de ajustes en AdminNav (índice de claves
  i18n, atajo `/`, navegar + resaltar ficha) y drill-down móvil de
  AdminNav a pantalla completa.

## 5. Plataforma (`design/06`)

- [ ] 5.1 Crear `modules/platform` (api/types/views), rutas
  `/platform` → `/platform/tenants` y `/platform/credentials` con
  guard `canManagePlatform`, y la entrada Plataforma en
  SectionNav/BottomNav bajo divisor.
- [ ] 5.2 `platform/tenants`: DataTable (nombre, slug, estado,
  creado — solo campos de `GET /admin/tenants`) con búsqueda y
  filtro de estado + drawer (detalle con estado como badge, crear
  tenant, "Entrar como admin" — siempre disponible: los platform
  admins tienen membresía sintética en todos los tenants —,
  DangerZone Pausar/Reactivar como única vía de cambio de estado).
- [ ] 5.3 `platform/credentials`: selector de tenant propio en la
  pantalla + DataTable del tenant elegido + drawer de upsert contra
  ese tenant con secreto write-only que se limpia tras guardar
  (design.md decisión 11).
- [ ] 5.4 Retirar de las pantallas de tenant los bloques de
  plataforma (crear tenant en `org/info`, upsert en credenciales) y
  mover/reubicar sus funciones de API a `modules/platform`.

## 6. Resto de la app y validación

- [ ] 6.1 Aplicar tokens y densidad Pulso a `OperationsView`,
  `SandboxView` y `LoginView` sin cambios de lógica.
- [ ] 6.2 `npm run typecheck` y `npm run build` en verde; registrar
  el delta de bundle gzip (≤ 60 KB).
- [ ] 6.3 QA manual: cada ruta (`login`, `operations`, `sandbox`, 8
  de admin, 2 de plataforma) a 360/768/1280/1600 en ambos temas;
  foco visible y Escape en drawers/modales; reduced-motion.
- [ ] 6.4 QA de permisos: cuenta solo-`agent_configs.manage` (ve solo
  Asistente; `/admin` cae a behavior; resto Forbidden), cuenta sin
  `platform.manage` (sin sección Plataforma; `/platform/*`
  Forbidden), platform admin (flujo completo de tenants y
  credenciales).
- [ ] 6.5 Greps de cierre: cero `rounded-[`, cero tokens del sistema
  anterior, cero `fetchAdminOverview` en vistas, cero `?panel`.
