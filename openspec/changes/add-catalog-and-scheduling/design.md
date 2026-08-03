# Design: add-catalog-and-scheduling

## Context

El pipeline de tools ya está completo (`ToolRegistry` → `ToolExecutionRunner`
→ auditoría en `tool_executions`), y `CreateLeadTool` demuestra que las tools
pueden escribir. `tenant_tool_configs` ya soporta scope por tenant/línea con
`overrides` y `bindings` JSON; `api_credentials` ya guarda secretos
encriptados con `provider` y scope por línea. Lo que no existe: un catálogo
estructurado (hoy solo chunks de Excel), ningún flujo OAuth, y ninguna
integración de escritura hacia servicios externos.

Caso piloto: sala de estética con procedimientos de belleza. Algunos
servicios tienen precio fijo y se agendan directo; otros (ácido hialurónico,
plasma) exigen una valoración previa y su precio solo se conoce en esa
valoración.

## Goals / Non-Goals

**Goals:**

- Catálogo canónico por tenant que distinga servicios y productos, y del
  cual el código (no el LLM) derive duración, precio y política de
  valoración.
- Agendamiento real en Google Calendar por línea de WhatsApp, con flujo
  conversacional de valoración previa cuando aplica.
- Degradación explícita cuando la conexión de calendario falla o es
  revocada (handoff + señal en admin), siguiendo el patrón de
  `SearchInventoryTool` con `DataSourceUnavailableException`.

**Non-Goals:**

- Pagos, abonos o anticipos para reservar.
- Especialistas/staff por servicio (un solo calendario por línea) y
  multi-sede.
- Sincronización del catálogo desde sistemas externos (Alegra, Siigo…);
  `CatalogItem` queda como destino canónico para esos conectores futuros.
- Recordatorios de cita, reagendamiento o cancelación por chat (v2).

## Decisions

### D1. Catálogo como entidad de dominio, no como data source

`catalog_items` es una tabla tenant-scoped, no chunks indexados. Regla que
separa las aguas: si el código toma decisiones con el dato (duración,
agendabilidad, tipo de precio) → columna estructurada; si solo el LLM lo
lee para conversar (descripción, cuidados) → texto libre/`metadata`.
Alternativa descartada: modelar el catálogo como `DataSource` tipo nuevo —
su contrato (`DataSourceReader`) es de solo lectura sobre texto y no puede
garantizar hechos para agendar.

### D2. Una sola tabla con `kind`, no tablas separadas

`kind: service | product` por ítem, no por tenant: el mismo negocio puede
ofrecer procedimientos y vender productos. Comparten ~90% de columnas;
`duration_minutes` y `assessment_item_id` simplemente quedan null en
productos, que nunca son agendables.

### D3. Valoración como ítem normal + FK auto-referencial

En lugar de un enum `booking_mode` y campos `assessment_*` duplicados:
`assessment_item_id` (nullable, FK a `catalog_items`). La valoración es un
ítem de pleno derecho con su propio precio ($0 si es gratis), duración y
descripción, y puede compartirse entre varios procedimientos. Reglas:

- `assessment_item_id != null` → NO agendable directo; el agente ofrece
  agendar el ítem vinculado.
- `assessment_item_id != null && price_amount == null` → "precio según
  valoración".

Esto elimina el price_type `on_assessment` y el enum de booking. Guard de
integridad: un ítem de valoración no puede a su vez tener
`assessment_item_id` (sin cadenas), y el FK debe apuntar a un ítem del
mismo tenant.

### D4. Esquema de `catalog_items`

```
catalog_items
  id, tenant_id (FK, cascade)
  kind        enum-string: service | product
  name, category (nullable), description (text, nullable)
  price_type  enum-string: fixed | from | range
  price_amount / price_min / price_max  (decimal, nullables)
  currency    default 'COP'
  duration_minutes      (nullable; requerido si es agendable)
  assessment_item_id    (nullable, FK self, nullOnDelete)
  active      boolean default true
  metadata    json nullable
  timestamps
```

