# Design — Enforce Tenant Status

## Context

Hallado durante la implementación de `redesign-ui-pulso` (task 4.6): la
UI del rediseño expone "Pausar / Reactivar la organización" en una
`DangerZone`, pero `tenants.status` es decorativo. Estado verificado del
backend:

- `database/migrations/2026_04_28_000100_create_core_domain_tables.php:15`
  — `$table->string('status')->default('active')` en `tenants`.
- `app/Models/Tenant.php` — `$guarded = []`, sin cast de `status`, sin
  enum.
- `app/Http/Requests/Api/UpsertTenantRequest.php` — `status` validado
  solo como `string|max:40`.
- `app/Http/Controllers/Api/AdminTenantController::update` — autoriza
  `Permission::ManageTenant` sobre el tenant, así que un `tenant_admin`
  puede cambiar el estado de su propia organización.
- Un grep de `'active'` sobre `app/` no arroja resultados: **nada lee el
  campo**.
- `routes/api.php:65-70` — el grupo `/admin/tenants` (index, store,
  update, destroy) usa `['web', 'auth:sanctum']` **sin**
  `tenant.context`; el resto de `/v1` usa
  `['web', 'auth:sanctum', 'tenant.context']`.

## Goals / Non-Goals

**Goals:**
- Que "pausado" tenga un efecto real y observable.
- Que pausar no pueda dejar a nadie sin forma de reactivar.
- Que Meta no acumule reintentos por mensajes de tenants pausados.

**Non-Goals:**
- Estados adicionales (`trial`, `suspended`, `archived`): solo
  `active` / `paused`.
- Facturación, cuotas o expiración automática.
- Cambios de frontend (la UI ya existe).
- Pausar líneas individuales: eso ya lo cubre `whatsapp_lines.is_enabled`.

## Decisions

1. **Enum `TenantStatus` en `app/Enums/`** (`active`, `paused`) junto a
   los enums existentes (`Permission`, `TenantRole`,
   `ConversationStatus`), con cast en `Tenant::casts()` y
   `Rule::enum(TenantStatus::class)` en `UpsertTenantRequest`. Alternativa
   rechazada: constantes de clase — el repo ya usa enums nativos.
2. **Sin cambio de esquema.** La columna ya es un string con default
   `active`. La migración solo normaliza filas con valores fuera del enum
   (`UPDATE tenants SET status = 'active' WHERE status NOT IN (...)`), de
   modo que el cast no reviente al leer datos viejos. Alternativa
   rechazada: convertir la columna a un enum nativo de Postgres —
   innecesario y peor para migraciones futuras.
3. **Gate operativo en un middleware propio
   (`EnsureTenantIsActive`), no en `ResolveTenantContext`.**
   `ResolveTenantContext` es infraestructura de tenancy que corre en todo
   `/v1` incluido `/admin/**`; meter el gate ahí bloquearía el panel de
   administración de un tenant pausado y con ello la única vía de
   reactivarlo desde la UI (que carga `GET /admin/overview`, ruta
   tenant-scoped). El middleware nuevo se aplica **solo** a las rutas
   operativas: `/conversations/**`, `/agent-sandbox/**`,
   `/data-sources/**`. Responde 403 con un `ApiException` traducible
   (`tenant_paused`), siguiendo el patrón de
   `RequestTenantContextResolver`. Debe correr **después** de
   `tenant.context`, porque lee el tenant de `TenantContextManager`.

   **Requiere reestructurar `routes/api.php`.** Hoy las tres rutas
   operativas y las seis de `/admin/**` tenant-scoped
   (`/admin/overview`, `/admin/tenant-users`, `/admin/whatsapp-lines`,
   `/admin/agent-configs`, `/admin/tool-configs`, `/admin/credentials`)
   viven en **un mismo** `Route::middleware(['web','auth:sanctum',
   'tenant.context'])` (`routes/api.php:28-60`). Hay que partirlo en dos
   sub-grupos: uno operativo con el middleware nuevo y uno de
   configuración sin él. Añadir el middleware al grupo compartido tal
   como está reproduciría exactamente el auto-bloqueo que esta decisión
   busca evitar.

   El `/agent-sandbox/**` **sí** se bloquea: aunque es una superficie de
   prueba interna, ejecuta el runtime del agente de verdad (consume
   modelo y herramientas), y "pausado" debe significar que el agente no
   corre. No es solo atención al cliente final.
