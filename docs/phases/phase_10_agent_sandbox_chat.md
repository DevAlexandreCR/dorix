# Fase 10 - Agent Sandbox Chat

## Objetivo

Agregar un sandbox de chat persistido, estilo ChatGPT/Claude, para probar el agente desde la SPA usando runtime, tools, retrieval, estado conversacional y trazabilidad reales, sin enviar mensajes por WhatsApp ni llamar a Meta.

## Scope incluido

- Sesiones sandbox por tenant y línea.
- Chat SPA para enviar mensajes de prueba al agente.
- Ejecución del runtime real con prompt, tools y retrieval configurados.
- Persistencia de conversaciones, mensajes, `conversation_state`, `tool_executions` y `agent_events`.
- Aislamiento explícito del canal WhatsApp real.
- Visibilidad básica de outcomes, tool calls, handoff y errores del último turno.

## Fuera de scope

- Envío real por Meta Graph API.
- Dependencia de credenciales WhatsApp.
- Canal público para clientes finales.
- Playground efímero sin persistencia.
- Nuevas integraciones externas.
- Cambios de dominio no necesarios para soportar el sandbox.

## Precondiciones

- Fase 9 terminada.
- Revisar [phase_9_observability_security_and_production_hardening.md](./phase_9_observability_security_and_production_hardening.md).
- Runtime, tools, retrieval, panel admin y autenticación SPA ya disponibles.

## Decisiones cerradas

- Esta fase es post-MVP y no cambia la definición de done global del MVP.
- El sandbox usa conversaciones persistidas, no ejecución efímera.
- Las sesiones sandbox se marcan con `source = agent_sandbox` o equivalente explícito.
- Las conversaciones sandbox no aparecen en el inbox operativo por defecto.
- El sandbox reutiliza `AgentRuntime`, `AgentContextLoader`, `AgentDecisionApplier`, tools y retrieval reales.
- El outbound del sandbox se persiste localmente y nunca llama a WhatsApp ni a Meta.
- El acceso se controla con `agent_configs.manage`.

## Entregables

- Documento de arquitectura mínima del sandbox dentro de esta fase.
- Endpoints autenticados bajo `/api/v1/agent-sandbox`.
- Servicio backend para ejecutar turnos sandbox.
- Outbound sender sandbox que persiste respuestas sin envío externo.
- Módulo frontend `frontend/src/modules/sandbox`.
- Vista SPA de sesiones, thread, composer y metadata de ejecución.
- Tests backend y build frontend.

## Cambios técnicos esperados

- Agregar una forma explícita de distinguir conversaciones WhatsApp reales de conversaciones sandbox.
- Crear sesiones sandbox asociadas a tenant y `whatsapp_line_id` para resolver prompt, config y bindings existentes.
- Persistir cada mensaje del usuario como inbound sandbox con `provider_message_id` local tipo `sandbox:{uuid}`.
- Ejecutar el runtime de forma síncrona para el turno sandbox y devolver los mensajes nuevos al frontend.
- Persistir respuestas del agente como outbound sandbox con status local, sin credenciales ni request externo.
- Registrar `agent_events` y `tool_executions` con payloads sanitizados como en el flujo real.
- Bloquear que una sesión sandbox dispare webhook, Meta outbound o callbacks de delivery.
- Excluir sesiones sandbox de listados operativos salvo filtro explícito futuro.

## Interfaces o contratos a definir

- `GET /api/v1/agent-sandbox/sessions`
- `POST /api/v1/agent-sandbox/sessions`
- `GET /api/v1/agent-sandbox/sessions/{conversation}`
- `POST /api/v1/agent-sandbox/sessions/{conversation}/messages`
- `POST /api/v1/agent-sandbox/sessions/{conversation}/close`
- Contrato frontend para sesión sandbox, mensaje sandbox y resumen del último turno.
- `SandboxAgentTurnService`
- `SandboxOutboundMessageSender`

## Riesgos y validaciones

- Evitar que pruebas sandbox envíen mensajes reales por WhatsApp.
- Evitar mezclar conversaciones sandbox con operación real.
- Evitar saltarse permisos de tenant.
- Evitar side effects no trazados cuando una tool modifica estado de conversación.
- Validar que fallos del runtime queden visibles sin bloquear el uso posterior del sandbox.

## Checklist de implementación

- Agregar marca de origen para conversaciones sandbox.
- Crear endpoints y requests de sandbox.
- Implementar servicio de turno sandbox.
- Implementar outbound sender local para sandbox.
- Conectar el runtime real al flujo sandbox.
- Agregar módulo frontend de sandbox.
- Agregar vista de sesiones, thread, composer y estado del turno.
- Mostrar metadata básica de outcomes, tools, handoff y errores.
- Agregar tests de permisos, persistencia, aislamiento de Meta y trazabilidad.
- Validar build frontend.

## Criterio de done

- Un `tenant_admin` puede crear una sesión sandbox desde la SPA, enviar mensajes y ver respuestas del agente.
- El flujo no requiere credenciales WhatsApp y no realiza llamadas a Meta.
- El historial sandbox queda persistido con estado, eventos y ejecuciones de tools inspeccionables.
- Las conversaciones sandbox no contaminan el inbox operativo por defecto.
- Los permisos impiden acceso a usuarios sin `agent_configs.manage`.
- Pasan:
  - `docker compose exec php-fpm php artisan test --filter=AgentSandbox`
  - `docker compose exec frontend npm run build`

## Prompt sugerido para Codex

```text
Implementa solo la Fase 10 usando:
- docs/implementation_plan_index.md
- docs/phases/phase_10_agent_sandbox_chat.md
- docs/phases/phase_9_observability_security_and_production_hardening.md
- docs/whatsapp_automation_mvp_architecture.md

Trabaja en backend/ y frontend/ para agregar un sandbox de chat persistido que pruebe el runtime real sin enviar mensajes por WhatsApp.
No implementes un playground efímero, canal público, nuevas integraciones externas ni envío real por Meta.
Explora primero el runtime, tools, conversaciones, outbound sender y panel admin existentes.
Luego agrega sesiones sandbox marcadas explícitamente, endpoints `/api/v1/agent-sandbox`, un outbound sender local y una vista SPA tipo chat para tenant admins.
Valida con tests de permisos, persistencia, aislamiento de Meta, trazabilidad de tools/eventos y build frontend.
```
