# Proposal: add-catalog-and-scheduling

## Why

Los tenants de servicios (caso piloto: sala de estética) necesitan que el
agente cotice y agende citas reales. Hoy el "catálogo" solo existe como
chunks de Excel indexados (`data_source_chunks`) que sirven para responder
preguntas, pero no aportan hechos estructurados confiables (duración,
política de valoración, tipo de precio) para alimentar lógica de
agendamiento, y no existe ninguna integración de calendario.

## What Changes

- Nuevo modelo de dominio `CatalogItem` (tenant-scoped): catálogo canónico
  de servicios y productos por tenant, con `kind` (`service`/`product`),
  tipos de precio (`fixed`/`from`/`range`), `duration_minutes` y FK
  auto-referencial `assessment_item_id` (un procedimiento que requiere
  valoración apunta al ítem de valoración; la valoración es un ítem normal
  con su propio precio y duración).
- API admin CRUD para el catálogo + nueva vista de catálogo en el panel
  admin del frontend.
- Conexión OAuth con Google Calendar por línea de WhatsApp: flujo de
  consentimiento, almacenamiento de refresh token en `api_credentials`
  (provider `google_calendar`), refresco automático de access tokens y
  señal de "conexión rota / reconectar" en el panel admin.
- Tres tools nuevas para el agente: `get_service_details` (detalle de un
  ítem del catálogo), `check_availability` (freebusy del calendario) y
  `create_appointment` (crea el evento). La duración y la política de
  valoración se imponen desde la BD, nunca desde la memoria del LLM: un
  ítem con `assessment_item_id` no es agendable directo — el agente ofrece
  agendar la valoración vinculada.
- `PromptBuilder` inyecta un índice compacto del catálogo activo (nombre,
  precio, duración, requisito de valoración) para que el agente conozca la
  oferta sin llamar tools.
- Configuración de agendamiento por línea (calendario destino, horario de
  atención, timezone) en `tenant_tool_configs.overrides`.

Fuera de alcance (explícito): pagos/abonos, catálogo de especialistas
(un solo calendario por línea), multi-sede, sincronización del catálogo
desde software de inventarios externo (el modelo `CatalogItem` queda como
destino canónico para conectores futuros).

## Capabilities

### New Capabilities

- `service-catalog`: modelo `CatalogItem` tenant-scoped (servicios y
  productos, precios, duración, valoración vinculada), API admin CRUD y
  reglas de negocio de agendabilidad.
- `google-calendar-connection`: flujo OAuth por línea, persistencia de
  tokens en `api_credentials`, refresco y manejo de revocación.
- `agent-scheduling`: tools `get_service_details`, `check_availability` y
  `create_appointment`, inyección del índice de catálogo en el prompt y
  flujo conversacional de valoración previa.

### Modified Capabilities

- `ui-admin`: nueva sección de catálogo (CRUD) y superficie de conexión
  de Google Calendar por línea (estado conectado/roto, acción de
  conectar/reconectar).

## Impact

- **Backend**: nueva migración (`catalog_items`), nuevo subdominio
  `Domain/Catalog`, nuevo subdominio `Domain/Connectors/GoogleCalendar`
  (cliente HTTP, OAuth, refresco de tokens), tools nuevas en
  `Domain/Tools/Tools` registradas en `AppServiceProvider` (tag
  `agent-tools`), intent `service_scheduling` en `AgentPackRegistry`
  (sin él, `AgentDecisionPolicy` fuerza handoff ante cualquier tool
  nueva), cambios en `PromptBuilder`, `AdminPanelDataBuilder`
  (catálogo + estado de conexión) y rutas nuevas en `routes/api.php`
  (CRUD de catálogo, OAuth redirect/callback).
- **Frontend**: nueva vista de catálogo bajo `modules/admin`
  (`api.ts`/`types.ts`/vista), copy de tools nuevas en `toolCopyKeys` de
  `ToolsView.vue` + i18n (es_CO/en), conexión de calendario como
  estado+acción por línea en `LinesView` (no en `CredentialsView`, que es
  solo lectura).
- **Dependencias externas**: proyecto en Google Cloud con OAuth client;
  los scopes de Calendar son "sensitive" — en modo testing los refresh
  tokens expiran a los 7 días, producción requiere verificación de Google
  (trámite de semanas, iniciar en paralelo).
- **Credenciales**: primer uso real de `api_credentials` para un provider
  distinto de `whatsapp_meta`; los selects hardcodeados de provider en
  `CredentialsView.vue` (admin y platform) deben reconocer
  `google_calendar`.
