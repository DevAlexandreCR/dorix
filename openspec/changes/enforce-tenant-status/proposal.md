# Enforce Tenant Status

## Why

`tenants.status` existe como columna string con default `'active'`, se
serializa a la API y se puede escribir vía `PATCH /admin/tenants/{id}`,
pero **el backend no la lee en ningún punto**: el literal `'active'` no
aparece en `app/`, no hay enum, y ni el webhook de Meta ni el middleware
de tenancy la consultan. El rediseño Pulso expone "Pausar / Reactivar la
organización" en una zona de peligro (`org/info`, y la pantalla de
tenants de plataforma), así que hoy esa acción cambiaría un string
decorativo mientras el asistente sigue respondiendo mensajes: una acción
destructiva que no hace lo que promete.

## What Changes

- **Enum `TenantStatus`** (`active`, `paused`) con cast en el modelo
  `Tenant` y validación en `UpsertTenantRequest`. **BREAKING**: `status`
  deja de aceptar cualquier string de ≤40 caracteres; valores fuera del
  enum se rechazan con 422.
- **Migración de normalización**: cualquier `tenants.status` existente
  fuera del enum pasa a `active` (no hay cambio de esquema; la columna ya
  existe).
- **El webhook de Meta deja de atender tenants pausados**: al resolver la
  línea, si el tenant está pausado no se persiste el mensaje entrante ni
  se despacha `ProcessIncomingMessageJob`. Se responde **200 con
  resultado vacío** (no un error) para que Meta no reintente, y se
  registra un `AgentEvent` `webhook_skipped_tenant_paused` para
  observabilidad. Los **status updates sí siguen persistiéndose**: son
  contabilidad de mensajes ya enviados y descartarlos congelaría su
  estado de entrega.
- **`ProcessIncomingMessageJob` verifica el estado al ejecutarse** y
  registra `agent_runtime_skipped_due_to_tenant_paused`, para los jobs
  que quedaron encolados antes de la pausa.
- **Las superficies operativas rechazan tenants pausados** con 403:
  conversaciones, sandbox y fuentes de datos. Se implementa en un
  middleware propio, no en `ResolveTenantContext`.
- **`/admin/**` sigue accesible con el tenant pausado**, a propósito: es
  la única forma de reactivar la organización y de configurarla mientras
  está pausada. Pausar significa "dejar de atender clientes", no "dejar
  fuera a los administradores".

## Capabilities

### New Capabilities

- `tenant-status`: significado y ciclo de vida del estado de una
  organización (`active` / `paused`), qué superficies bloquea el estado
  pausado, qué superficies siguen disponibles, y cómo se observa.

### Modified Capabilities

<!-- Ninguna: no existen specs previas en openspec/specs/. -->

## Impact

- **Solo backend** (`backend/app/**`, `backend/database/migrations/**`,
  `backend/routes/api.php`, `backend/tests/**`).
- Nuevo: `app/Enums/TenantStatus.php`, un middleware de gating y su
  registro en `bootstrap/app.php`.
- Modificado: `app/Models/Tenant.php` (cast), `UpsertTenantRequest`
  (validación), `MetaWhatsAppWebhookHandler` (skip + evento),
  `ProcessIncomingMessageJob` (segunda barrera + evento),
  `routes/api.php` (**partir en dos el grupo con `tenant.context`**: uno
  operativo con el middleware nuevo, uno de configuración sin él).
- Verificado sin cambios necesarios:
  `app/Support/Admin/AdminPanelDataBuilder::serializeTenant()` hace
  `'status' => $tenant->status`; los enums con backing scalar de PHP
  serializan a su valor, así que la respuesta de la API no cambia.
- **No cambia**: `PATCH /admin/tenants/{id}` sigue autorizando con
  `Permission::ManageTenant` (un `tenant_admin` puede pausar su propia
  organización) y sigue fuera del grupo con `tenant.context`, así que
  reactivar nunca queda bloqueado por el propio gate.
- **Frontend: sin cambios.** La UI de pausar/reactivar ya existe
  (`redesign-ui-pulso` task 4.6) y su task 5.2 queda desbloqueada al
  mergear este cambio.
