# whatsapp-line-connection Specification

## Purpose
TBD - created by archiving change add-meta-embedded-signup. Update Purpose after archive.
## Requirements
### Requirement: Conexión de línea vía Embedded Signup
El sistema SHALL exponer `POST /api/v1/admin/whatsapp-lines/connect` (gate `Permission::ManageTenant`) que recibe `{ code, phone_number_id, waba_id, connection_mode }`, intercambia el authorization code por un business token contra Graph API usando el app id/app secret de la plataforma, y deja la línea operativa en una sola operación. El access token MUST intercambiarse exclusivamente server-side y MUST NOT devolverse en ninguna respuesta al frontend.

#### Scenario: Conexión exitosa en modo Cloud API
- **WHEN** un tenant admin envía un code válido con `connection_mode=cloud_api`
- **THEN** el backend intercambia el code por un token, crea la línea con `connection_mode=cloud_api`, `status=active`, `is_enabled=true`, y responde `201` con la línea serializada (sin el token)

#### Scenario: Conexión exitosa en modo coexistencia
- **WHEN** un tenant admin envía un code válido con `connection_mode=coexistence`
- **THEN** la línea se crea con `connection_mode=coexistence` y no se llama al endpoint `register` de Graph

#### Scenario: Code inválido o expirado
- **WHEN** Graph rechaza el intercambio del code
- **THEN** el backend responde `422` con un mensaje de error traducible y no persiste línea ni credencial

#### Scenario: Número ya conectado a otro tenant
- **WHEN** el `phone_number_id` ya pertenece a una línea de otro tenant
- **THEN** el backend responde `409` sin revelar a qué tenant pertenece y no realiza llamadas de escritura a Graph ni a la DB

#### Scenario: Reconexión del mismo tenant
- **WHEN** el `phone_number_id` ya pertenece a una línea del mismo tenant
- **THEN** la línea existente se actualiza (modo, estado) y su credencial `access_token` se reemplaza por el nuevo token

#### Scenario: Usuario sin permiso
- **WHEN** un usuario con rol `operator` o `viewer` llama al endpoint
- **THEN** el backend responde `403` y no realiza ninguna llamada a Graph

### Requirement: Aprovisionamiento post-conexión en Graph
Tras un intercambio de token exitoso, el sistema SHALL suscribir la app de la plataforma a la WABA (`POST /{waba_id}/subscribed_apps`) y, solo en modo `cloud_api`, registrar el número (`POST /{phone_number_id}/register`) con un PIN de 6 dígitos generado aleatoriamente. Las llamadas a Graph SHALL completarse antes de persistir línea y credencial; si Graph falla, nada se persiste.

#### Scenario: Suscripción de la WABA
- **WHEN** el intercambio de token tiene éxito
- **THEN** el backend llama `POST /{waba_id}/subscribed_apps` con el token de la línea antes de responder

#### Scenario: Registro del número en Cloud API
- **WHEN** la conexión es `cloud_api`
- **THEN** el backend llama `POST /{phone_number_id}/register` con un PIN generado, y el PIN se guarda como credencial de línea `credential_key=registration_pin`

#### Scenario: Falla la suscripción o el registro
- **WHEN** `subscribed_apps` o `register` devuelven error
- **THEN** el backend responde `422` con el detalle del paso fallido y no persiste línea ni credencial

### Requirement: Almacenamiento del token por línea
El token obtenido SHALL guardarse cifrado en `api_credentials` con scope de línea, `provider=whatsapp_meta` y `credential_key=access_token` — el par exacto que resuelve el sender saliente. El upsert SHALL reutilizar la fila existente si la línea ya tenía token (rotación), nunca duplicarla.

#### Scenario: El sender encuentra el token sin configuración adicional
- **WHEN** una línea conectada vía Embedded Signup envía su primer mensaje saliente
- **THEN** `MetaGraphOutboundMessageSender` resuelve la credencial line-scoped sin intervención del platform admin

#### Scenario: Rotación en reconexión
- **WHEN** un tenant reconecta una línea que ya tenía credencial `access_token`
- **THEN** la misma fila de `api_credentials` se actualiza con el nuevo secreto cifrado