4. **`/admin/**` sigue disponible con el tenant pausado**, a propósito.
   Pausar = "dejar de atender clientes", no "cerrar el panel". Esto
   también evita el auto-bloqueo: un `tenant_admin` que pausa su propia
   organización puede volver a entrar y reactivarla.
5. **El webhook responde 200, no un error.** En
   `MetaWhatsAppWebhookHandler::handle`, tras resolver la línea y antes de
   persistir, si `tenant->status === TenantStatus::Paused` se omite el
   mensaje entrante: no se crea `ConversationMessage`, no se despacha
   `ProcessIncomingMessageJob`, y se registra el `AgentEvent`
   `webhook_skipped_tenant_paused` vía `AgentEventRecorder` (dentro del
   contexto de tenant, como el resto de eventos del handler). Devolver
   4xx/5xx haría que Meta reintente el mismo mensaje durante horas; 200
   con `WebhookHandlingResult` vacío lo cierra limpiamente.

   **Los status updates entrantes SÍ se persisten con el tenant
   pausado.** `persistStatusUpdate` es pura contabilidad sobre mensajes
   **ya enviados** (`ConversationMessage.status` / `sent_at` /
   `error_code`): no invoca al agente y no atiende a nadie. Descartarlos
   congelaría en silencio el estado de entrega de los mensajes que
   salieron antes de la pausa, de modo que al reactivar se verían como
   `sent` mensajes que Meta reportó `failed` o `read`, sin rastro del
   porqué. Alternativa rechazada: extender el skip a los status updates
   "por simetría" con los mensajes entrantes — la justificación de los
   entrantes (no invocar al agente, no acumular reintentos) simplemente
   no aplica acá.
6. **`ProcessIncomingMessageJob` también verifica el estado** al
   ejecutarse, y registra
   `agent_runtime_skipped_due_to_tenant_paused`. El job puede haber
   quedado encolado antes de la pausa; sin esta verificación el asistente
   respondería mensajes ya aceptados minutos después de pausar. Es una
   segunda barrera, no un reemplazo de la decisión 5. El evento es
   obligatorio: el job ya registra
   `agent_runtime_skipped_due_to_status` y
   `agent_runtime_skipped_due_to_configuration` para sus otros caminos de
   skip, y dejar este sin evento lo volvería el único punto ciego del
   pipeline en el timeline de Actividad.
7. **Los platform admins pueden pausar y reactivar cualquier tenant**, y
   los `tenant_admin` solo el propio: se conserva tal cual la
   autorización actual (`Permission::ManageTenant` sobre el tenant en
   `update`), sin tocar gates ni permisos.

## Risks / Trade-offs

- [Un tenant pausado deja de recibir mensajes sin avisar al cliente
  final] → fuera de alcance; el mensaje entrante simplemente no se
  atiende. Queda registrado como `webhook_skipped_tenant_paused` y visible
  en el timeline de Actividad, que ya mapea eventos desconocidos con un
  fallback.
- [La validación por enum rompe integraciones que escribían otros
  strings] → **BREAKING** declarado; no hay consumidores conocidos fuera
  del propio frontend, y la migración normaliza lo existente.
- [Elegir 200 en el webhook oculta el descarte en los logs de Meta] →
  mitigado con el `AgentEvent` dedicado, que es donde el equipo ya mira.
- [Un `operator` o `viewer` en una organización pausada recibe un 403
  pelado y no tiene forma de entender por qué] → **limitación aceptada**.
  Esos roles solo tienen permisos `conversations.*`, así que
  `canAccessAdmin` es falso y no pueden llegar a `org/info` a ver el
  estado. Como este cambio es backend-only a propósito, el 403
  `tenant_paused` no se traduce a un mensaje propio en la UI. Fast-follow
  recomendado (fuera de alcance): que el frontend detecte el código
  `tenant_paused` y muestre "tu organización está pausada; pídele a un
  administrador que la reactive".

## Migration Plan

Una sola migración de datos idempotente (normalizar `status` fuera del
enum a `active`). Sin downtime, sin backfill costoso, sin cambio de
esquema. Reversible: el `down()` no necesita hacer nada porque no hay
cambio estructural.

## Open Questions

Ninguna. La pregunta sobre si el **sandbox** debía quedar abierto con el
tenant pausado quedó resuelta en la decisión 3: se bloquea, porque
ejecuta el runtime real del agente.