### D5. Google Calendar por línea, tokens en `api_credentials`

Provider `google_calendar`, una credencial por línea (`whatsapp_line_id`
set, `scope_type: line`). El secret encriptado guarda el refresh token; los
access tokens se refrescan on-demand (cache corto) y nunca se persisten.
`last_used_at` ya existe para diagnóstico. Config de agendamiento
(calendario destino, horario de atención por día, timezone
`America/Bogota` default) vive en `tenant_tool_configs.overrides` de las
tools de agendamiento — sin tabla nueva.

### D6. Flujo OAuth

Rutas tenant-scoped bajo `/v1/admin`: `POST …/calendar-connections/{line}`
devuelve la URL de consentimiento (state firmado con tenant/línea + CSRF);
`GET /api/oauth/google/callback` (pública, como los webhooks) valida state,
intercambia el code y persiste el refresh token. Scopes mínimos:
`calendar.events` + `calendar.freebusy`. Cliente HTTP propio con `Http::`
de Laravel — sin SDK de Google ni Socialite (dependencia pesada para dos
endpoints REST).

**Permiso**: el endpoint de conexión se gatea con
`Permission::ManageAgentConfig` (lo tiene `tenant_admin`), NO con
`ManagePlatform` como el upsert manual de credenciales
(`AdminCredentialController`). Rationale del doble estándar: en el flujo
OAuth el usuario nunca ve ni digita un secreto — Google emite el token
directo al callback del servidor, scoped al provider `google_calendar` de
su propia línea — mientras que el formulario manual de platform permite
escribir secretos arbitrarios de cualquier provider (incluido
`whatsapp_meta`). El callback público solo persiste si el `state` firmado
es íntegro y vigente. La config de agendamiento asociada ya vive bajo el
mismo permiso (`tenant_tool_configs`).

**Grupo de middleware**: tanto las rutas de catálogo como las de
calendar-connections van en el grupo de configuración
(`web, auth:sanctum, tenant.context` — SIN `tenant.active`), igual que el
resto del admin: un tenant pausado debe poder gestionar su catálogo y
reconectar su calendario.

### D7. Contrato del conector y fake para tests/sandbox

Interfaz `GoogleCalendarClient` (`freeBusy()`, `createEvent()`,
`refreshToken()`) en `Domain/Connectors/GoogleCalendar`, con
implementación HTTP real y `FakeGoogleCalendarClient` bindeado en tests y
en el sandbox — mismo patrón que `LlmProviderInterface`. Ningún test ni
conversación de sandbox toca la API real.

### D8. Intent `service_scheduling` en el AgentPack

`AgentDecisionPolicy` solo permite `call_tool` si un intent del
`AgentPack` activo autoriza esa tool (de lo contrario fuerza handoff), y
`sales_support_v1` no autoriza ninguna tool nueva. Se agrega el intent
`service_scheduling` al pack existente `sales_support_v1` (no un pack
nuevo — hoy solo existe uno y la selección de pack ya funciona) con
`allowedTools: [get_service_details, check_availability,
create_appointment]` y los outcomes correspondientes, y se extiende
`PromptBuilder::developerInstructions()` con la guía por intent análoga a
la de inventory/knowledge lookup (cuándo consultar detalle, cuándo pedir
disponibilidad, cuándo agendar).

Limitación aceptada: `AgentDecisionPolicy` prohíbe llamar una segunda
tool en el mismo ciclo cuando ya hay `retrievedContext` — un mensaje no
puede encadenar `get_service_details` → `check_availability`; el flujo se
completa en mensajes sucesivos de la conversación, lo cual es natural en
WhatsApp. No es un bug: los tests no deben asumir encadenamiento en un
solo turno.

### D9. Tools y dónde vive la verdad

- `get_service_details(item)` — detalle completo de un ítem (descripción,
  cuidados, precio, política de valoración).
