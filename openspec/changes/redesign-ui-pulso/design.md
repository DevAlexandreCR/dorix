# Design — Redesign UI Pulso

## Context

La fuente de verdad del diseño es la carpeta **`design/`** (sistema
Pulso): tokens y componentes (`03`), arquitectura de información
(`04`), pantallas de tenant admin (`05`), platform admin (`06`),
patrones UX (`07`) y plan de fases (`08`), más la maqueta
`design/mockups/admin-pulso.html`. Este documento no duplica ese
contenido: registra las decisiones técnicas de implementación.

Estado del código: Vue 3.5 + TS + Tailwind v4 (tokens en
`style.css`), vue-router 4, vue-i18n (es-CO default). Las sub-rutas
de admin existen pero el componente padre (`AdminView.vue`, 1.853
líneas) no tiene `<RouterView/>`; `AdminNav.vue` está sin usar; las
claves i18n nuevas no existen; `meta.requires` no se evalúa. No hay
librería de componentes ni tests de frontend.

## Goals / Non-Goals

**Goals:**
- Implementar el sistema Pulso completo en el frontend.
- Admin de tenant funcional de punta a punta con las 8 pantallas de
  `design/05`.
- Superficie `/platform` nueva según `design/06`.
- Feature parity: toda acción disponible hoy sigue existiendo.

**Non-Goals:**
- Cambios de backend, API, permisos o dominio (cero).
- Compatibilidad con URLs o convenciones de la UI anterior (proyecto
  nuevo, sin usuarios que migrar): no hay redirects `?panel=`, ni
  alias de tokens, ni feature flags.
- Nuevos KPIs/dashboard de métricas; onboarding tour; logs con
  paginación server-side (el timeline usa los datos que ya entrega la
  API).

## Decisions

1. **`AdminLayout.vue` como padre de las sub-rutas** (AdminNav +
   PanelHeader + `<RouterView/>`), y `AdminView.vue` se elimina junto
   con `AdminSectionTabs` y `presentation.ts`. Alternativa rechazada:
   parchear `AdminView` con un RouterView — mantiene 1.853 líneas
   muertas.
2. **Guard por `meta.requires`** (claves de `useNavigationAccess`,
   OR) en `router/guards.ts`, con la tabla única
   `ADMIN_ROUTE_REQUIRES` de `modules/admin/router.ts` compartida con
   `AdminNav`. `/platform/**` usa el mismo mecanismo con
   `canManagePlatform`.
3. **Tokens Pulso directos**: se reescribe `style.css` sin ningún
   alias del sistema anterior; light = `:root`, dark =
   `:root[data-theme="dark"]` (se invierte el default actual, el
   mecanismo `initTheme()` no cambia). Un grep de nombres viejos
   (`--bg-elev`, `--text-faint`, `--accent-50`… y `rounded-[`) debe
   quedar en cero.
4. **Fuentes self-hosted** (Instrument Sans variable + JetBrains Mono
   subset) en `frontend/src/assets/fonts/` con `@font-face` +
   `font-display: swap`. Alternativa rechazada: Google Fonts CDN
   (dependencia externa y CSP).
5. **Datos por recurso**: composable `useAdminResource` /
   `useAdminFeedback` que expone estado (loading/error/éxito) por
   recurso (tenant, members, lines, credentials, agentConfigs,
   toolConfigs, dataSources, events). Como el único GET combinado es
   `fetchAdminOverview()`, este se llama **una sola vez** por sesión
   de admin o cambio de tenant; cada mutación actualiza su colección
   en memoria a partir de la respuesta de la propia mutación (los
   endpoints devuelven el registro actualizado) — sin GETs de
   seguimiento. Se implementa **antes** que las pantallas.
   Alternativa rechazada: refetch del overview completo por vista y
   por guardado (payload completo + 3 streams de logs cada vez).
6. **Herencia por diff**: en ámbito línea solo se persisten campos
   personalizados (`updateLineAgentConfig` / `updateLineToolConfig`);
   "Restaurar al general" = `deleteLine*Config`. El `ScopePicker`
   emite `request-switch` antes de cambiar para interceptar
   formularios sucios.
