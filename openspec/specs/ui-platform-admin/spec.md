# ui-platform-admin Specification

## Purpose
Superficie de plataforma (`/platform`): gestión de tenants y de
credenciales por tenant, separada del admin de tenant.

Fuente de diseño: `design/06-platform-admin.md`.
## Requirements
### Requirement: Sección /platform gated
El SectionNav SHALL mostrar la sección Plataforma (bajo un divisor,
con eyebrow propio) únicamente a usuarios con `platform.manage`, y
todas las rutas `/platform/**` SHALL estar protegidas por el guard con
`canManagePlatform`. `/platform` SHALL redirigir a
`/platform/tenants`.

#### Scenario: Usuario sin permiso de plataforma
- **WHEN** un tenant admin sin `platform.manage` navega a
  `/platform/tenants`
- **THEN** ve `ForbiddenState` y la sección Plataforma no aparece en
  su navegación

### Requirement: Gestión de tenants
`/platform/tenants` SHALL listar todos los tenants en `DataTable`
(nombre, slug en mono, estado, fecha de creación — solo campos que
entrega `GET /admin/tenants`) con búsqueda por nombre/slug y filtro
por estado. Un drawer SHALL permitir ver el detalle, crear tenants
(nombre + slug autogenerado editable + estado inicial) y
pausar/reactivar vía DangerZone; el estado SHALL mostrarse como badge
de solo lectura, nunca como select libre. "Entrar como admin" SHALL
navegar a `/admin/org/info?tenant=<id>` (los platform admins tienen
membresía sintética en todos los tenants, así que la acción está
siempre disponible para ellos).

#### Scenario: Crear un tenant
- **WHEN** el platform admin crea un tenant desde el drawer
- **THEN** la tabla muestra la fila nueva resaltada con toast "Tenant
  creado", sin cambiar el tenant activo del usuario

#### Scenario: Pausar un tenant
- **WHEN** el platform admin pausa un tenant desde la zona de peligro
  del drawer
- **THEN** la confirmación explica que sus líneas dejarán de responder
  y, al confirmar, el estado en la tabla pasa a Pausada

### Requirement: Gestión de credenciales por tenant
`/platform/credentials` SHALL operar sobre **un tenant a la vez**: la
pantalla SHALL mostrar un selector de tenant propio (visible siempre,
independiente del TopBar) y listar las credenciales de ese tenant
(proveedor, llave en mono, ámbito global o por línea, estado, último
uso) usando los endpoints tenant-scoped existentes. El upsert vía
drawer (ámbito, proveedor, llave, secreto write-only) SHALL escribir
contra el tenant seleccionado. El secreto SHALL limpiarse del
formulario tras guardar y SHALL NOT volver a mostrarse.

#### Scenario: Guardar un secreto
- **WHEN** el platform admin, con el tenant "La Espiga" seleccionado,
  guarda una credencial con secreto
- **THEN** la credencial se escribe para "La Espiga", aparece el toast
  "Credencial guardada", el campo de secreto queda vacío y la tabla
  muestra la credencial como Configurada

#### Scenario: Cambiar de tenant en la pantalla
- **WHEN** el platform admin cambia el tenant del selector de la
  pantalla
- **THEN** la tabla recarga con las credenciales del tenant elegido

### Requirement: Separación de ámbitos
Las pantallas de admin de tenant SHALL NOT contener acciones de
plataforma: crear tenants y editar secretos existe solo bajo
`/platform/**`. `/admin/connect/credentials` SHALL ser de solo
lectura para todos los roles, con un link "Gestionar en Plataforma →"
visible únicamente para platform admins.

Excepción acotada: las credenciales de línea derivadas del flujo de
Embedded Signup de Meta (par `whatsapp_meta`/`access_token` y su
`registration_pin`) SHALL escribirlas el backend automáticamente al
completar la conexión iniciada por un tenant admin desde
`/admin/connect/lines`. En este flujo la frontera de autorización la
impone Meta — el token intercambiado solo cubre los assets que el
usuario autorizó en el popup — y el secreto MUST NOT mostrarse ni
editarse en ninguna pantalla de `/admin/**`. La edición y rotación
manual de secretos sigue existiendo solo bajo `/platform/**`.

#### Scenario: Credenciales del tenant en solo lectura
- **WHEN** cualquier usuario abre `/admin/connect/credentials`
- **THEN** ve la lista de metadatos sin ningún formulario de edición;
  si además tiene `platform.manage`, ve el link a
  `/platform/credentials`

#### Scenario: Credencial creada por Embedded Signup
- **WHEN** un tenant admin completa el flujo de conexión de una línea
- **THEN** la credencial `access_token` aparece en
  `/admin/connect/credentials` como metadato Configurada, sin que
  ninguna pantalla de `/admin/**` haya mostrado ni aceptado el
  secreto en un formulario

