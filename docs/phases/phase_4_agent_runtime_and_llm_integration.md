# Fase 4 - Agent Runtime and LLM Integration

## Objetivo

Implementar el runtime mínimo del agente con integración a OpenAI `gpt-5.1`, contratos de entrada/salida estables y política de fallback segura.

## Scope incluido

- `AgentRuntime`, `AgentContext`, `AgentDecision`, `PromptBuilder`.
- Carga de contexto mínimo.
- Soporte contractual para `retrieved_context` y metadata de retrieval.
- Provider adapter OpenAI.
- Outcomes oficiales del runtime.
- Logging de ejecución del runtime.
- Fallback a `HUMAN_HANDOFF`.

## Fuera de scope

- Tool execution real compleja.
- Retrieval funcional de Excel.
- UI administrativa.

## Precondiciones

- Fase 3 terminada.
- Revisar [phase_3_conversations_state_and_concurrency.md](./phase_3_conversations_state_and_concurrency.md).

## Decisiones cerradas

- Provider baseline: OpenAI `gpt-5.1`.
- Prompt versioning persistido backend-only.
- Outcomes: `send_message`, `request_missing_information`, `call_tool`, `wait_for_customer`, `request_handoff`, `no_reply`, `error`.
- Fallo inseguro deriva a `HUMAN_HANDOFF`.
- El contrato del runtime puede recibir contexto recuperado por una tool en un pase posterior.

## Entregables

- Runtime mínimo funcional.
- Adapter de proveedor LLM.
- Estructuras DTO del runtime.
- Contrato de contexto listo para continuation post-tool.
- Persistencia de eventos y errores del runtime.

## Cambios técnicos esperados

- Cargar tenant, line, conversation, history breve, state snapshot, agent config y tools habilitadas.
- Permitir que `AgentContext` transporte `retrieved_context` y metadata asociada cuando exista.
- Construir prompt con estructura estable.
- Aceptar contexto recuperado como entrada válida del runtime sin asumir todavía retrieval funcional.
- Normalizar respuesta del modelo a outcomes internos.
- Bloquear respuestas parciales o inválidas.
- Evitar cerrar el runtime a un modelo rígido de una decisión y una sola respuesta sin continuation.

## Interfaces o contratos a definir

- `AgentRuntimeInterface`
- `AgentContext`
- `AgentDecision`
- `LlmProviderInterface`
- `PromptBuilder`

## Riesgos y validaciones

- Evitar parseo frágil de salida del modelo.
- Evitar que el runtime envíe mensajes si la decisión no está validada.
- Evitar que el contrato del runtime impida un segundo pase después de una tool de retrieval.
- Validar timeouts y manejo de errores de proveedor.

## Checklist de implementación

- Crear runtime y DTOs base.
- Dejar `AgentContext` y `PromptBuilder` listos para aceptar `retrieved_context`.
- Implementar adapter OpenAI.
- Construir prompt base por tenant/line.
- Validar y mapear outcomes.
- Registrar eventos del runtime.
- Aplicar fallback a `HUMAN_HANDOFF` ante fallo inseguro.

## Criterio de done

- El backend puede tomar una conversación persistida y producir una decisión válida del runtime.
- Los fallos del modelo quedan registrados y llevan la conversación a un estado operativo seguro.
- El contrato de entrada/salida del runtime queda estable para fases siguientes, incluidas tools que devuelven contexto recuperado.

## Prompt sugerido para Codex

```text
Implementa solo la Fase 4 usando:
- docs/implementation_plan_index.md
- docs/phases/phase_4_agent_runtime_and_llm_integration.md
- docs/phases/phase_3_conversations_state_and_concurrency.md
- docs/whatsapp_automation_mvp_architecture.md

Trabaja solo en backend/ y configuración segura del provider si aplica.
No implementes todavía tools completas, Excel ni UI.
Explora primero los contratos existentes del gateway y conversaciones, luego agrega el runtime mínimo con OpenAI gpt-5.1 y fallback seguro.
Deja el runtime listo para recibir `retrieved_context` en un pase posterior, sin implementar todavía retrieval funcional de Excel.
Valida con pruebas del mapeo de outcomes y del comportamiento ante errores del proveedor.
```
