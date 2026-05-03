# Agent Outputs: Packs, Policy y Handoff Seguro

## Summary

- Separar explícitamente **intent**, **outcome** y **tool** para que nuevos flujos como `appointment_scheduling`, `reservation_request` o `quote_request` se agreguen como intents y tools, sin crear un outcome nuevo por cada caso.
- Mantener el alcance MVP en `sales_support_v1`: lookup de conocimiento/inventario, captura/calificación de lead y handoff. No agregar lógica real de agenda, reservas ni cotizaciones todavía.
- Corregir huecos actuales del runtime: `request_handoff` debe poder enviar un mensaje público antes del handoff, `handoff_to_human` debe distinguirse del outcome `request_handoff`, y búsquedas sin matches no deben terminar en silencio.
- El `system_prompt` del tenant seguirá existiendo como instrucciones adicionales, pero subordinado al pack: puede ajustar tono o restringir comportamiento, nunca ampliar el alcance.

## Key Changes

- Crear contratos code-only en backend:
  - `AgentPack`: key, nombre, intents permitidos, tools permitidas por intent, reglas de respuesta directa, campos requeridos y mensaje default de handoff.
  - `IntentDefinition`: `key`, `allowed_outcomes`, `allowed_tools`, `required_fields`, `requires_retrieved_context_for_send_message`.
  - `AgentPackRegistry`: registra `sales_support_v1` y valida keys.
  - `AgentDecisionPolicy`: valida cada `AgentDecision` después del LLM y antes de `AgentDecisionApplier`.
- Si una decisión viola el pack, `AgentDecisionPolicy` debe devolver una decisión segura:
  - Por defecto, `request_handoff` con `reply_text` público.
  - Para `create_lead` sin datos mínimos, `request_missing_information` con los campos faltantes.
- Definir `sales_support_v1` con estos intents:
  - `knowledge_lookup`: puede llamar `search_knowledge`; solo puede usar `send_message` si hay `retrieved_context`.
  - `inventory_lookup`: puede llamar `search_inventory`; solo puede usar `send_message` si hay `retrieved_context`.
  - `customer_data_capture`: puede pedir datos o usar `save_customer_data`.
  - `lead_qualification`: puede pedir datos, usar `save_customer_data` o llamar `create_lead` solo cuando existan `customer_name` e `interest_summary`.
  - `handoff_requested`: permite `request_handoff` y, si se usa tool, `handoff_to_human`.
  - `unsupported`: solo permite `request_handoff`.
- Endurecer `PromptBuilder`:
  - Incluir el pack activo y sus intents permitidos en el prompt.
  - Prohibir small talk, chistes, conocimiento general y respuestas no relacionadas con el tenant.
  - Exigir retrieval para preguntas de inventario/conocimiento cuando no haya `retrieved_context`.
  - Declarar que `handoff_to_human` es una tool con side effects, mientras que `request_handoff` es un outcome del runtime.
- No agregar `out_of_scope_policy` como setting configurable en MVP; la política queda definida por el pack para evitar doble fuente de verdad.

## Handoff y Retrieval

- Cambiar `request_handoff` para aceptar `reply_text` público opcional.
- Antes de mover la conversación a `HUMAN_HANDOFF`, enviar `reply_text` al cliente:
  - Si el LLM trae `reply_text`, usarlo.
  - Si no lo trae, `AgentDecisionPolicy` debe inyectar `handoff_customer_message`.
- Mantener `handoff_to_human` como tool para side effects de handoff; no reemplaza ni duplica el outcome `request_handoff`.
- Si `search_inventory` o `search_knowledge` devuelve `ContinueWithRetrievedContext` sin matches, `AgentDecisionApplier` debe convertirlo en handoff seguro con mensaje público, no `wait_for_customer`.
- Si un segundo pase con `retrieved_context` intenta llamar otra tool, mantener el comportamiento seguro: bloquearlo y crear handoff.

## Lead

- Extender `create_lead` para aceptar opcionalmente:
  - `handoff_after_create: boolean`
  - `handoff_reason: string`
  - `reply_text: string`
- Si `handoff_after_create=true`, `create_lead` persiste el lead y devuelve `ToolNextAction::RequestHandoff`.
- Lead listo requiere `customer_name` e `interest_summary`.
- Si faltan campos requeridos para `lead_qualification`, la policy bloquea `create_lead` y convierte la decisión en `request_missing_information`.
- Para el MVP, la salida del lead es handoff interno. No agregar WhatsApp externo, webhook ni notificaciones externas.

## Public Interfaces

- `agent_configs.settings` añade:
  - `agent_pack_key`: default `sales_support_v1`.
  - `handoff_customer_message`: mensaje breve default configurable.
- API admin de agent config:
  - Acepta y serializa `agent_pack_key`.
  - Acepta y serializa `handoff_customer_message`.
  - Expone `available_agent_packs`.
- Sandbox expone en `last_turn`:
  - `agent_pack_key`
  - `policy.allowed`
  - `policy.normalized_intent`
  - `policy.blocked_reason`
  - `policy.replacement_outcome`
- `AgentDecision` conserva los outcomes actuales. Solo cambia la regla de `reply_text` para permitirlo en `request_handoff`.
- Para futuros flujos, el contrato es:
  - Agregar un `IntentDefinition`.
  - Asociar tools existentes o nuevas.
  - Declarar campos requeridos.
  - Agregar tests de policy.
  - No ampliar `AgentDecisionOutcome` salvo que cambie el runtime genérico.

## Test Plan

- Runtime/policy:
  - “dime un chiste” termina en `request_handoff`, envía mensaje fuera de alcance y crea handoff.
  - `send_message` con intent `unsupported` se bloquea y se convierte en handoff.
  - Una tool no permitida por el pack se bloquea antes de ejecutarse.
  - `system_prompt` no puede permitir respuestas fuera del pack.
- Retrieval:
  - Pregunta de inventario llama `search_inventory` cuando está habilitada.
  - Pregunta de conocimiento llama `search_knowledge` cuando está habilitada.
  - Sin binding, sin fuente o sin matches termina en handoff con mensaje público.
  - Follow-up con `retrieved_context` no puede llamar otra tool.
- Lead:
  - Con `customer_name` e `interest_summary`, `create_lead` persiste lead y crea handoff si `handoff_after_create=true`.
  - Sin campos requeridos, pide datos faltantes y no crea lead listo.
- Admin/sandbox:
  - Admin guarda y serializa `agent_pack_key`.
  - Admin guarda y serializa `handoff_customer_message`.
  - Sandbox muestra pack activo y resultado de policy sin enviar WhatsApp real.
- Validación final:
  - `docker compose exec php-fpm php artisan test --filter=Agent`
  - `docker compose exec frontend npm run build`

## Assumptions

- Pack inicial único: `sales_support_v1`.
- MVP no implementa agenda, reservas, cotizaciones, WhatsApp externo de notificación ni webhooks nuevos.
- Lead listo requiere `customer_name` e `interest_summary`.
- El cliente siempre recibe un mensaje breve antes de handoff automático.
- Nuevas capacidades futuras con side effects se implementan como tools nuevas y se habilitan desde el pack correspondiente.
