# Tasks — add-meta-embedded-signup

## 1. Configuración y esquema

- [ ] 1.1 Agregar `app_id`, `app_secret`, `embedded_signup_config_id` a `config/services.php` bajo `services.whatsapp.meta.*` y documentar `META_APP_ID`, `META_APP_SECRET`, `META_ES_CONFIG_ID`, `WHATSAPP_META_BASE_URL`, `WHATSAPP_META_API_VERSION`, `WHATSAPP_WEBHOOK_VERIFY_TOKEN` en `backend/.env.example` con comentarios de origen
- [ ] 1.2 Crear migración que agrega `connection_mode` (string 20, NOT NULL, default `cloud_api`) a `whatsapp_lines`; crear enum `App\Enums\WhatsAppConnectionMode` (`cloud_api`, `coexistence`) y castearlo en el modelo `WhatsAppLine`
- [ ] 1.3 Exponer `connection_mode` en la serialización de línea de `AdminPanelDataBuilder` y verificar que las líneas existentes quedan `cloud_api` tras migrar (test de migración/serialización)

## 2. Seguridad del webhook

- [ ] 2.1 Implementar verificación de `X-Hub-Signature-256` en `WhatsAppWebhookController@handle` (HMAC-SHA256 del cuerpo crudo + `hash_equals`, fail-closed 403 si falta firma o app secret) y cambiar la comparación del verify token del GET a `hash_equals`; tests: firma válida procesa, firma inválida/ausente 403, secret ausente 403 con warning; actualizar los tests existentes de `WhatsAppGatewayTest` para firmar sus payloads

## 3. Conector de Embedded Signup (backend)

- [ ] 3.1 Crear `Domain/WhatsApp/EmbeddedSignupConnector` con el intercambio de code (`GET oauth/access_token`), manejo de errores de Graph (excepción de dominio con mensaje traducible) y tests unitarios con `Http::fake`
- [ ] 3.2 Agregar al conector el aprovisionamiento post-exchange: `POST /{waba_id}/subscribed_apps`, `POST /{phone_number_id}/register` con PIN aleatorio de 6 dígitos solo para `cloud_api`, y `GET /{phone_number_id}?fields=verified_name,display_phone_number` para el nombre inicial; tests de cada rama incluyendo fallas parciales (nada se persiste si Graph falla)
- [ ] 3.3 Implementar la persistencia transaccional: crear/actualizar `WhatsAppLine` (modo, status active, is_enabled true, nombre con fallback) y upsert de `ApiCredential` line-scoped `whatsapp_meta`/`access_token` + `registration_pin`; reconexión del mismo tenant rota el token en la misma fila
- [ ] 3.4 Crear `POST /api/v1/admin/whatsapp-lines/connect` en un controller dedicado `AdminWhatsAppLineConnectController` (ruta en `routes/api.php`, FormRequest con `Rule::enum` para `connection_mode`, gate `Permission::ManageTenant`) orquestando el conector; casos: 201 cloud_api, 201 coexistence sin register, 422 code inválido, 409 número de otro tenant sin fuga de información, 403 operator/viewer; auditar con `AuditEventRecorder` (`whatsapp_line_connected`)

## 4. Coexistencia en el pipeline de webhooks

- [ ] 4.1 Extender `MetaWebhookPayloadNormalizer` y `MetaWhatsAppWebhookHandler`: `smb_message_echoes` se persiste como mensaje saliente con `payload.source = business_app` (convención existente de `ConversationMessage`, no un caso nuevo de `ConversationSource`) sin encolar `ProcessIncomingMessageJob` ni cambiar estado de conversación; `history`, `smb_app_state_sync` y fields desconocidos responden 200 con log informativo; tests de los escenarios (echo, history/app_state_sync, messages intacto)

## 5. Frontend — flujo de conexión

- [ ] 5.1 Verificar en el App Dashboard de Meta si una sola config de Embedded Signup sirve para ambos modos o se necesitan dos (ver Open Questions del design); agregar `VITE_META_APP_ID` / `VITE_META_ES_CONFIG_ID` a `frontend/.env` (y ejemplo — desdoblar en dos config ids si Meta lo exige, con mapeo modo → config id en el helper), crear composable/helper que carga el Facebook JS SDK on-demand y lanza `FB.login` con `config_id`, `response_type: 'code'`, `override_default_response_type: true`, `extras.sessionInfoVersion: '3'` y `extras.featureType: 'whatsapp_business_app_onboarding'` solo en coexistencia; escuchar el evento `WA_EMBEDDED_SIGNUP` validando `event.origin` y actuando solo sobre `data.event` terminal del modo elegido (`FINISH`/`FINISH_ONLY_WABA` para cloud_api, `FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING` para coexistencia; estados intermedios y `CANCEL` se ignoran)
- [ ] 5.2 Agregar `connectWhatsAppLine` a `modules/admin/api.ts` y `connection_mode` a `WhatsAppLineRecord` en `types.ts` (+ tipos del payload connect); integrar en `useAdminResource`
- [ ] 5.3 Rediseñar la cabecera de `LinesView.vue`: botón primario "Conectar con WhatsApp" con selector de modo (coexistencia vs API directa con explicación), estados conectando/éxito/error/cancelado según spec `ui-admin`; drawer manual como acción secundaria "Conexión manual"; badge de solo lectura del modo en tabla y drawer
- [ ] 5.4 Agregar strings i18n (es-CO primario + en fallback) del flujo completo y corregir el hint de `credential_key` en la vista de credenciales de plataforma (`wa_token` → `access_token`); correr `npm run typecheck`

## 6. Validación final

- [ ] 6.1 Correr suite completa backend (`php artisan test`) y typecheck/build frontend; verificar checklist mínimo del repo (stack levanta, `/api/health` responde, frontend carga) y revisar `openspec status` del change
