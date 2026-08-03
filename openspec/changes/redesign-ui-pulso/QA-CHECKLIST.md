# QA manual — tasks 6.3 y 6.4 (redesign-ui-pulso)

Este checklist es para terminar en navegador lo que no se puede verificar
sin uno (no hay Playwright/puppeteer en este repo y no se va a añadir).
Todo lo que sí se pudo verificar en código está en la sección final —
no lo repitas.

Stack: `docker compose up --build` ya corriendo. Frontend
`http://localhost:5173`, backend `http://localhost:8080/api`.

## 0. Cuentas de prueba

Sembradas con `docker compose exec php-fpm php artisan db:seed`
(`database/seeders/DatabaseSeeder.php`), tenant único **Acme Demo**
(`acme-demo`). Password para las 4: **`password`**.

| Cuenta | Email | Rol | Permisos reales (`GET /api/v1/auth/session`, verificado en vivo) |
|---|---|---|---|
| Platform admin | `platform-admin@example.com` | `platform_admin` | las 8 (`platform.manage`, `tenant.manage`, `tenant_users.manage`, `agent_configs.manage`, `conversations.view`, `conversations.reply`, `handoffs.manage`, `credentials.view_metadata`) — membresía sintética en Acme Demo |
| Tenant admin (= "sin `platform.manage`") | `tenant-admin@example.com` | `tenant_admin` | `tenant.manage`, `tenant_users.manage`, `agent_configs.manage`, `conversations.view`, `conversations.reply`, `handoffs.manage`, `credentials.view_metadata` (todo excepto `platform.manage`) |
| Operator | `operator@example.com` | `operator` | `conversations.view`, `conversations.reply`, `handoffs.manage` (sin nada de admin) |
| Viewer | `viewer@example.com` | `viewer` | `conversations.view` únicamente |

**⚠️ No existe cuenta real "solo `agent_configs.manage`"** — ver hallazgo
en la sección final. Para esa parte de 6.4 usa el mock de DevTools
descrito en el punto 2 de la sección "Cuentas de permisos".

## 1. Rutas × anchos × temas

13 rutas: `login`, `operations`, `sandbox`, 8 de admin
(`/admin/org/info`, `/admin/org/members`, `/admin/connect/lines`,
`/admin/connect/credentials`, `/admin/connect/data`,
`/admin/assistant/behavior`, `/admin/assistant/tools`,
`/admin/activity`), 2 de plataforma (`/platform/tenants`,
`/platform/credentials`).

Anchos: **360, 768, 1280, 1600** px (DevTools → responsive, o
`window.innerWidth` en la consola). Temas: **claro y oscuro**
(toggle del shell; persiste en `localStorage`, mecanismo `initTheme()`
sin cambios).

Con la cuenta **tenant-admin** (ve las 8 rutas de admin) y
**platform-admin** (ve además las 2 de plataforma), para cada
combinación ruta × ancho × tema marca:

- [ ] `/login` — 360 claro
- [ ] `/login` — 360 oscuro
- [ ] `/login` — 768 claro
- [ ] `/login` — 768 oscuro
- [ ] `/login` — 1280 claro
- [ ] `/login` — 1280 oscuro
- [ ] `/login` — 1600 claro
- [ ] `/login` — 1600 oscuro
- [ ] `/operations` — 360/768/1280/1600 × claro/oscuro (8 combinaciones)
- [ ] `/sandbox` — 360/768/1280/1600 × claro/oscuro (8 combinaciones)
- [ ] `/admin/org/info` — 360/768/1280/1600 × claro/oscuro (8)
- [ ] `/admin/org/members` — 360/768/1280/1600 × claro/oscuro (8)
- [ ] `/admin/connect/lines` — 360/768/1280/1600 × claro/oscuro (8)
- [ ] `/admin/connect/credentials` — 360/768/1280/1600 × claro/oscuro (8)
- [ ] `/admin/connect/data` — 360/768/1280/1600 × claro/oscuro (8)
- [ ] `/admin/assistant/behavior` — 360/768/1280/1600 × claro/oscuro (8)
- [ ] `/admin/assistant/tools` — 360/768/1280/1600 × claro/oscuro (8)
- [ ] `/admin/activity` — 360/768/1280/1600 × claro/oscuro (8)
- [ ] `/platform/tenants` — 360/768/1280/1600 × claro/oscuro (8, con platform-admin)
- [ ] `/platform/credentials` — 360/768/1280/1600 × claro/oscuro (8, con platform-admin)

