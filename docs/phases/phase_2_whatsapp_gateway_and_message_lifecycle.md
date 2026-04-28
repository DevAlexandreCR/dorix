# Fase 2 - WhatsApp Gateway and Message Lifecycle

## Objetivo

Construir el gateway de WhatsApp y el ciclo de vida de mensajes inbound/outbound sin ejecutar todavía el runtime completo del agente.

## Scope incluido

- Webhook verification.
- Validación de payload.
- Resolución de línea por `phone_number_id`.
- Persistencia de inbound y status callbacks.
- Deduplicación inbound.
- Pipeline outbound con `idempotency_key`.
- Jobs base de procesamiento asíncrono.

## Fuera de scope

- Decisión semántica del agente.
- Tools.
- Handoff UI.
- Importación de datos.

## Precondiciones

- Fase 1 terminada.
- Revisar [phase_1_core_domain_and_multitenancy.md](/Users/alexandrecr/devs/gorda/auto/docs/phases/phase_1_core_domain_and_multitenancy.md).

## Decisiones cerradas

- Webhook no ejecuta runtime inline.
- Dedup inbound con `(tenant_id, provider_message_id)`.
- Outbound con `idempotency_key` persistida antes del provider call.
- Non-text inbound se guarda como `unsupported`.
- Status callbacks actualizan el mismo mensaje outbound.

## Entregables

- Controlador de webhook.
- DTOs o normalizadores de payload Meta.
- Servicio de resolución de línea y tenant.
- Persistencia de inbound, outbound y status.
- Jobs despachados para procesamiento posterior.

## Cambios técnicos esperados

- Crear endpoint público de webhook.
- Validar challenge y payloads principales.
- Persistir soporte mínimo del payload para debugging sin secretos.
- Registrar eventos de recepción, dedup y dispatch.
- Dejar listo el contrato de `ProcessIncomingMessageJob`.

## Interfaces o contratos a definir

- `WhatsAppWebhookHandler`
- `WhatsAppLineResolver`
- `OutboundMessageSender`
- DTOs inbound/status/outbound
- contrato del job de procesamiento con `tenant_id`, `conversation_id`, `message_id`

## Riesgos y validaciones

- Evitar respuestas duplicadas por carreras en dedup.
- Evitar depender de campos opcionales de Meta como llave principal.
- Validar que errores de webhook queden trazados sin romper disponibilidad.

## Checklist de implementación

- Exponer webhook verification.
- Parsear inbound text y non-text.
- Resolver tenant por `phone_number_id`.
- Persistir inbound y dedup hits.
- Crear flujo outbound con `idempotency_key`.
- Persistir status callbacks.
- Despachar job asíncrono.

## Criterio de done

- El gateway puede recibir mensajes, deduplicarlos, persistirlos y despachar jobs.
- El ciclo outbound queda persistido y trazable de punta a punta.
- El runtime sigue desacoplado del webhook.

## Prompt sugerido para Codex

```text
Implementa solo la Fase 2 usando:
- docs/implementation_plan_index.md
- docs/phases/phase_2_whatsapp_gateway_and_message_lifecycle.md
- docs/phases/phase_1_core_domain_and_multitenancy.md
- docs/whatsapp_automation_mvp_architecture.md

Trabaja solo en backend/ y config relacionada con colas o rutas si es necesario.
No implementes todavía el runtime, tools, Excel ni UI.
Explora primero el dominio existente y luego construye el gateway y el lifecycle de mensajes con persistencia e idempotencia.
Valida con feature tests de webhook verification, inbound dedup, outbound persistence y status callbacks.
```
