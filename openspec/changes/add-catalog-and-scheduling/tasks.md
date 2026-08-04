# Tasks: add-catalog-and-scheduling

## 1. Catálogo — modelo y dominio

- [x] 1.1 Migración `catalog_items` (esquema de design.md D4: kind,
  precios, duration_minutes, assessment_item_id FK self nullOnDelete,
  active, metadata) + modelo `CatalogItem` extendiendo `TenantScopedModel`
  con casts y relación `assessmentItem`.
- [x] 1.2 Reglas de negocio en `Domain/Catalog`: agendabilidad
  (`isBookable`) y validación de vínculo de valoración (mismo tenant, sin
  cadenas). Tests unitarios.
- [x] 1.3 Presentación de precio en `Domain/Catalog`: factory de
  representación legible por `price_type`, "precio según valoración"
  cuando aplica y copy "gratis" para precio 0 (i18n). Tests unitarios.

## 2. Catálogo — API admin

- [x] 2.1 `AdminCatalogItemController` + FormRequest con validación de
  coherencia de precio y duración; rutas CRUD tenant-scoped en
  `routes/api.php` bajo `/v1/admin`, gateadas por
  `Permission::ManageAgentConfig` y en el grupo de configuración (SIN
  `tenant.active` — un tenant pausado gestiona su catálogo). Feature
  tests: CRUD feliz, validaciones 422, aislamiento de tenant, 403 para
  viewer.
- [x] 2.2 Exponer catálogo en `AdminPanelDataBuilder` (lista para la vista
  admin) y mensajes i18n `en`/`es_CO` de validación.

## 3. Google Calendar — conector

- [x] 3.1 Interfaz `GoogleCalendarClient` (`freeBusy`, `createEvent`,
  `refreshAccessToken`) en `Domain/Connectors/GoogleCalendar` +
  `FakeGoogleCalendarClient` bindeada en tests/sandbox +
  `CalendarConnectionUnavailableException`. Config `services.google`
  (client id/secret, redirect).
- [x] 3.2 Implementación HTTP real con `Http::` de Laravel: refresco de
  access token on-demand con cache corto (sin persistir access tokens),
  actualización de `last_used_at`, mapeo de `invalid_grant` a conexión
  rota. Tests con `Http::fake`.
- [x] 3.3 Flujo OAuth: endpoint admin (gate
  `Permission::ManageAgentConfig`, grupo de configuración sin
  `tenant.active` — ver design D6) que genera URL de consentimiento con
  `state` firmado (tenant+línea+expiración), callback público
  `GET /api/oauth/google/callback` que valida state, intercambia code y
  hace upsert de la credencial `google_calendar` de la línea; redirección
  al panel con resultado. Feature tests: éxito, state inválido/expirado,
  403 para viewer.
- [x] 3.4 Estado de conexión por línea (`connected|broken|none`) expuesto
  en `AdminPanelDataBuilder`; marcado de `broken` al detectar
  `invalid_grant`.

## 4. Tools de agendamiento

- [x] 4.0 Intent `service_scheduling` en `AgentPackRegistry`
  (`sales_support_v1`): `allowedTools` con las tres tools nuevas y
  outcomes del flujo; guía por intent en
  `PromptBuilder::developerInstructions()` análoga a inventory/knowledge
  lookup. Tests de `AgentDecisionPolicy`: permite `call_tool` de
  agendamiento con este intent y mantiene la regla de un solo tool call
  por ciclo con `retrievedContext`.
- [x] 4.1 Tool `get_service_details`: resolución por id tenant-scoped,
  retorno de detalle con política de valoración, registro de eventos.
  Registro en `AppServiceProvider` (tag `agent-tools`). Tests.
- [x] 4.2 Cómputo de slots server-side (`Domain/Scheduling` o similar):
  freebusy ∩ horario de atención (desde `overrides` de tool config, con
  fallback línea→tenant) ∩ duración del ítem, todo en el timezone de la
  línea. Tests unitarios exhaustivos de bordes (bloques parciales, día
  cerrado, timezone).
- [x] 4.3 Tool `check_availability`: usa el cómputo de slots; para ítems
  con valoración usa la duración del ítem vinculado y lo señala; handoff
  cuando no hay conexión. Tests.
- [x] 4.4 Tool `create_appointment`: re-validación del slot antes de
  `events.insert`, duración siempre desde BD, rechazo de ítems con
  `assessment_item_id` (con instrucción de ofrecer la valoración),
  registro en `tool_executions` + agent events, handoff ante conexión
  rota. Tests: éxito, slot tomado, rechazo por valoración, sin conexión.
- [x] 4.5 Índice de catálogo en `PromptBuilder` vía `AgentContextLoader`
  (ids, precio legible, duración, requisito de valoración; omitido sin
  catálogo). Test de integración del prompt.

## 5. Frontend admin

- [x] 5.1 Módulo de catálogo: tipos + llamadas API en
  `modules/admin/api.ts`/`types.ts`, vista de catálogo con patrón
  `DataTable` + `UiDrawer` (NO resumen-primero) en sub-ruta
  `/admin/assistant/catalog` con entrada en `ADMIN_ROUTE_REQUIRES`,
  drawer adaptativo por `kind`/`price_type`, selector de valoración sin
  cadenas, i18n `es_CO`/`en`.
- [x] 5.2 Conexión de calendario en `LinesView` (NO en CredentialsView,
  que es solo lectura): badge de estado por línea
  (`connected|broken|none`), acción conectar/reconectar en el drawer de
  la línea que abre la URL de consentimiento, tono de alerta para
  `broken`, i18n.
- [x] 5.3 Copy de tools nuevas en `toolCopyKeys` (ToolsView) + i18n, y
  etiqueta `google_calendar` en los selects/labels de provider de
  credenciales (admin y platform CredentialsView).
- [x] 5.4 `npm run typecheck` y build del frontend en verde.

## 6. Validación final

- [x] 6.1 Suite backend completa (`php artisan test`) en verde; revisar
  que el sandbox use `FakeGoogleCalendarClient` y que una conversación de
  sandbox pueda agendar de punta a punta contra el fake.
- [ ] 6.2 Checklist mínimo del repo: `docker compose up --build`,
  `/api/health` responde, frontend carga; documentar en el README/env de
  ejemplo las variables `services.google` y el requisito de verificación
  del OAuth client de Google (bloqueante de release, no de desarrollo).