Qué mirar en cada una: nada de scroll horizontal de página (una tabla
ancha debe scrollear dentro de su propio contenedor, no la página —
`DataTable` ya envuelve en `overflow-x-auto`, confirmar visualmente que
no se rompe), AdminNav en `<lg` es el botón "seleccionar panel" +
drill-down a pantalla completa (no el sidebar), SectionNav/BottomNav
correctos según el ancho.

## 2. Foco, teclado y drawers/modales — interacciones específicas

En **cualquier pantalla de admin con un drawer** (p. ej.
`/admin/connect/lines` → "Conectar línea", o `/admin/org/members` →
invitar):

- [ ] Tab a través de toda la pantalla: el orden de foco sigue el orden
  visual (nav → contenido → acciones), sin saltos raros.
- [ ] Cada elemento enfocado muestra un anillo visible (halo de 2px
  fondo + 4px acento) — ya verificado en código que ningún componente
  quita el outline sin reemplazo (ver sección final), pero confirma
  visualmente que se ve bien en ambos temas, con foco por teclado
  (Tab), no solo por click.
- [ ] Abrir un drawer con click y con teclado (Enter/Space sobre el
  botón que lo abre) — en ambos casos el foco debe caer dentro del
  drawer (el botón de cerrar, normalmente).
- [ ] Con el drawer abierto, Tab repetido nunca sale del drawer (el
  foco debe ciclar: del último elemento vuelve al primero, y
  Shift+Tab desde el primero va al último).
- [ ] **Escape** cierra el drawer/modal y el foco vuelve exactamente al
  botón que lo abrió (no a otro lugar, no se pierde en el body).
- [ ] Repetir Escape + foco-trap en un **modal de confirmación**
  (p. ej. "Eliminar línea", "Pausar organización").
- [ ] Repetir outside-click + Escape en un **popover** (menú de avatar,
  `InfoPopover`, menú de fila de `DataTable`) — Escape debe devolver el
  foco al botón que abrió el popover.
- [ ] En `/admin` (AdminNav), atajo `/` enfoca la búsqueda de ajustes
  (si estás escribiendo en un input, `/` no debe hacer nada raro).
  Probar en desktop (sidebar) y en `<lg` (abre el drawer de navegación
  y luego enfoca la búsqueda dentro).

## 3. Reduced motion

Activa "reducir movimiento" del sistema (macOS: Ajustes → Accesibilidad
→ Pantalla → Reducir movimiento; o en Chrome DevTools → Rendering →
"Emulate CSS media feature prefers-reduced-motion: reduce").

- [ ] Abre y cierra un drawer, un modal y un popover — deben
  aparecer/desaparecer instantáneamente (sin deslizamiento/fundido),
  pero siguen siendo usables (nada queda invisible o no-interactivo).
- [ ] Con la búsqueda de ajustes de AdminNav, navega a un resultado y
  confirma que la ficha destino se resalta (el resaltado usa una
  animación de 900ms vía `@animationend` para limpiarse solo — con
  reduced-motion la duración se fuerza a 0ms; algunos navegadores
  igual disparan `animationend` a 0ms y otros no de forma consistente,
  así que si el resaltado se queda "pegado" tras el toggle, es un bug
  cosmético menor a reportar, no bloqueante).
- [ ] Crea un tenant en `/platform/tenants` (con platform-admin) y
  confirma que la fila nueva se resalta y el resaltado se limpia (mismo
  mecanismo `@animationend` que el punto anterior, en
  `TenantsView.vue`).

## 4. Cuentas de permisos (6.4)

### Con `tenant-admin@example.com` (sin `platform.manage`)
- [ ] SectionNav/BottomNav: **no** aparece la sección "Plataforma".
- [ ] Navegar directo a `/platform/tenants` en la URL → `ForbiddenState`.
- [ ] Navegar directo a `/platform/credentials` en la URL → `ForbiddenState`.
- [ ] El resto de la app (operaciones, sandbox, las 8 pantallas de
  admin) funciona con normalidad — este rol tiene todos los permisos
  de tenant.