7. **Un solo Popover**: `UiPopover` (outside-click, Escape,
   focus-trap) y AvatarMenu/AdminNav/InfoPopover lo consumen —
   elimina las 3 implementaciones duplicadas.
8. **Búsqueda de ajustes client-side**: índice construido de las
   claves i18n (título/label/ayuda de cada panel) con matching fuzzy
   simple, sin dependencia nueva; navegar + resaltar la ficha
   destino. Alternativa rechazada: librería de búsqueda (peso injustificado).
9. **Módulo nuevo `modules/platform`** con `api.ts` (reusa las
   funciones platform de `modules/admin/api.ts` reubicadas),
   `types.ts` y `views/` — sigue la convención de módulos del repo.
10. **Tenants de plataforma solo con datos de `GET /admin/tenants`**:
   la tabla muestra nombre, slug, estado y fecha de creación — sin
   contadores de líneas/miembros, porque `serializeTenant` no los
   incluye y el backend no se toca. Alternativas rechazadas: N
   llamadas a `fetchAdminOverview` para contar (costo absurdo) o
   `withCount` en backend (viola el non-goal). Si algún día se
   necesita, es un cambio de backend aparte.
11. **`/platform/credentials` es tenant-scoped con selector propio**:
   todos los endpoints de credenciales requieren `X-Tenant-Id`, así
   que la pantalla opera sobre un tenant a la vez elegido en un
   selector visible dentro de la pantalla (los platform admins tienen
   membresía sintética en todos los tenants, así que la lista de
   opciones ya está en sesión). El TopBar en `/platform/**` sigue sin
   pill de tenant; el contexto vive en la pantalla. Alternativa
   rechazada: agregación cross-tenant (requiere N overviews y un
   campo de tenant en el drawer no soportado por la API).
12. **Gates de `org/info` y `connect/lines` = solo
   `canManageTenant`**: se corrige `ADMIN_ROUTE_REQUIRES` en ambas
   entradas (hoy `org/info` incluye `canAccessAdmin` y
   `connect/lines` incluye `canManageAgentConfig`; cualquiera de las
   dos dejaría entrar a usuarios solo-asistente a paneles de
   Organización/Conexiones y rompería el fallback de `/admin`
   esperado: un usuario con solo `agent_configs.manage` debe caer en
   `assistant/behavior` y ver únicamente el grupo Asistente). La
   gestión por-línea del asistente para ese rol vive en el ámbito de
   línea de `assistant/behavior|tools`, no en `connect/lines`.
13. **Estados enumerados nunca editables como texto/select libre**:
   se muestran como badge y se cambian solo con la acción dedicada
   (Pausar/Reactivar en DangerZone), igual en `org/info` y en el
   drawer de tenants de plataforma.

## Risks / Trade-offs

- [La pantalla Behavior concentra patrones nuevos (SummaryCard +
  herencia + dirty-state)] → implementarla primero valida los
  patrones; las demás pantallas los reutilizan.
- [Reescritura simultánea de vistas sin tests de frontend] → QA
  manual por pantalla contra la matriz de `design/08` §Fase 6 +
  `npm run typecheck`/`build` como gates por tarea.
- [Instrument Sans/JetBrains Mono aumentan el bundle] → subsets
  woff2, presupuesto medido de ≤ 60 KB gzip adicionales.
- [Contraste de la paleta sin verificar] → tarea temprana de medición
  AA documentada en `design/contrast-check.md`; los valores de
  `design/03` se ajustan si algún par falla.
- [Eliminar `AdminView.vue` de una vez rompe el admin si las
  pantallas no están listas] → orden de fases: layout+guard+i18n
  primero (con las vistas ya extraídas y ports mínimos), la
  eliminación del monolito es la última tarea de esa fase.

## Migration Plan

No aplica migración de usuarios (proyecto nuevo, pre-lanzamiento). El
cambio aterriza en una sola rama siguiendo las fases de `design/08`;
cada fase deja `typecheck` y `build` en verde.

## Open Questions

- Subset exacto de JetBrains Mono (¿solo latin + números?) — decidir
  al medir el bundle.
- `EmptyState`/ilustración del timeline de Actividad cuando la API de
  eventos devuelva vacío — copy definido en `design/07` §5, sin
  ilustración por ahora.
