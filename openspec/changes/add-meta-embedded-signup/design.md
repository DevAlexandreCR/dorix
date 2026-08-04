# Design — add-meta-embedded-signup

## Context

Dorix es Tech Provider de Meta con una app propia (app id + app secret ya disponibles). Hoy:

- El tenant crea la línea manualmente (`POST /v1/admin/whatsapp-lines`) pero el `access_token` solo puede cargarlo un platform admin (`PUT /v1/admin/credentials`, `Permission::ManagePlatform`).
- El webhook (`/api/webhooks/meta/whatsapp`) es una URL única global con verify token de env; el POST no verifica firma.
- La línea se resuelve por `phone_number_id` (`DatabaseWhatsAppLineResolver`); el normalizador solo acepta `field === 'messages'`.
- El sender saliente busca el token en `api_credentials` con `provider=whatsapp_meta`, `credential_key=access_token`, prefiriendo scope de línea sobre scope de tenant (`MetaGraphOutboundMessageSender:201-222`).
- No existe noción de modo de conexión (coexistencia vs Cloud API directa).

Al ser una sola app de Meta para toda la plataforma, la URL de webhook única y el verify token global son la arquitectura correcta — no cambian. Lo que falta es el flujo de autorización por tenant y el discriminador de modo.

## Goals / Non-Goals

**Goals:**

- El tenant conecta una línea con un clic vía Embedded Signup, eligiendo coexistencia o Cloud API directa; el backend recibe el authorization code, lo intercambia por un business token y deja la línea operativa (línea creada, token cifrado, WABA suscrita, número registrado si aplica) sin intervención del platform admin.
- El webhook verifica `X-Hub-Signature-256` con el app secret.
- El pipeline de webhooks tolera los fields de coexistencia (`smb_message_echoes`, `history`) sin errores.

**Non-Goals:**

- Importar el historial de chats de coexistencia (`history` se acepta y se ignora en este cambio) y sincronizar contactos de coexistencia (`smb_app_state_sync` — tercer field que Meta exige suscribir para coexistencia — también se acepta y se ignora vía la ruta genérica de fields desconocidos).
- Refresh/rotación automática de tokens (los business integration system user tokens de Tech Provider no expiran; la rotación manual sigue en `/platform/credentials`).
- Cambios en el sender saliente (templates, media) o en la lógica del agente.
- Desconexión/deautorización desde Meta hacia Dorix (webhook `account_update`) y limpieza en Graph al eliminar una línea (`DELETE /{waba_id}/subscribed_apps` en `destroy()`) — deuda conocida: tras borrar una línea conectada, Meta puede seguir entregando webhooks para ese número, que caerán en `WhatsAppLineNotFoundException` como ocurre hoy con cualquier línea borrada.
- Eliminar el drawer manual de creación de línea (queda como fallback).

## Decisions

### D1 — Flujo Embedded Signup con `response_type=code` y session info v3

El frontend carga el Facebook JS SDK y lanza `FB.login` con `config_id` (configuración de Embedded Signup de la app), `response_type: 'code'`, `override_default_response_type: true` y `extras.sessionInfoVersion: '3'`. Para coexistencia se pasa `extras.featureType: 'whatsapp_business_app_onboarding'`; para Cloud API directa no se pasa `featureType`.

El resultado llega por dos canales que el frontend combina:
- `authResponse.code` del callback de `FB.login` (código de un solo uso, ~30 s de vida).
- Evento `message` de `window` con `data.type === 'WA_EMBEDDED_SIGNUP'` que trae `phone_number_id` y `waba_id` (session info v3). Se valida `event.origin` contra `https://www.facebook.com` / `https://web.facebook.com`, **y `data.event` debe ser un valor terminal del flujo elegido**: `FINISH`/`FINISH_ONLY_WABA` para `cloud_api`, `FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING` para coexistencia. Mensajes del mismo `type` con otros valores de `event` (estados intermedios, `CANCEL`) se ignoran — sin este filtro el frontend podría llamar al backend prematuramente o más de una vez por intento, quemando el code de un solo uso.

El frontend envía `{ code, phone_number_id, waba_id, connection_mode, name? }` al backend. El token **nunca** transita por el frontend.

*Alternativa descartada:* `response_type=token` (token en el navegador) — inseguro y Meta lo desaconseja para server-side apps.

### D2 — Endpoint único de conexión: `POST /v1/admin/whatsapp-lines/connect`

Controller dedicado `AdminWhatsAppLineConnectController` (el contrato request/response difiere materialmente de `store`/`update`/`destroy`, y el codebase favorece controllers delgados), gate `Permission::ManageTenant`, mismo grupo de middleware que el resto de rutas admin (deliberadamente sin `tenant.active`, igual que las rutas de líneas actuales).