- `check_availability(item, date)` — el servidor computa y devuelve slots
  candidatos concretos (freebusy ∩ horario de atención ∩ duración del
  ítem). El LLM elige entre slots dados, nunca calcula horarios — los LLM
  hacen mal aritmética de calendarios.
- `create_appointment(item, slot, customer_name)` — re-valida
  disponibilidad justo antes de `events.insert` y rechaza ítems con
  `assessment_item_id` (retorna la instrucción de ofrecer la valoración).

La duración del evento sale SIEMPRE de `catalog_items.duration_minutes`;
los argumentos del LLM solo eligen ítem y slot. Resolución de ítem por id
expuesto en el índice del prompt, no por nombre libre.

### D10. Índice de catálogo en el prompt

`AgentContextLoader` carga los ítems activos y `PromptBuilder` inyecta un
índice compacto (id, nombre, precio legible, duración, "requiere
valoración → ítem X"). Catálogos objetivo: 15–40 ítems — cabe en el
prompt sin RAG. Si un tenant no tiene catálogo, la sección se omite y las
tools de agendamiento no se habilitan.

### D11. UI: catálogo como tabla+drawer, conexión de calendario en Lines

La vista de catálogo sigue el patrón existente "Entidades en tablas con
drawer" (`DataTable` + `UiDrawer`), NO resumen-primero — es una colección,
como miembros/líneas/credenciales. Nueva sub-ruta
`/admin/assistant/catalog` con su entrada en `ADMIN_ROUTE_REQUIRES`
(permiso de agent config).

La superficie de conexión de calendario NO va en `CredentialsView` (esa
vista tiene el invariante documentado de nunca mutar credenciales): vive
como estado+acción por línea en `LinesView` (la conexión es por línea) —
badge `connected|broken|none` y botón conectar/reconectar en el drawer de
la línea. Nota: la ruta de `LinesView` se gatea con `canManageTenant`
pero el endpoint de conexión usa `ManageAgentConfig`; hoy solo
`tenant_admin` tiene ambos, pero el botón conectar/reconectar lleva su
propio check `canManageAgentConfig` a nivel de componente para que no se
rompa en silencio si la matriz de roles se separa en el futuro.

### D12. Degradación de conexión

Token revocado/refresh fallido lanza
`CalendarConnectionUnavailableException` → la tool retorna
`ToolNextAction::RequestHandoff` con razón clara y registra evento de
agente; `AdminPanelDataBuilder` expone el estado de conexión
(`connected | broken | none`) por línea para que la UI muestre
"reconectar".

## Risks / Trade-offs

- [Verificación de Google] Los scopes de Calendar son sensitive: en modo
  testing los refresh tokens expiran a los 7 días → inútil en producción.
  Mitigación: iniciar el trámite de verificación del OAuth client en
  paralelo al desarrollo; documentarlo como bloqueante de release, no de
  desarrollo.
- [Doble reserva] Entre `check_availability` y `create_appointment` otra
  conversación puede tomar el slot. Mitigación: re-validación server-side
  inmediatamente antes del insert; la ventana residual de milisegundos se
  acepta (sin locking distribuido en v1).
- [Timezone] Todo el cómputo de slots en el timezone de la línea
  (`America/Bogota` default), conversiones solo en el borde con la API de
  Google (RFC3339 con offset). Tests fijan timezone explícito.
- [LLM elige mal el ítem] Mitigación: ids en el índice del prompt +
  `get_service_details` para confirmar antes de agendar; `create_appointment`
  rechaza ítems no agendables por regla de BD.
- [Catálogo crece más allá del prompt] 15–40 ítems caben; si un tenant
  carga cientos, el índice se trunca por categoría y se apoya en
  `get_service_details`. Señalado como límite conocido de v1.

## Open Questions

(Resueltas — quedan como decisiones:)

- Valoraciones con `price_amount = 0` se comunican con copy "gratis"
  (i18n), nunca "$0".
- Evento de Calendar: título "«Servicio» — «Nombre cliente»", descripción
  con teléfono del cliente y nota de origen (Dorix). Configurable después
  si algún comercio lo pide.