### Requirement: Modo de conexión de la línea
`whatsapp_lines` SHALL tener una columna `connection_mode` con valores `cloud_api` | `coexistence` (enum PHP `WhatsAppConnectionMode`, default `cloud_api`), incluida en la serialización admin de la línea. El valor MUST validarse con `Rule::enum` en el endpoint connect y MUST NOT ser editable como texto libre.

#### Scenario: Líneas existentes tras la migración
- **WHEN** corre la migración sobre líneas ya creadas
- **THEN** todas quedan con `connection_mode=cloud_api` y siguen operando sin cambios

#### Scenario: Modo visible en el admin
- **WHEN** un tenant admin consulta el overview del admin
- **THEN** cada línea incluye `connection_mode` en su payload serializado

### Requirement: Verificación de firma del webhook
El POST de `/api/webhooks/meta/whatsapp` SHALL verificar el header `X-Hub-Signature-256` calculando HMAC-SHA256 del cuerpo crudo con el app secret y comparando con `hash_equals`. Firma ausente, inválida, o app secret no configurado SHALL resultar en `403` sin procesar el payload (fail-closed). La comparación del verify token en el GET SHALL usar `hash_equals`.

#### Scenario: Firma válida
- **WHEN** llega un POST con firma HMAC-SHA256 correcta del cuerpo
- **THEN** el payload se procesa normalmente

#### Scenario: Firma inválida o ausente
- **WHEN** llega un POST sin header de firma o con firma incorrecta
- **THEN** el backend responde `403` y no encola ningún procesamiento

#### Scenario: App secret no configurado
- **WHEN** `META_APP_SECRET` no está definido en el entorno
- **THEN** todo POST al webhook responde `403` y se registra un warning de configuración

### Requirement: Tolerancia a webhooks de coexistencia
El pipeline de webhooks SHALL aceptar los fields de coexistencia sin error: `smb_message_echoes` se persiste como mensaje saliente de la conversación con `payload.source = business_app` (la convención existente de origen por mensaje en `ConversationMessage`, no un caso nuevo del enum `ConversationSource`) sin disparar el pipeline del agente ni alterar el estado de la conversación; `history`, `smb_app_state_sync` y cualquier field no reconocido SHALL responderse con `200` y descartarse con log informativo.

#### Scenario: Echo de mensaje enviado desde la app de WhatsApp Business
- **WHEN** llega un webhook con field `smb_message_echoes` para una línea en coexistencia
- **THEN** el mensaje se registra como saliente con `payload.source = business_app` en la conversación correspondiente y no se encola `ProcessIncomingMessageJob`

#### Scenario: Fields de historial y sincronización de contactos
- **WHEN** llega un webhook con field `history` o `smb_app_state_sync`
- **THEN** el backend responde `200` sin procesar el contenido y sin registrar error

#### Scenario: Mensaje entrante en coexistencia
- **WHEN** llega un webhook con field `messages` para una línea en coexistencia
- **THEN** sigue el pipeline existente (normalización → `ProcessIncomingMessageJob` → agente) sin cambios

### Requirement: Configuración de plataforma para Embedded Signup
Las claves `META_APP_ID`, `META_APP_SECRET` y `META_ES_CONFIG_ID` SHALL leerse vía `config/services.php` (`services.whatsapp.meta.*`) y SHALL documentarse en `backend/.env.example` junto con las claves `WHATSAPP_META_*` existentes. El frontend SHALL recibir app id y config id vía `VITE_META_APP_ID` / `VITE_META_ES_CONFIG_ID`; el app secret MUST NOT exponerse al frontend por ningún canal.

#### Scenario: Variables documentadas
- **WHEN** un desarrollador copia `.env.example`
- **THEN** encuentra todas las claves Meta necesarias (app, webhook, base_url/api_version) con comentarios de dónde obtenerlas

#### Scenario: Nombre inicial de la línea
- **WHEN** la conexión se completa y Meta no aporta un nombre de línea
- **THEN** el backend consulta `GET /{phone_number_id}?fields=verified_name,display_phone_number` y usa `verified_name` (fallback: número visible) como nombre inicial, editable después desde el drawer