### Con `platform-admin@example.com`
- [ ] Sección "Plataforma" visible en SectionNav (bajo divisor, con su
  eyebrow) y en BottomNav.
- [ ] `/platform/tenants`: buscar por nombre/slug, filtrar por estado,
  abrir el drawer de un tenant, crear un tenant nuevo (revisa que la
  fila nueva aparece resaltada con toast), pausar y reactivar un tenant
  desde la `DangerZone` del drawer.
- [ ] `/platform/credentials`: cambiar el selector de tenant propio de
  la pantalla, confirmar que la tabla recarga; guardar una credencial
  con secreto y confirmar que el campo de secreto queda vacío tras
  guardar y que no se puede volver a ver.
- [ ] Desde `/admin/org/info` con esta cuenta, o desde el drawer de un
  tenant en `/platform/tenants` → "Entrar como admin": confirma que
  navega a `/admin/org/info?tenant=<id>` y que el admin de ese tenant
  funciona (la membresía sintética le da acceso completo).
- [ ] En `/admin/connect/credentials`, confirma que aparece el link
  "Gestionar en Plataforma →" (solo visible para platform admins).

### Escenario "solo `agent_configs.manage`" — NO HAY CUENTA REAL PARA ESTO
Ver el hallazgo detallado más abajo. El backend actual solo tiene 3
roles de tenant (`tenant_admin`, `operator`, `viewer`) y únicamente
`tenant_admin` tiene `agent_configs.manage` — pero `tenant_admin`
también tiene los otros 6 permisos de tenant, así que **ninguna cuenta
sembrable hoy tiene *solo* ese permiso**. La lógica de gating del
frontend para este caso ya se verificó por trazado de código (ver
sección final) y es consistente con el spec. Para una verificación
visual real en el navegador:

1. Inicia sesión con **cualquier cuenta** (p. ej. `tenant-admin`).
2. Abre DevTools → Network, recarga, localiza la respuesta de
   `GET /api/v1/auth/session`.
3. Usa "Local overrides" (Chrome DevTools → Network → clic derecho →
   "Override content") o la extensión Requestly/ModHeader para
   reescribir esa respuesta: deja `memberships[0].permissions` como
   `["agent_configs.manage"]` únicamente, conservando el resto del
   payload igual.
4. Recarga la app con la respuesta interceptada y verifica:
   - [ ] AdminNav muestra **solo** el grupo "Asistente" (Comportamiento
     y Herramientas).
   - [ ] `/admin` (sin sub-ruta) cae en `/admin/assistant/behavior`.
   - [ ] Navegar directo a `/admin/org/members`,
     `/admin/connect/lines`, `/admin/connect/credentials`,
     `/admin/connect/data` o `/admin/activity` → `ForbiddenState` en
     los 5 casos.
   - [ ] El tab "Admin" en SectionNav/BottomNav sigue visible (requiere
     el fix aplicado a `canAccessAdmin`, ver hallazgos).

## Ya verificado en código — no repetir

**Gating de permisos (Part A):**
- `ADMIN_ROUTE_REQUIRES` (`frontend/src/modules/admin/router.ts:6-15`)
  ya tiene `/admin/org/info` y `/admin/connect/lines` corregidos a
  `['canManageTenant']` (decisión 12 de `design.md`) — task 1.2 quedó
  bien aplicada.
- El guard único (`frontend/src/router/guards.ts:60-76`) evalúa
  `meta.requires` con la misma función `hasRequiredAccess` que usa
  `AdminNav` y `resolveAdminFallback` — no hay implementación paralela
  ni riesgo de que diverjan.
- `resolveAdminFallback` (`frontend/src/modules/admin/router.ts:45-51`)
  camina `ADMIN_FALLBACK_ORDER` y cae correctamente en
  `assistant/behavior` para una membership que solo tenga
  `agent_configs.manage` (verificado por trazado línea a línea).
- Backend: `TenantAccess::TENANT_ROLE_PERMISSIONS`
  (`backend/app/Support/Auth/TenantAccess.php:15-33`) y
  `AuthSessionController::serializeMembership`
  (`backend/app/Http/Controllers/Api/AuthSessionController.php:103-128`)
  confirman que el payload de sesión es la única fuente de permisos
  del frontend, sin overrides. Verificado en vivo contra el stack con
  las 4 cuentas sembradas (`platform-admin`, `tenant-admin`,
  `operator`, `viewer`) — payloads reales incluidos en la tabla de la
  sección 0.
