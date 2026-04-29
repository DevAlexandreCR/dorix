# Fase 1 - Core Domain and Multitenancy

## Objetivo

Definir el núcleo del dominio y la infraestructura de tenancy sobre la que se montarán WhatsApp, runtime, tools y handoff.

## Scope incluido

- Modelos y migraciones núcleo.
- Reglas de ownership por `tenant_id`.
- RBAC inicial.
- Resolución explícita de contexto tenant en backend.
- Constraints e índices mínimos del dominio base.

## Fuera de scope

- Webhook de WhatsApp.
- Runtime LLM.
- UI conversacional.
- Importación de Excel.

## Precondiciones

- Fase 0 terminada.
- Revisar [phase_0_foundation_and_repo_bootstrap.md](./phase_0_foundation_and_repo_bootstrap.md).

## Decisiones cerradas

- Tenancy single-database.
- Todas las tablas de negocio llevan `tenant_id`.
- Ningún job ni tool opera sin contexto tenant explícito.
- Roles iniciales: `platform_admin`, `tenant_admin`, `operator`, `viewer`.

## Entregables

- Migraciones del dominio base.
- Modelos y relaciones principales.
- Middleware o servicio de tenant context.
- Base de permisos y roles.
- Seeds mínimos de desarrollo si ayudan a probar tenancy.

## Cambios técnicos esperados

- Implementar tablas: `tenants`, `users`, `tenant_users`, `whatsapp_lines`, `api_credentials`, `agent_configs`, `conversations`, `conversation_messages`, `conversation_states`, `data_sources`, `uploaded_files`, `tenant_tool_configs`, `tool_executions`, `handoff_requests`, `agent_events`, `audit_events`.
- Forzar unicidad global de `phone_number_id`.
- Dejar `waba_id` como metadata opcional.
- Implementar índice único `(tenant_id, provider_message_id)` donde aplique.

## Interfaces o contratos a definir

- `TenantContextResolver`
- contratos de autorización por rol
- convenciones de consultas tenant-scoped
- DTOs o helpers mínimos para jobs con `tenant_id`

## Riesgos y validaciones

- Evitar relaciones ambiguas entre tablas tenant-scoped y platform-scoped.
- Evitar policies de auth que dependan de tenant implícito.
- Validar coherencia de foreign keys e índices desde el inicio.

## Checklist de implementación

- Crear migraciones núcleo.
- Crear modelos y relaciones.
- Configurar roles y permisos base.
- Implementar tenant context resolver.
- Definir convención para jobs tenant-aware.
- Verificar restricciones e índices críticos.

## Criterio de done

- El esquema base soporta tenants, líneas, conversaciones, estado y configuración.
- El backend tiene una forma explícita de resolver tenant context.
- Los roles mínimos existen y se pueden usar en futuras APIs.

## Prompt sugerido para Codex

```text
Implementa solo la Fase 1 usando:
- docs/implementation_plan_index.md
- docs/phases/phase_1_core_domain_and_multitenancy.md
- docs/phases/phase_0_foundation_and_repo_bootstrap.md
- docs/whatsapp_automation_mvp_architecture.md

Trabaja solo en backend/ y la documentación mínima asociada.
No implementes webhook, runtime, tools, Excel ni UI de producto.
Explora primero la estructura creada en Fase 0 y luego agrega el dominio base y tenancy explícita.
Valida con migraciones, pruebas unitarias o feature tests de ownership y tenant context.
```
