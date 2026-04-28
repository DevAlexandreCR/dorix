# Fase 8 - Admin Panel and Tenant Configuration

## Objetivo

Completar el panel administrativo mínimo para operar tenants, líneas, credenciales, prompts y configuración de automatización sin intervención manual en la base de datos.

## Scope incluido

- Gestión de tenants y tenant users.
- Gestión de líneas de WhatsApp.
- Metadata de credenciales.
- Configuración de prompts.
- Enable/disable de automatización.
- Logs básicos de operación.

## Fuera de scope

- Rotación de credenciales por `tenant_admin`.
- UI completa de failed jobs.
- Historial avanzado de prompts con rollback.

## Precondiciones

- Fase 7 terminada.
- Revisar [phase_7_handoff_internal_console_and_manual_reply.md](/Users/alexandrecr/devs/gorda/auto/docs/phases/phase_7_handoff_internal_console_and_manual_reply.md).

## Decisiones cerradas

- Auth con Sanctum.
- `tenant_admin` ve metadata de credenciales, no rota secretos.
- Prompt versioning existe en backend, no requiere UI avanzada.

## Entregables

- Vistas SPA para administración base.
- APIs CRUD mínimas de tenants, lines y configs.
- Gestión visual de enablement y metadata sensible.

## Cambios técnicos esperados

- Exponer endpoints seguros para configuración.
- Mostrar estado de credenciales sin revelar valores.
- Permitir editar prompt/config actual y flags de automatización.
- Añadir vistas básicas de logs relevantes para operación.

## Interfaces o contratos a definir

- CRUD de tenants y lines
- edición de `agent_config`
- lectura de metadata de credenciales

## Riesgos y validaciones

- Evitar sobreexponer datos sensibles en responses del backend.
- Evitar que el panel permita estados inválidos de línea o automatización.
- Validar permisos diferenciados entre `platform_admin` y `tenant_admin`.

## Checklist de implementación

- Implementar CRUD de tenants y lines.
- Implementar vista de metadata de credenciales.
- Implementar edición de prompt/config.
- Implementar toggles de automatización.
- Implementar logs básicos de operación.

## Criterio de done

- El MVP puede configurarse y operarse desde el panel sin tocar la base de datos manualmente.
- Los permisos básicos separan administración de plataforma y administración de tenant.
- La exposición de secretos queda controlada.
- La fase reutiliza la autenticación mínima ya introducida en la Fase 7 y no redefine `auth/session`.

## Prompt sugerido para Codex

```text
Implementa solo la Fase 8 usando:
- docs/implementation_plan_index.md
- docs/phases/phase_8_admin_panel_and_tenant_configuration.md
- docs/phases/phase_7_handoff_internal_console_and_manual_reply.md
- docs/whatsapp_automation_mvp_architecture.md

Trabaja en backend/ y frontend/ para el panel admin mínimo.
No implementes rotación completa de credenciales, rollback de prompts ni UI avanzada de failed jobs.
Asume que la autenticación mínima ya existe desde la Fase 7 y no la redefinas en esta fase.
Explora primero los endpoints y modelos existentes; luego agrega las vistas y APIs mínimas de configuración del MVP.
Valida con pruebas de permisos y con un flujo de configuración end-to-end de tenant y línea.
```
