# Spec — tenant-status

## ADDED Requirements

### Requirement: Estados válidos de una organización

El estado de una organización SHALL ser uno de exactamente dos valores:
`active` o `paused`. La API SHALL rechazar con 422 cualquier otro valor, y
las migraciones SHALL normalizar a `active` los valores heredados que
queden fuera del enum.

#### Scenario: Estado válido aceptado

- **WHEN** un usuario con `ManageTenant` envía
  `PATCH /api/v1/admin/tenants/{id}` con `status: "paused"`
- **THEN** la respuesta es 200 y el tenant queda con estado `paused`

#### Scenario: Estado inválido rechazado

- **WHEN** un usuario con `ManageTenant` envía
  `PATCH /api/v1/admin/tenants/{id}` con `status: "archivado"`
- **THEN** la respuesta es 422 y el estado del tenant no cambia

#### Scenario: Valores heredados se normalizan

- **WHEN** se ejecutan las migraciones sobre una base con un tenant cuyo
  `status` no es `active` ni `paused`
- **THEN** ese tenant queda con estado `active`

### Requirement: Una organización pausada no atiende mensajes de WhatsApp

Los mensajes entrantes de Meta SHALL NOT persistirse ni procesarse
mientras la organización está pausada, y el asistente SHALL NOT
responder. El webhook SHALL responder 2xx para que Meta no reintente, y
SHALL registrar un `AgentEvent` `webhook_skipped_tenant_paused`.

#### Scenario: Mensaje entrante para una organización pausada

- **WHEN** llega un `POST /api/webhooks/meta/whatsapp` con un mensaje para
  una línea cuya organización está pausada
- **THEN** la respuesta es 200, no se crea ningún `ConversationMessage`,
  no se despacha `ProcessIncomingMessageJob`, y se registra un
  `AgentEvent` de tipo `webhook_skipped_tenant_paused`

#### Scenario: Meta no reintenta

- **WHEN** el webhook descarta un mensaje por organización pausada
- **THEN** la respuesta HTTP es exitosa (2xx), de modo que Meta no
  reintenta la entrega

#### Scenario: Job encolado antes de la pausa

- **WHEN** un `ProcessIncomingMessageJob` se ejecuta después de que su
  organización pasó a `paused`
- **THEN** el job termina sin invocar al agente y sin enviar respuesta, y
  registra un `AgentEvent` de tipo
  `agent_runtime_skipped_due_to_tenant_paused`

#### Scenario: Los status updates siguen registrándose

- **WHEN** llega un status update de Meta (por ejemplo `failed`) para un
  mensaje ya enviado de una organización pausada
- **THEN** el estado del `ConversationMessage` se actualiza con
  normalidad, porque es contabilidad de un mensaje ya enviado y no
  atiende a ningún cliente

#### Scenario: Reactivar restablece la atención

- **WHEN** una organización pausada vuelve a `active` y llega un mensaje
  nuevo
- **THEN** el mensaje se persiste y se despacha el procesamiento con
  normalidad

### Requirement: Una organización pausada bloquea las superficies operativas

Las rutas de conversaciones, sandbox y fuentes de datos SHALL responder
403 con el código de error `tenant_paused` mientras la organización está
pausada, y SHALL comportarse con normalidad cuando está activa.

#### Scenario: Conversaciones bloqueadas

- **WHEN** un usuario autenticado de una organización pausada pide
  `GET /api/v1/conversations`
- **THEN** la respuesta es 403 con el código de error `tenant_paused`

#### Scenario: Sandbox bloqueado

- **WHEN** un usuario autenticado de una organización pausada pide
  `POST /api/v1/agent-sandbox/sessions`
- **THEN** la respuesta es 403 con el código de error `tenant_paused`

#### Scenario: Fuentes de datos bloqueadas

- **WHEN** un usuario autenticado de una organización pausada pide
  `GET /api/v1/data-sources`
- **THEN** la respuesta es 403 con el código de error `tenant_paused`

#### Scenario: Organización activa no se ve afectada

- **WHEN** un usuario autenticado de una organización activa pide
  `GET /api/v1/conversations`
- **THEN** la respuesta es 200

### Requirement: La administración sigue disponible con la organización pausada

Pausar una organización SHALL NOT impedir administrarla: las rutas
`/admin/**` SHALL seguir respondiendo con normalidad, de modo que
reactivarla sea siempre posible tanto para el `tenant_admin` propio como
para un platform admin.

#### Scenario: El panel de administración carga

- **WHEN** un `tenant_admin` de una organización pausada pide
  `GET /api/v1/admin/overview`
- **THEN** la respuesta es 200

#### Scenario: El resto de la configuración también sigue disponible

- **WHEN** un `tenant_admin` de una organización pausada pide
  `GET /api/v1/admin/whatsapp-lines` u otra ruta de configuración
  tenant-scoped bajo `/admin/**`
- **THEN** la respuesta no es 403 por estado pausado

#### Scenario: Reactivar desde la propia organización

- **WHEN** un `tenant_admin` que pausó su propia organización envía
  `PATCH /api/v1/admin/tenants/{id}` con `status: "active"`
- **THEN** la respuesta es 200 y la organización queda activa

#### Scenario: Un platform admin reactiva cualquier organización

- **WHEN** un usuario con `ManagePlatform` envía
  `PATCH /api/v1/admin/tenants/{id}` con `status: "active"` para una
  organización pausada de la que no es miembro directo
- **THEN** la respuesta es 200 y la organización queda activa
