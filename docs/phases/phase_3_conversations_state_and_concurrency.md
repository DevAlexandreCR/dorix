# Fase 3 - Conversations, State and Concurrency

## Objetivo

Volver consistente la conversación como unidad operativa persistente, con snapshot de estado y protección contra procesamiento concurrente.

## Scope incluido

- Modelo operativo de `conversation`.
- Snapshot único de `conversation_state`.
- Estados de conversación.
- Políticas de actualización de `last_message_at` y ownership.
- Locking o serialización por conversación.
- Expiración de `memory_summary`.

## Fuera de scope

- LLM real.
- Tools reales.
- UI de conversación.

## Precondiciones

- Fase 2 terminada.
- Revisar [phase_2_whatsapp_gateway_and_message_lifecycle.md](/Users/alexandrecr/devs/gorda/auto/docs/phases/phase_2_whatsapp_gateway_and_message_lifecycle.md).

## Decisiones cerradas

- `conversation_state` es snapshot único por conversación.
- Estados oficiales: `BOT_ACTIVE`, `WAITING_CUSTOMER`, `HUMAN_HANDOFF`, `CLOSED`, `ERROR`.
- La expiración limpia solo `memory_summary`.
- El procesamiento de la misma conversación no corre en paralelo sin protección.

## Entregables

- Servicios de resolución/creación de conversación.
- Gestión consistente de `conversation_state`.
- Mecanismo de locking por conversación.
- Helpers para transición de estados.

## Cambios técnicos esperados

- Determinar cómo se reusa o crea conversación por contacto y línea.
- Actualizar timestamps relevantes al persistir mensajes.
- Introducir guardas para evitar dobles respuestas o jobs simultáneos.
- Preparar el contrato que consumirá luego el runtime.

## Interfaces o contratos a definir

- `ConversationResolver`
- `ConversationStateRepository` o equivalente
- `ConversationLockManager`
- contrato de transición de estado

## Riesgos y validaciones

- Evitar que un lock demasiado agresivo bloquee throughput.
- Evitar flags duplicados que compitan con `status`.
- Validar que dedup y locking trabajen juntos y no se contradigan.

## Checklist de implementación

- Definir ciclo de creación o reuse de conversación.
- Implementar snapshot de estado y sus updates.
- Implementar locking por conversación.
- Definir transiciones válidas de estado.
- Validar expiración de memoria operativa.

## Criterio de done

- Cada inbound queda asociado a una conversación consistente.
- El estado conversacional se puede leer y actualizar de forma segura.
- El backend evita procesamiento concurrente conflictivo sobre la misma conversación.

## Prompt sugerido para Codex

```text
Implementa solo la Fase 3 usando:
- docs/implementation_plan_index.md
- docs/phases/phase_3_conversations_state_and_concurrency.md
- docs/phases/phase_2_whatsapp_gateway_and_message_lifecycle.md
- docs/whatsapp_automation_mvp_architecture.md

Trabaja solo en backend/ y tests relacionados.
No implementes todavía LLM, tools, Excel ni UI.
Explora primero cómo quedó el gateway y luego agrega el modelo operativo de conversación, estado snapshot y locking por conversación.
Valida con tests de transiciones, expiración de memoria y protección contra ejecución concurrente.
```