- Platform admin obtiene membresía sintética con **los 8 permisos**
  (incluye `platform.manage`) por cada tenant existente
  (`AuthSessionController.php:77-86`) — confirmado en vivo.

**Fix aplicado durante esta auditoría:**
- `canAccessAdmin` en `frontend/src/composables/useNavigationAccess.ts`
  no incluía `canManageTenantUsers` en su OR, a pesar de que
  `/admin/org/members` sí lo acepta como único requisito
  (`ADMIN_ROUTE_REQUIRES['/admin/org/members']`). Con los 3 roles
  reales esto era inalcanzable (`tenant_admin` es el único con
  `tenant_users.manage` y siempre trae `tenant.manage` también), pero
  es la clase exacta de bug que rompería el tab "Admin" del
  SectionNav/BottomNav si el backend alguna vez desacopla esos
  permisos. **Corregido**: se añadió `canManageTenantUsers.value` al
  OR. `npm run typecheck` y `npm run build` en verde tras el cambio.

**Accesibilidad/motion (Part B):**
- Regla global de reduced-motion: `frontend/src/style.css:211-219`
  (`@media (prefers-reduced-motion: reduce)` zerea
  animation/transition-duration y `scroll-behavior`). Ningún
  drawer/modal/popover depende de la animación para volverse visible o
  interactivo: todos usan `v-if` (no solo una clase CSS) para montar el
  panel, así que con duración 0 simplemente aparecen/desaparecen sin
  transición — visto en `UiDrawer.vue`, `UiModal.vue`,
  `UiPopover.vue`.
- Foco visible global: `frontend/src/style.css:199-208` —
  `button/a/input/select/textarea/[tabindex]:focus-visible` quita el
  outline nativo y pone un halo de `box-shadow` de 2px fondo + 4px
  acento. Todas las primitivas (`UiButton`, `UiInput`, `UiSelect`,
  `UiTextarea`, `UiCheckbox`, `UiSwitch`, `SearchInput`) renderizan
  elementos nativos (`<button>`/`<input>`/`<select>`/`<textarea>`), así
  que quedan cubiertas automáticamente. Los `outline: none` locales que
  sí existen (`SearchInput.vue:106` en `.search-input-field`,
  `UiTabs.vue:145-147` en `.ui-tabs-panel:focus`) solo pisan la
  propiedad `outline`, nunca `box-shadow`, así que el halo de foco
  sigue apareciendo — confirmado por especificidad de CSS, no rompen
  nada.
- Escape + focus-trap + restaurar foco: `useModalBehavior`
  (`frontend/src/composables/useModalBehavior.ts`) implementa
  exactamente lo que reclaman las tareas 2.5/2.6 — guarda
  `previousActiveElement`, bloquea scroll del body, enfoca el primer
  elemento focoable al abrir, cicla Tab/Shift+Tab dentro del panel, y
  devuelve el foco al cerrar (`onKeyDown`/`watch` en líneas 35-94).
  `UiDrawer.vue` y `UiModal.vue` lo consumen igual
  (`useModalBehavior({...})` en cada `<script setup>`). `UiPopover.vue`
  tiene su propia implementación más liviana (sin scroll-lock, sin
  autofocus al abrir — intencional para un popover, no un diálogo
  modal) pero sí Escape + outside-click + focus-trap con Tab/Shift+Tab
  (líneas 45-77).
- Anchos fijos: grep de `width:\s*[0-9]{3,}px|min-width:\s*[0-9]{3,}px`
  en todo `frontend/src` no encontró ningún riesgo real de overflow a
  360px — los `min-width` existentes (`ScopePicker.vue:97`,
  `TenantsView.vue:504`) viven dentro de contenedores
  `flex-wrap: wrap`, así que el elemento envuelve a su propia línea en
  vez de forzar scroll horizontal de página; `UiDrawer.vue:91` solo
  aplica `width: 480px` desde `min-width: 1024px` (a `<lg` es
  `width: 100vw`); `body { min-width: 320px }`
  (`style.css:170`) es un piso por debajo de 360px, no un problema.
  `DataTable.vue:3` ya envuelve la tabla en `overflow-x-auto`.
