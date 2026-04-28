# Fase 9 - Observability, Security and Production Hardening

## Objetivo

Cerrar el MVP para operación productiva con observabilidad suficiente, protección de secretos, políticas de retry y validaciones de despliegue.

## Scope incluido

- Audit y agent events.
- Horizon y operación de colas.
- Retries y backoff.
- Cifrado en reposo.
- Sanitización de logs.
- Smoke checks de entorno productivo.

## Fuera de scope

- UI avanzada de dead-letter review.
- SIEM o monitoreo corporativo complejo.
- Hardening multi-región o HA.

## Precondiciones

- Fase 8 terminada.
- Revisar [phase_8_admin_panel_and_tenant_configuration.md](/Users/alexandrecr/devs/gorda/auto/docs/phases/phase_8_admin_panel_and_tenant_configuration.md).

## Decisiones cerradas

- Horizon como superficie principal de operación de colas.
- Retries base: `3` con backoff exponencial.
- Secretos cifrados en reposo.
- Logs sin credenciales ni payloads sensibles completos.

## Entregables

- Eventos mínimos persistidos.
- Configuración de retries y backoff.
- Cifrado de credenciales y tokens.
- Sanitización de logs.
- Checklist de despliegue y smoke tests.

## Cambios técnicos esperados

- Completar emisión del set mínimo de eventos operativos.
- Configurar colas y fallos persistentes con comportamiento consistente.
- Verificar que payloads de soporte guarden solo campos necesarios.
- Documentar runbook mínimo de despliegue y validación.

## Interfaces o contratos a definir

- contrato de eventos operativos
- convenciones de logging seguro
- criterios de health/smoke checks

## Riesgos y validaciones

- Evitar cifrado parcial o inconsistente de secretos.
- Evitar que retries creen duplicados visibles al cliente.
- Validar que la operación pueda reconstruir un incidente sin leer código.

## Checklist de implementación

- Persistir eventos faltantes.
- Configurar retries/backoff finales.
- Cifrar secretos en reposo.
- Sanitizar logs y payloads.
- Configurar Horizon y runbook operativo.
- Ejecutar smoke tests de despliegue.

## Criterio de done

- El sistema puede operar en producción con trazabilidad, colas observables y secretos protegidos.
- Los fallos más comunes tienen comportamiento predecible y rastreable.
- Existe una validación mínima de despliegue antes de usar el MVP.

## Prompt sugerido para Codex

```text
Implementa solo la Fase 9 usando:
- docs/implementation_plan_index.md
- docs/phases/phase_9_observability_security_and_production_hardening.md
- docs/phases/phase_8_admin_panel_and_tenant_configuration.md
- docs/whatsapp_automation_mvp_architecture.md

Trabaja en backend/, infra/ y frontend/ solo si hace falta exponer logs básicos ya definidos.
No agregues UI avanzada de dead letters ni infraestructura fuera del baseline de 1 VM con Docker Compose.
Explora primero cómo quedaron eventos, colas y credenciales; luego aplica hardening, retries, cifrado y smoke checks.
Valida con pruebas de idempotencia, sanitización de logs y verificación operativa del stack.
```
