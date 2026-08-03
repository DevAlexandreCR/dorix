# Tasks — Enforce Tenant Status

> Backend-only (`backend/**`). Gate por tarea:
> `docker compose exec php-fpm php artisan test` en verde.

## 1. Estado como tipo

- [x] 1.1 Migración de normalización idempotente: los `tenants.status`
  fuera del enum pasan a `active`. Sin cambio de esquema (la columna ya
  es `string` con default `active`); `down()` vacío y documentado. **Va
  primero** para que el cast de 1.2 nunca lea un valor inválido.
- [x] 1.2 Crear `app/Enums/TenantStatus.php` (`active`, `paused`)
  siguiendo el estilo de los enums existentes (`Permission`,
  `TenantRole`, `ConversationStatus`); añadir el cast de `status` en
  `Tenant::casts()`.
- [x] 1.3 Validar `status` con `Rule::enum(TenantStatus::class)` en
  `UpsertTenantRequest` (sustituye `string|max:40`). Test: 422 ante un
  valor fuera del enum, 200 ante `paused`.
- [x] 1.4 Grep de seguridad antes de seguir: `backend/database/factories`,
  `backend/database/seeders` y `backend/tests` en busca de literales de
  `status` de tenant fuera del enum; corregir lo que aparezca. (El
  `DatabaseSeeder` ya usa `'active'`, pero la revisión de spec no pudo
  enumerar factories ni tests — trátalo como carga real, no formalidad.)

## 2. Gate operativo

- [x] 2.1 Crear el middleware `EnsureTenantIsActive` que lea el tenant de
  `TenantContextManager` y lance un `ApiException` 403 `tenant_paused`
  (con clave de traducción, siguiendo `RequestTenantContextResolver`);
  registrarlo con alias en `bootstrap/app.php`. Debe correr **después**
  de `tenant.context`, del que depende para tener tenant en contexto.
- [x] 2.2 **Partir en dos el grupo de `routes/api.php:28-60`.** Hoy las
  rutas operativas y las seis de configuración tenant-scoped comparten un
  único `Route::middleware(['web','auth:sanctum','tenant.context'])`.
  Crear: (a) un sub-grupo **operativo** con `EnsureTenantIsActive` que
  contenga `/conversations/**`, `/agent-sandbox/**` y
  `/data-sources/**`; (b) un sub-grupo de **configuración** sin el
  middleware nuevo con `/admin/overview`, `/admin/tenant-users`,
  `/admin/whatsapp-lines`, `/admin/agent-configs`, `/admin/tool-configs`
  y `/admin/credentials`. Añadir el middleware al grupo compartido tal
  como está hoy bloquearía `/admin/overview` y recrearía el auto-bloqueo
  que la decisión 3 evita.
- [x] 2.3 Añadir la clave de traducción del error en los archivos de
  idioma existentes de la API (`lang/*/api.php`).
- [x] 2.4 Tests del gate:
  - 403 `tenant_paused` con tenant pausado en cada prefijo operativo,
    **incluyendo al menos un endpoint de escritura por prefijo** (no solo
    el index): p. ej. `POST /conversations/{id}/manual-reply`,
    `POST /agent-sandbox/sessions/{id}/messages`,
    `POST /data-sources`.
  - 200 en las mismas rutas con tenant activo.
  - **200 con tenant pausado en `GET /admin/overview` y en al menos una
    segunda ruta de configuración** (p. ej. `/admin/whatsapp-lines`),
    para detectar un arreglo parcial de 2.2.
  - Reactivación exitosa vía `PATCH /admin/tenants/{id}` tanto por el
    `tenant_admin` propio como por un platform admin.

## 3. Pipeline de mensajes

- [x] 3.1 En `MetaWhatsAppWebhookHandler`, tras resolver la línea y antes
  de persistir: si el tenant está pausado, omitir el **mensaje entrante**
  (sin `ConversationMessage`, sin `ProcessIncomingMessageJob`), registrar
  el `AgentEvent` `webhook_skipped_tenant_paused` dentro del contexto de
  tenant, y devolver un `WebhookHandlingResult` vacío → **HTTP 200**
  (design.md decisión 5).
- [x] 3.2 **No tocar `persistStatusUpdate`**: los status updates se
  siguen persistiendo con el tenant pausado (design.md decisión 5). Son
  contabilidad de mensajes ya enviados; descartarlos congelaría su estado
  de entrega en silencio. Añadir un test que lo fije como
  comportamiento esperado, no un accidente.
- [x] 3.3 En `ProcessIncomingMessageJob`, verificar el estado del tenant
  al ejecutarse y terminar sin invocar al agente si está pausado,
  registrando `agent_runtime_skipped_due_to_tenant_paused` vía
  `AgentEventRecorder` — igual que los caminos de skip existentes
  (`agent_runtime_skipped_due_to_status`,
  `agent_runtime_skipped_due_to_configuration`). Sin este evento sería el
  único punto ciego del pipeline en el timeline de Actividad
  (design.md decisión 6).
- [x] 3.4 Tests: webhook con tenant pausado → 200, cero mensajes
  persistidos, cero jobs despachados, evento registrado; status update
  con tenant pausado → el `ConversationMessage` **sí** se actualiza; job
  encolado que corre tras la pausa → no invoca al agente, no envía
  respuesta y registra su evento; tras reactivar, el flujo normal vuelve
  a funcionar de punta a punta.

## 4. Cierre

- [x] 4.1 `docker compose exec php-fpm php artisan test` completo en
  verde.
- [x] 4.2 Verificar que `redesign-ui-pulso` task 5.2 queda desbloqueada:
  la UI de Pausar/Reactivar ya existente en `org/info` produce un efecto
  real (probar de punta a punta con el stack levantado).
