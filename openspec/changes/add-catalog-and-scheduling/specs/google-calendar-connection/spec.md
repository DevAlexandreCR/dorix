# google-calendar-connection Specification (delta)

## ADDED Requirements

### Requirement: Conexión OAuth por línea de WhatsApp
El sistema SHALL permitir conectar una cuenta de Google Calendar por línea
de WhatsApp mediante OAuth 2.0 (authorization code). Un endpoint admin
tenant-scoped — gateado por `Permission::ManageAgentConfig` y en el grupo
de rutas de configuración (sin `tenant.active`), NO por `ManagePlatform`
como el upsert manual de credenciales (ver design D6) — SHALL generar la
URL de consentimiento con un `state` firmado que identifica tenant y
línea; el callback público `GET /api/oauth/google/callback` SHALL validar
el `state`, intercambiar el código por tokens y persistir el refresh
token encriptado en `api_credentials` con `provider: google_calendar` y
scope de línea.

#### Scenario: tenant_admin puede conectar
- **WHEN** un usuario con `Permission::ManageAgentConfig` solicita la URL
  de consentimiento para una línea de su tenant
- **THEN** recibe la URL con `state` firmado; un `viewer` recibe 403

#### Scenario: Conexión exitosa
- **WHEN** el callback recibe un `code` válido con `state` íntegro
- **THEN** se persiste (upsert) la credencial `google_calendar` de esa
  línea con el refresh token encriptado y el usuario es redirigido al
  panel admin con indicador de éxito

#### Scenario: State inválido
- **WHEN** el callback recibe un `state` alterado, expirado o de otro
  tenant
- **THEN** no se persiste ninguna credencial y se responde con error

### Requirement: Refresco de access tokens
El sistema SHALL obtener access tokens on-demand usando el refresh token
persistido, sin persistir nunca el access token, y SHALL actualizar
`last_used_at` de la credencial en cada uso.

#### Scenario: Token expirado se refresca de forma transparente
- **WHEN** una tool de agendamiento necesita llamar a la API de Google y
  no hay access token vigente en cache
- **THEN** el cliente refresca el token con el refresh token y la
  operación continúa sin intervención del usuario

### Requirement: Detección y señalización de conexión rota
Cuando el refresco falla de forma permanente (token revocado,
`invalid_grant`), el sistema SHALL marcar la conexión como rota, lanzar
`CalendarConnectionUnavailableException` hacia las tools y exponer el
estado por línea (`connected` | `broken` | `none`) en los datos del panel
admin.

#### Scenario: Token revocado durante conversación
- **WHEN** el comercio revocó el acceso desde su cuenta Google y el agente
  invoca una tool de agendamiento
- **THEN** la tool retorna handoff con razón clara, se registra un evento
  de agente y el panel admin muestra la conexión como `broken`

#### Scenario: Reconexión
- **WHEN** un admin repite el flujo de conexión sobre una línea con
  conexión rota
- **THEN** la credencial se reemplaza y el estado vuelve a `connected`
