# ui-platform-admin — delta

## MODIFIED Requirements

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