Esta escritura de credenciales por un tenant admin es una **excepción explícita y acotada** al requirement "Separación de ámbitos" de `ui-platform-admin` ("editar secretos existe solo bajo `/platform/**`") — ver el delta de spec de este cambio. Justificación: la frontera de autorización la impone Meta (el token intercambiado solo cubre los assets que el usuario autorizó en el popup), el secreto nunca pasa por un formulario ni se muestra en `/admin/**`, y la edición manual sigue siendo exclusiva de plataforma. Orquesta vía un nuevo servicio de dominio `Domain/WhatsApp/EmbeddedSignupConnector`:

1. **Exchange**: `GET {base_url}/{api_version}/oauth/access_token?client_id={app_id}&client_secret={app_secret}&code={code}` → business token.
2. **Subscribe**: `POST /{waba_id}/subscribed_apps` con el token.
3. **Register** (solo `cloud_api`): `POST /{phone_number_id}/register` con `messaging_product=whatsapp` y un `pin` de 6 dígitos generado aleatoriamente, guardado como credencial de línea `credential_key=registration_pin` (necesario para re-registros futuros). En coexistencia el número ya está registrado en la app de WhatsApp Business — no se llama `register`.
4. **Persistencia** (transacción DB, después de que las llamadas Graph tuvieron éxito): crear/actualizar `WhatsAppLine` (`connection_mode`, `status=active`, `is_enabled=true`) y upsert de `ApiCredential` line-scoped con `provider=whatsapp_meta`, `credential_key=access_token` — exactamente el par que `MetaGraphOutboundMessageSender` ya resuelve. Ante dos connects concurrentes del mismo número, la persistencia se apoya en el unique de `phone_number_id`: el conflicto de inserción se captura y se reintenta como actualización dentro de la transacción, de modo que la carrera degrada a un resultado consistente, nunca a estado parcial.

Orden Graph-antes-de-DB: si la DB falla tras las llamadas Graph, reintentar el flujo es seguro (`subscribed_apps` y `register` son idempotentes); lo inverso dejaría líneas "conectadas" sin token.

**Idempotencia/conflictos sobre `phone_number_id` (unique global):**
- Ya existe en el mismo tenant → reconexión: se actualiza la línea y se rota el token (caso "reconectar").
- Existe en otro tenant → `409 Conflict` sin filtrar a qué tenant pertenece.

Errores de Graph (code expirado/reusado, permisos faltantes) → `422` con mensaje traducible; nada se persiste.

*Alternativa descartada:* reutilizar `POST /whatsapp-lines` con campos extra — mezcla dos contratos (creación manual vs conexión OAuth) y complica la validación.

### D3 — `connection_mode` como columna string + enum PHP

Migración: `ALTER TABLE whatsapp_lines ADD connection_mode string(20) NOT NULL DEFAULT 'cloud_api'`. Enum backed `App\Enums\WhatsAppConnectionMode { CloudApi = 'cloud_api'; Coexistence = 'coexistence' }` con cast en el modelo. Default `cloud_api` deja las líneas existentes coherentes (todas operan hoy contra Cloud API). Validación con `Rule::enum` en el request de connect. Se expone en el serializer de `AdminPanelDataBuilder` y en `WhatsAppLineRecord` del frontend (badge informativo en la tabla/drawer, siguiendo la regla de spec "estados nunca como texto libre").

### D4 — Credenciales de app de plataforma en config/env, no en DB

`META_APP_ID`, `META_APP_SECRET`, `META_ES_CONFIG_ID` → `config/services.php` bajo `services.whatsapp.meta.{app_id, app_secret, embedded_signup_config_id}`, documentadas en `backend/.env.example` junto con las `WHATSAPP_META_*` ya existentes (que hoy faltan ahí). Son credenciales de la plataforma (una sola app para todos los tenants), no datos por tenant — no van en `api_credentials`.

El frontend necesita `app_id` y `config_id` (valores públicos, van en el JS del navegador de todos modos): se exponen como `VITE_META_APP_ID` y `VITE_META_ES_CONFIG_ID` en `frontend/.env`. *Alternativa descartada:* endpoint de bootstrap para servirlos — añade una llamada para dos constantes públicas; si algún día se necesita rotación sin rebuild, se migra entonces.

### D5 — Verificación de firma del webhook

En `WhatsAppWebhookController@handle` (POST), antes de procesar: calcular `hash_hmac('sha256', $request->getContent(), app_secret)` y comparar con `hash_equals` contra el header `X-Hub-Signature-256` (formato `sha256=<hex>`). Firma ausente o inválida → `403`. Si `META_APP_SECRET` no está configurado → `403` con log de warning (fail-closed: este cambio hace obligatoria la variable). El verify GET también pasa a `hash_equals` para el verify token (hoy usa `!==`).

*Alternativa considerada:* middleware dedicado — válido, pero con un solo webhook el controller es suficiente; extraer a middleware si aparece un segundo webhook.

### D6 — Coexistencia en el pipeline de webhooks

`MetaWebhookPayloadNormalizer` hoy exige `field === 'messages'` y lanza para el resto. Cambios:

- `smb_message_echoes`: se normaliza como mensaje **saliente** enviado por el humano desde la app de WhatsApp Business. `MetaWhatsAppWebhookHandler` lo persiste en la conversación (dirección outbound, fuente `business_app`) **sin** disparar el pipeline del agente ni `ProcessIncomingMessageJob`. `business_app` es un valor de `payload.source` en `ConversationMessage`, siguiendo la convención existente (`meta_webhook`, `agent_runtime`, `outbound_sender`) — **no** un caso nuevo del enum `ConversationSource`, que es de nivel conversación. No altera el estado de la conversación: en coexistencia el agente y el humano conviven por diseño, y el humano ya tiene el handoff explícito si quiere silenciar al agente.
- `history`, `smb_app_state_sync` y cualquier field no reconocido: se responde `200` y se ignora con log informativo (Meta reintenta ante non-2xx; fields desconocidos no deben generar reintentos infinitos).
- `messages` no cambia: los mensajes entrantes de clientes en coexistencia llegan por el mismo field y siguen el pipeline existente.

### D7 — UI del flujo de conexión

En `/admin/connect/lines`, el botón primario pasa a ser "Conectar con WhatsApp" (lanza Embedded Signup); el drawer manual actual queda accesible como acción secundaria ("Conexión manual") sin cambios de contrato. El flujo muestra estados: eligiendo modo (coexistencia vs API directa, con explicación de cada uno) → popup de Meta abierto → conectando (llamada al backend) → éxito (toast "Línea conectada", fila nueva en la tabla) / error (mensaje accionable; el popup cerrado por el usuario no es error, solo cancela). El SDK de Facebook se carga on-demand al entrar a la vista (no en el bundle global).

## Risks / Trade-offs

- **[Embedded Signup requiere dominio HTTPS allowlisted en la app de Meta]** → en dev (`http://localhost:5173`) el popup no funcionará; mitigación: mantener el drawer manual como fallback y documentar que el flujo ES se prueba en staging con dominio permitido. Los tests de backend cubren el endpoint connect con Graph mockeado (`Http::fake`).
- **[El code de OAuth expira en ~30 s y es de un solo uso]** → el frontend envía el code al backend inmediatamente al recibirlo; reintento = repetir el signup. El error de Graph se traduce a un mensaje claro ("la autorización expiró, intenta de nuevo").
- **[Session info (`phone_number_id`/`waba_id`) viene del frontend y podría manipularse]** → el riesgo real es bajo: el token intercambiado solo tiene permisos sobre los assets que el usuario autorizó; una `phone_number_id` ajena haría fallar `register`/envíos. Mitigación adicional: la suscripción y el registro se hacen con el token recibido, de modo que ids ajenos fallan en Graph y se aborta antes de persistir. Deuda registrada: validar contra `GET /{waba_id}/phone_numbers` en un cambio futuro.
- **[Activar verificación de firma puede romper un deploy existente sin `META_APP_SECRET`]** → la variable se documenta en `.env.example` y el checklist de despliegue del cambio la marca como requisito previo; fail-closed es preferible a un webhook abierto.
- **[Echoes de coexistencia con volumen alto]** → se persisten como mensajes sin trabajo de agente; costo marginal. Si un tenant coexistente satura, `is_enabled` de la línea sigue disponible.
- **[Meta puede cambiar el shape del evento `WA_EMBEDDED_SIGNUP`]** → se fija `sessionInfoVersion: '3'` explícitamente y se valida el shape en el frontend antes de llamar al backend.

## Migration Plan

1. Deploy backend: migración (`connection_mode`), config nuevo, endpoint connect, firma de webhook. **Requisito previo:** `META_APP_ID`, `META_APP_SECRET`, `META_ES_CONFIG_ID`, `WHATSAPP_WEBHOOK_VERIFY_TOKEN` presentes en el entorno (la firma es fail-closed).
2. Deploy frontend con `VITE_META_APP_ID` / `VITE_META_ES_CONFIG_ID`.
3. Líneas existentes: quedan `connection_mode=cloud_api` por default de migración; sus credenciales tenant-scoped siguen resolviéndose por el fallback existente del sender.
4. Rollback: revertir deploys; la columna nueva es aditiva e inocua; los tokens creados por ES siguen siendo válidos y gestionables desde `/platform/credentials`.

## Open Questions

- ¿La app de Meta ya tiene **dos** configuraciones de Embedded Signup (una con `featureType` de coexistencia y otra estándar) o una sola config sirve para ambos flujos pasando `featureType` en `extras`? Se asume lo segundo (una config + `extras.featureType`). **Resolver contra el App Dashboard de Meta antes de la task 5.1.** Si Meta exige configs separadas, el impacto no es solo env: `VITE_META_ES_CONFIG_ID` se desdobla en dos variables y el helper del frontend mapea modo → config id — la task 5.1 incluye esta rama como fallback previsto.
- Nombre de la línea: el flujo ES no lo pide a Meta; se usa `display_phone_number` (obtenible tras conectar vía `GET /{phone_number_id}` con `fields=display_phone_number,verified_name`) o `verified_name` como nombre inicial, editable después en el drawer. Decisión tomada en specs: usar `verified_name` con fallback al número.
