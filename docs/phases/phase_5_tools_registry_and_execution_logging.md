# Fase 5 - Tools Registry and Execution Logging

## Objetivo

Agregar la capa de tools del runtime con registry code-only, enablement por tenant y trazabilidad completa de ejecuciones.

## Scope incluido

- Tool Registry.
- Contrato base de tools.
- Configuración por tenant.
- Timeouts y ejecución controlada.
- Logs de ejecución y errores.
- Implementación funcional de `create_lead`, `save_customer_data` y `handoff_to_human`.
- Registro de `search_inventory` y `search_knowledge` para completarse funcionalmente en la Fase 6.

## Fuera de scope

- Excel importer completo.
- UI para administrar definiciones de tools.
- Integraciones genéricas API/DB.

## Precondiciones

- Fase 4 terminada.
- Revisar [phase_4_agent_runtime_and_llm_integration.md](./phase_4_agent_runtime_and_llm_integration.md).

## Decisiones cerradas

- Las definiciones de tools viven en código.
- `tenant_tool_configs` controla enablement, overrides y bindings.
- No se permite lógica por nombre de tenant.

## Entregables

- `ToolRegistry`.
- `ToolInterface`.
- Ejecutor con timeout y logging.
- Tools funcionales `create_lead`, `save_customer_data` y `handoff_to_human`.
- Registro estable de `search_inventory` y `search_knowledge` con contratos listos para su integración en la Fase 6.

## Cambios técnicos esperados

- Registrar tools por nombre, schema básico y handler.
- Resolver tools habilitadas para tenant y línea.
- Persistir `tool_executions` con input/output resumido, duración y resultado.
- Integrar `call_tool` como outcome soportado del runtime.
- Declarar explícitamente en `ToolRegistry` qué tools quedan funcionales en esta fase y cuáles dependen del vertical Excel.
- Dejar claro que las tools de retrieval recuperan contexto y no construyen la respuesta final al cliente.

## Interfaces o contratos a definir

- `ToolInterface`
- `ToolRegistry`
- `ToolExecutionRunner`
- DTOs de input y output de tools
- Mapa estable del set obligatorio de tools MVP y su fase de implementación

## Riesgos y validaciones

- Evitar tools con acceso directo a datos fuera de adapters aprobados.
- Evitar que fallos de tools rompan el runtime sin registrar contexto.
- Validar timeout y serialización de payloads resumidos.

## Checklist de implementación

- Crear contrato base de tools.
- Crear registry code-only.
- Implementar lectura de `tenant_tool_configs`.
- Registrar `tool_executions`.
- Integrar tool calling al runtime.
- Implementar `create_lead`, `save_customer_data` y `handoff_to_human`.
- Registrar `search_inventory` y `search_knowledge` con contratos definitivos para conectarse a Excel en la Fase 6.

## Criterio de done

- El runtime puede resolver y ejecutar con trazabilidad `create_lead`, `save_customer_data` y `handoff_to_human`.
- Los tenants pueden habilitar o deshabilitar tools por configuración.
- `search_inventory` y `search_knowledge` quedan registradas sin ambigüedad y listas para hacerse funcionales en la Fase 6.
- La observabilidad de tools queda lista para uso operativo.

## Prompt sugerido para Codex

```text
Implementa solo la Fase 5 usando:
- docs/implementation_plan_index.md
- docs/phases/phase_5_tools_registry_and_execution_logging.md
- docs/phases/phase_4_agent_runtime_and_llm_integration.md
- docs/whatsapp_automation_mvp_architecture.md

Trabaja solo en backend/ y tests asociados.
No implementes todavía Excel importer completo ni UI admin de tools.
Explora primero cómo quedó el runtime y luego agrega registry, contratos y execution logging sin introducir lógica por tenant hardcodeada.
Implementa funcionalmente `create_lead`, `save_customer_data` y `handoff_to_human`.
Deja `search_inventory` y `search_knowledge` registradas con contrato estable pero con implementación funcional diferida a la Fase 6.
Valida con pruebas de enablement, timeout y persistencia de tool executions.
```
