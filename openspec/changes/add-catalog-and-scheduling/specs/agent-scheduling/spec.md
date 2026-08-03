# agent-scheduling Specification (delta)

## ADDED Requirements

### Requirement: Intent service_scheduling en el AgentPack
El pack `sales_support_v1` SHALL incluir el intent `service_scheduling`
con `allowedTools: [get_service_details, check_availability,
create_appointment]` y los outcomes necesarios para el flujo de
agendamiento, y `PromptBuilder::developerInstructions()` SHALL incluir la
guía por intent para estas tools, análoga a la de inventory/knowledge
lookup. Sin este intent, `AgentDecisionPolicy` fuerza handoff ante
cualquier `call_tool` de agendamiento.

#### Scenario: Decisión de agendamiento autorizada
- **WHEN** el LLM decide `call_tool: check_availability` con intent
  `service_scheduling`
- **THEN** `AgentDecisionPolicy` permite la decisión y la tool se ejecuta

#### Scenario: Un solo tool call por ciclo con contexto recuperado
- **WHEN** en el mismo ciclo de procesamiento ya hay `retrievedContext`
  (p. ej. tras `get_service_details`)
- **THEN** la policy no permite un segundo `call_tool`; el flujo continúa
  en el siguiente mensaje de la conversación (limitación aceptada, no un
  bug)

### Requirement: Índice de catálogo en el prompt
Cuando el tenant tiene ítems de catálogo activos, `PromptBuilder` SHALL
incluir un índice compacto (id, nombre, precio legible, duración,
requisito de valoración con referencia al ítem vinculado). Si el tenant no
tiene catálogo activo, la sección SHALL omitirse.

#### Scenario: Tenant con catálogo
- **WHEN** se construye el prompt para una conversación de un tenant con
  ítems activos
- **THEN** el prompt contiene el índice con ids e indica qué ítems
  requieren valoración previa

#### Scenario: Tenant sin catálogo
- **WHEN** el tenant no tiene ítems activos
- **THEN** el prompt no contiene sección de catálogo

### Requirement: Tool get_service_details
El sistema SHALL registrar la tool `get_service_details` que, dado el id
de un ítem del catálogo del tenant, retorna su detalle completo
(descripción, precio, duración, política de valoración) para contexto
conversacional.

#### Scenario: Ítem existente
- **WHEN** el agente invoca la tool con un id válido del tenant
- **THEN** la tool retorna `ContinueWithRetrievedContext` con el detalle
  del ítem

#### Scenario: Ítem inexistente o de otro tenant
- **WHEN** el id no corresponde a un ítem activo del tenant
- **THEN** la tool retorna un resultado que indica que el ítem no existe,
  sin filtrar datos de otros tenants

### Requirement: Tool check_availability con slots computados
El sistema SHALL registrar la tool `check_availability` que, dado un ítem
agendable y una fecha, computa server-side los slots disponibles
(freebusy del calendario ∩ horario de atención configurado ∩
`duration_minutes` del ítem, en el timezone de la línea) y los retorna
como lista concreta. El LLM SHALL elegir únicamente entre los slots
retornados.

#### Scenario: Día con disponibilidad parcial
- **WHEN** el calendario tiene bloques ocupados dentro del horario de
  atención
- **THEN** la tool retorna solo slots que caben completos (duración del
  ítem) fuera de los bloques ocupados

#### Scenario: Ítem que requiere valoración
- **WHEN** se consulta disponibilidad de un ítem con `assessment_item_id`
- **THEN** la tool computa los slots usando la duración del ítem de
  valoración vinculado e indica que lo que se agenda es la valoración

### Requirement: Tool create_appointment con verdad desde la BD
El sistema SHALL registrar la tool `create_appointment` que crea el evento
en Google Calendar tomando la duración desde `catalog_items` (nunca de los
argumentos del LLM), re-validando disponibilidad inmediatamente antes de
insertar. Ítems con `assessment_item_id` SHALL ser rechazados para
agendamiento directo, retornando la instrucción de ofrecer la valoración
vinculada. La ejecución SHALL registrarse en `tool_executions` y como
evento de agente.

#### Scenario: Agendamiento directo exitoso
- **WHEN** el agente invoca la tool con un ítem agendable y un slot aún
  libre
- **THEN** se crea el evento con la duración de la BD y la tool retorna
  éxito con los datos de la cita

#### Scenario: Slot tomado entre consulta y creación
- **WHEN** el slot elegido fue ocupado después de `check_availability`
- **THEN** la tool no crea el evento y retorna la nueva disponibilidad
  para reintentar

#### Scenario: Procedimiento con valoración agendado directo
- **WHEN** el agente intenta `create_appointment` sobre un ítem con
  `assessment_item_id`
- **THEN** la tool rechaza la operación e indica el ítem de valoración
  que corresponde agendar

### Requirement: Degradación sin conexión de calendario
Cuando la línea no tiene conexión de calendario o esta falla, las tools de
agendamiento SHALL retornar `ToolNextAction::RequestHandoff` con razón
clara y registrar el evento, siguiendo el patrón de `SearchInventoryTool`
ante `DataSourceUnavailableException`.

#### Scenario: Línea sin conexión
- **WHEN** el agente invoca `check_availability` en una línea sin
  credencial `google_calendar`
- **THEN** la tool retorna handoff con razón de conexión no disponible

### Requirement: Configuración de agendamiento por scope
La configuración de agendamiento (calendario destino, horario de atención
por día, timezone con default `America/Bogota`) SHALL vivir en
`tenant_tool_configs.overrides` de las tools de agendamiento, resolviendo
por línea con fallback a tenant como el resto de tool configs.

#### Scenario: Override por línea
- **WHEN** una línea define horario de atención propio
- **THEN** los slots se computan con el horario de la línea, no el del
  tenant
