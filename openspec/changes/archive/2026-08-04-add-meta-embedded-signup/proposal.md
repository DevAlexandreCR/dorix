# Add Meta Embedded Signup for WhatsApp Line Connection

## Why

Hoy un tenant puede crear una línea de WhatsApp desde el admin, pero la línea no puede funcionar sin que un platform admin cargue manualmente el `access_token` en `/platform/credentials`, y el concepto de coexistencia (seguir usando la app de WhatsApp Business junto a la API) no existe en el código. Dorix ya es Tech Provider de Meta con app id y app secret propios, lo que habilita el flujo oficial de **Embedded Signup**: el tenant hace clic en "Conectar", autoriza en Meta, y el backend recibe e intercambia el token automáticamente — sin intervención del platform admin y con soporte tanto para coexistencia como para Cloud API directa.

## What Changes

- Nuevo flujo de conexión self-service: botón "Conectar con WhatsApp" en `/admin/connect/lines` que lanza Embedded Signup de Meta (Facebook JS SDK), reemplazando el drawer manual de crear línea como vía principal.
- Nuevo endpoint backend que recibe el resultado del signup (authorization code + session info con `phone_number_id` y `waba_id`), intercambia el code por un business token contra Graph API, crea la línea y almacena el token cifrado en `api_credentials` con scope de línea — automáticamente, sin exponer el secreto al frontend ni al tenant.
- Post-conexión automática: suscripción de la app a los webhooks de la WABA (`POST /{waba_id}/subscribed_apps`) y, en modo Cloud API directa, registro del número (`POST /{phone_number_id}/register`).
- Nuevo campo `connection_mode` en `whatsapp_lines` (`cloud_api` | `coexistence`), poblado según el flujo de signup elegido por el tenant.
- Soporte de coexistencia en el pipeline de webhooks: aceptar y no fallar ante los fields `history` y `smb_message_echoes`; los echoes se registran en la conversación como mensajes salientes enviados por humano.
- Verificación de firma `X-Hub-Signature-256` en el webhook usando el app secret (hoy el POST del webhook es completamente abierto).
- Nuevas claves de configuración de plataforma: `META_APP_ID`, `META_APP_SECRET`, `META_ES_CONFIG_ID` (configuration id de Embedded Signup), documentadas en `.env.example`.
- El drawer manual de creación de línea queda como fallback secundario (sin cambios de contrato); la edición de línea y el flujo de credenciales de plataforma existentes no cambian.
- Fix relacionado: el hint de `credential_key` en la UI de credenciales de plataforma dice `wa_token` pero el sender exige `access_token`; se corrige el hint.

## Capabilities

### New Capabilities

- `whatsapp-line-connection`: flujo de conexión self-service de líneas de WhatsApp vía Meta Embedded Signup — intercambio de code por token, creación de línea con `connection_mode`, almacenamiento cifrado del token, suscripción/registro post-conexión, y verificación de firma + manejo de fields de coexistencia en el webhook.

### Modified Capabilities

- `ui-admin`: el scenario "Conectar una línea" cambia — la vía principal deja de ser un drawer con campos técnicos de Meta y pasa a ser el botón de Embedded Signup con estados de progreso/error del flujo; el drawer manual queda como opción secundaria.
- `ui-platform-admin`: el requirement "Separación de ámbitos" ("editar secretos existe solo bajo `/platform/**`") incorpora una excepción explícita y acotada: las credenciales derivadas del flujo de Embedded Signup para líneas del propio tenant las escribe el backend automáticamente desde `/admin/connect/lines` — la frontera de autorización la pone Meta (el token intercambiado solo cubre los assets que el usuario autorizó), no el platform admin. La edición manual de secretos sigue siendo exclusiva de `/platform/**`.

## Impact

- **Backend**: migración a `whatsapp_lines` (columna `connection_mode`); nuevo controller/endpoint bajo `/api/v1/admin/whatsapp-lines` (connect); nuevo servicio de dominio en `Domain/WhatsApp/` para el intercambio OAuth y las llamadas Graph post-conexión; cambios en `WhatsAppWebhookController` (firma) y `MetaWebhookPayloadNormalizer`/`MetaWhatsAppWebhookHandler` (fields de coexistencia); `config/services.php` y `.env.example` (claves Meta).
- **Frontend**: `modules/admin/views/connect/LinesView.vue` (botón de conexión + estados del flujo), carga del Facebook JS SDK, `modules/admin/api.ts`/`types.ts` (endpoint connect, `connection_mode` en `WhatsAppLineRecord`), i18n es-CO/en.
- **Dependencias externas**: Facebook JS SDK (frontend), Graph API `oauth/access_token`, `subscribed_apps`, `register` (backend). Requiere que la app de Meta tenga la configuración de Embedded Signup publicada y los permisos `whatsapp_business_management` + `whatsapp_business_messaging`.
- **Seguridad**: el webhook pasa de abierto a verificado por firma; los tokens nunca transitan por el frontend; el code de OAuth es de un solo uso y se intercambia server-side con el app secret.
- **No cambia**: el sender saliente (`MetaGraphOutboundMessageSender`) sigue resolviendo el token por `provider=whatsapp_meta` / `credential_key=access_token` — el flujo nuevo escribe exactamente ese par; `/platform/credentials` sigue existiendo para operación manual/rotación.
