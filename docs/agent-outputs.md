# Agent Runtime Outputs: Intents, Tools, Policy y Handoff Seguro

## Estado del documento

Este documento es un plan de cambios futuros sobre el runtime actual, no una descripcion exacta de lo que ya existe en codigo. La arquitectura vigente que se preserva es:

`AgentDecision -> outcome=call_tool -> ToolExecutionRunner -> ToolResult/ToolNextAction`

El MVP no migra todavia a tool/function calling nativo por proveedor. Mantiene un adapter propio y provider-agnostic basado en structured outputs, compatible conceptualmente con los patrones de OpenAI, Anthropic y Gemini: salida estructurada para decisiones controladas, tools/function calling para ejecutar capacidades externas, y validacion de negocio en la aplicacion.

Fuentes de alineacion:

- OpenAI: [function calling](https://platform.openai.com/docs/guides/function-calling), [structured outputs](https://platform.openai.com/docs/guides/structured-outputs), [handoffs](https://openai.github.io/openai-agents-python/handoffs/)
- Anthropic: [tool use](https://docs.anthropic.com/en/docs/agents-and-tools/tool-use/overview)
- Gemini: [structured outputs](https://ai.google.dev/gemini-api/docs/structured-output), [function calling](https://ai.google.dev/gemini-api/docs/function-calling)

## Summary

- Separar explicitamente **intent**, **outcome** y **tool**. Nuevos flujos como `appointment_scheduling`, `reservation_request` o `quote_request` se agregan primero como intents; solo requieren tools si necesitan consultar datos, persistir estado o ejecutar side effects auditables.
- No tratar outputs/outcomes y tools como conceptos intercambiables. Los outcomes son decisiones estructuradas del runtime; las tools son capacidades ejecutables registradas en `ToolRegistry`.
- Mantener el alcance MVP en `sales_support_v1`: lookup de conocimiento/inventario, captura/calificacion de lead y handoff seguro. No agregar logica real de agenda, reservas ni cotizaciones todavia.
- Para handoffs iniciados por el modelo, usar solo `call_tool` + `handoff_to_human`. `request_handoff` queda como outcome interno de fallback, policy o normalizacion mientras exista en el codigo.
- Corregir huecos actuales del runtime: todo handoff automatico debe enviar un mensaje publico antes de mover la conversacion a `HUMAN_HANDOFF`, y busquedas sin binding, fuente o matches no deben terminar en silencio.
- El `system_prompt` del tenant sigue existiendo como instrucciones adicionales, pero subordinado al pack: puede ajustar tono o restringir comportamiento, nunca ampliar alcance.

## Terminologia

- `intent`: clasificacion semantica del objetivo del usuario, usada por policy para decidir que outcomes y tools son validos.
- `outcome`: decision estructurada de `AgentDecision` que el runtime puede aplicar, por ejemplo `send_message`, `request_missing_information`, `call_tool`, `wait_for_customer`, `request_handoff`, `no_reply` o `error`.
- `tool`: capacidad ejecutable registrada en `ToolRegistry`, con schema, handler, enablement por tenant/linea, timeout y logging en `tool_executions`.
- `call_tool`: outcome provider-agnostic que conecta la decision estructurada del LLM con una tool local mediante `tool_name` y `tool_arguments_json`.
- `handoff_to_human`: tool model-callable para solicitar handoff con side effects auditables.
- `request_handoff`: outcome interno reservado para fallos, bloqueos de policy o fallback seguro. No debe ser la ruta normal que el LLM elige para handoff.
- `tool_result` / `ToolNextAction`: resultado interno de una tool despues de ejecutarse. Puede pedir segundo pase con contexto recuperado, enviar un mensaje, esperar, pedir handoff o no responder, pero no es un outcome publico emitido por el LLM.

La practica de OpenAI, Anthropic y Gemini separa structured outputs de tool/function calling. Structured outputs sirven para producir una forma controlada; tool/function calling conecta el modelo con datos, codigo o acciones externas. Por eso, en este MVP `call_tool` es el puente local y no una senal para convertir todos los outcomes en tools.

## Key Changes

- Crear contratos code-only a implementar en backend:
  - `AgentPack`: key, nombre, intents permitidos, outcomes permitidos por intent, tools permitidas por intent, reglas de cuando un intent puede terminar en `send_message`, `call_tool` o fallback interno, campos requeridos y mensaje default de handoff.
  - `IntentDefinition`: `key`, `allowed_outcomes`, `allowed_tools`, `required_fields`, `requires_retrieved_context_for_send_message`.
  - `AgentPackRegistry`: registra `sales_support_v1` y valida keys.
  - `AgentDecisionPolicy`: valida cada `AgentDecision` despues del LLM y antes de `AgentDecisionApplier`.
- Si una decision viola el pack, `AgentDecisionPolicy` debe devolver una decision segura:
  - Por defecto, fallback interno `request_handoff` con mensaje publico.
  - Para `create_lead` sin datos minimos, `request_missing_information` con los campos faltantes.
- Definir `sales_support_v1` con estos intents:
  - `knowledge_lookup`: puede llamar `search_knowledge`; solo puede usar `send_message` si hay `retrieved_context`.
  - `inventory_lookup`: puede llamar `search_inventory`; solo puede usar `send_message` si hay `retrieved_context`.
  - `customer_data_capture`: puede pedir datos o usar `save_customer_data`.
  - `lead_qualification`: puede pedir datos, usar `save_customer_data` o llamar `create_lead` solo cuando existan `customer_name` e `interest_summary`.
  - `handoff_requested`: debe usar `call_tool` con `handoff_to_human`; `request_handoff` solo queda permitido como fallback interno de policy/runtime.
  - `unsupported`: debe terminar en `call_tool` con `handoff_to_human` o, si no es posible ejecutar la tool, en fallback interno `request_handoff`.
- Endurecer `PromptBuilder`:
  - Incluir el pack activo y sus intents permitidos en el prompt.
  - Prohibir small talk, chistes, conocimiento general y respuestas no relacionadas con el tenant.
  - Exigir retrieval para preguntas de inventario/conocimiento cuando no haya `retrieved_context`.
  - Declarar que `handoff_to_human` es la unica ruta model-callable para handoff.
  - Declarar que `request_handoff` es un mecanismo interno de runtime/policy, no una opcion normal para el modelo.
- Mantener `call_tool` como puente provider-agnostic:
  - El LLM produce `outcome=call_tool` con `tool_name` y `tool_arguments_json`.
  - `AgentDecisionPolicy` valida que outcome, intent y tool sean permitidos por el pack.
  - `ToolExecutionRunner` valida enablement por tenant/linea y ejecuta la tool registrada.
  - `ToolResult` decide el siguiente paso interno mediante `ToolNextAction`.
- No convertir en tools los outcomes `send_message`, `request_missing_information`, `wait_for_customer`, `no_reply` ni `error`.
- No agregar `out_of_scope_policy` como setting configurable en MVP; la politica queda definida por el pack para evitar doble fuente de verdad.
- `handoff_customer_message` solo personaliza el texto permitido de handoff. No define alcance, intents, tools ni reglas de policy.

## Handoff y Retrieval

- Para handoff iniciado por el modelo:
  - Usar `outcome=call_tool`.
  - Usar `tool_name=handoff_to_human`.
  - Incluir `reason` y `reply_text` en los argumentos de la tool.
- Extender `handoff_to_human` para aceptar `reply_text` publico opcional.
- Antes de mover la conversacion a `HUMAN_HANDOFF`, enviar un mensaje publico al cliente:
  - Si `handoff_to_human` trae `reply_text`, usarlo.
  - Si el fallback interno usa `request_handoff`, usar su `reply_text`.
  - Si no hay texto, `AgentDecisionPolicy` debe inyectar `handoff_customer_message`.
- Mantener `request_handoff` como fallback interno para errores, policy blocks, tool failures o rutas donde el runtime ya no puede confiar en una decision model-callable.
- Si `search_inventory` o `search_knowledge` devuelve `ContinueWithRetrievedContext` sin matches, `AgentDecisionApplier` debe convertirlo en handoff seguro con mensaje publico, no `wait_for_customer`.
- Si un segundo pase con `retrieved_context` intenta llamar otra tool, mantener el comportamiento seguro: bloquearlo y crear handoff con mensaje publico.
- Sin binding, sin fuente disponible o sin matches, el cliente debe recibir una respuesta breve de handoff y la conversacion debe quedar trazada.

## Lead

- Extender `create_lead` para aceptar opcionalmente:
  - `handoff_after_create: boolean` con default `false`.
  - `handoff_reason: string`.
  - `reply_text: string`.
- Si `handoff_after_create=true`, `create_lead` persiste el lead y termina en el pipeline de handoff publico con `reply_text` y `handoff_reason`.
- Ese handoff post-lead puede implementarse internamente con `ToolNextAction::RequestHandoff`; no requiere que el LLM haga una segunda llamada a `handoff_to_human`.
- Lead listo requiere `customer_name` e `interest_summary`.
- Si faltan campos requeridos para `lead_qualification`, la policy bloquea `create_lead` y convierte la decision en `request_missing_information`.
- Para el MVP, la salida del lead es handoff interno. No agregar WhatsApp externo, webhook ni notificaciones externas.

## Public Interfaces

Estas interfaces son a implementar; no existen completas hoy.

- `agent_configs.settings` añade:
  - `agent_pack_key`: default `sales_support_v1`.
  - `handoff_customer_message`: mensaje breve default configurable.
- API admin de agent config:
  - Acepta y serializa `agent_pack_key`.
  - Acepta y serializa `handoff_customer_message`.
  - Expone `available_agent_packs`.
- Sandbox expone en `last_turn`:
  - `agent_pack_key`.
  - `policy.allowed`.
  - `policy.normalized_intent`.
  - `policy.blocked_reason`.
  - `policy.replacement_outcome`.
- `AgentDecision` conserva los outcomes actuales. El cambio de comportamiento es que `request_handoff` se reserva para fallback interno, y los handoffs model-callable pasan por `handoff_to_human`.
- Para futuros flujos, el contrato es:
  - Agregar un `IntentDefinition`.
  - Asociar tools existentes solo si el intent necesita una capacidad ejecutable real.
  - Crear tools nuevas unicamente para retrieval, persistencia, integraciones externas o side effects auditables.
  - No crear tools que solo dupliquen outcomes genericos del runtime.
  - Declarar campos requeridos.
  - Agregar tests de policy.
  - No ampliar `AgentDecisionOutcome` salvo que cambie una accion generica del runtime, no por cada flujo de negocio.

## Test Plan

- Documentacion:
  - El documento ya no presenta outputs/outcomes y tools como intercambiables.
  - El flujo `outcome=call_tool -> ToolExecutionRunner -> ToolResult/ToolNextAction` queda claro.
  - `handoff_to_human` queda como unica ruta model-callable de handoff.
  - `request_handoff` queda documentado como fallback interno.
  - El plan sigue compatible con Fase 4, Fase 5 y el codigo actual de `AgentDecisionOutcome`, `ToolRegistry`, `ToolResult` y `AgentDecisionApplier`.
- Runtime/policy:
  - "dime un chiste" termina en handoff publico y crea handoff.
  - `send_message` con intent `unsupported` se bloquea y se convierte en handoff.
  - Una tool no permitida por el pack se bloquea antes de ejecutarse.
  - `system_prompt` no puede permitir respuestas fuera del pack.
- Handoff:
  - El modelo pide handoff con `call_tool` + `handoff_to_human`.
  - Si `handoff_to_human` no esta habilitada o falla, el runtime usa fallback interno `request_handoff` con mensaje publico.
  - Todo handoff automatico envia mensaje antes de mover la conversacion a `HUMAN_HANDOFF`.
- Retrieval:
  - Pregunta de inventario llama `search_inventory` cuando esta habilitada.
  - Pregunta de conocimiento llama `search_knowledge` cuando esta habilitada.
  - Sin binding, sin fuente o sin matches termina en handoff con mensaje publico.
  - Follow-up con `retrieved_context` no puede llamar otra tool.
- Lead:
  - Con `customer_name` e `interest_summary`, `create_lead` persiste lead.
  - Con `handoff_after_create=true`, `create_lead` termina en handoff publico.
  - Sin campos requeridos, pide datos faltantes y no crea lead listo.
- Admin/sandbox:
  - Admin guarda y serializa `agent_pack_key`.
  - Admin guarda y serializa `handoff_customer_message`.
  - Sandbox muestra pack activo y resultado de policy sin enviar WhatsApp real.
- Validacion documental:
  - Verificar que solo cambia `docs/agent-outputs.md`.
  - No correr Docker ni tests de runtime para esta tarea, porque el cambio solicitado es solo documental.

## Assumptions

- "genimo" se interpreta como Gemini.
- Pack inicial unico: `sales_support_v1`.
- `outputs` en este documento significa salida estructurada del agente/runtime, no tools.
- Se preserva la arquitectura actual del repo: outcomes del runtime separados de tools ejecutables mediante el adapter `call_tool`.
- MVP no implementa agenda, reservas, cotizaciones, WhatsApp externo de notificacion ni webhooks nuevos.
- Lead listo requiere `customer_name` e `interest_summary`.
- El cliente siempre recibe un mensaje breve antes de handoff automatico.
- Nuevas capacidades futuras con side effects se implementan como tools nuevas y se habilitan desde el pack correspondiente.
