# Fase 7 - Handoff Internal Console and Manual Reply

## Objetivo

Habilitar la operación humana mínima del MVP: ver conversaciones, tomar control, responder manualmente y devolver el control al bot.

## Scope incluido

- Login y sesión mínima de SPA para operadores.
- Inbox básico.
- Thread viewer.
- Cambio a `HUMAN_HANDOFF`.
- Asignación de operador.
- Manual reply de texto.
- Resume del bot y limpieza de `assigned_to_user_id`.

## Fuera de scope

- Chatwoot.
- Routing avanzado.
- SLAs, macros, analytics o omnichannel.
- Templates manuales fuera de ventana.

## Precondiciones

- Fase 6 terminada.
- Revisar [phase_6_excel_document_retrieval_and_indexing.md](./phase_6_excel_document_retrieval_and_indexing.md).

## Decisiones cerradas

- Handoff interno sin Chatwoot.
- Reply manual solo texto.
- Mientras haya `HUMAN_HANDOFF`, el bot no puede auto-responder.
- Al retomar el bot se limpia `assigned_to_user_id`.

## Entregables

- `auth/session` mínima para la consola operativa.
- Endpoints backend para inbox, thread, handoff, assignment, manual reply y resume.
- UI mínima para operadores.
- Auditoría básica de ownership.

## Cambios técnicos esperados

- Exponer login y sesión mínima con Sanctum para habilitar la consola operativa.
- Bloquear runtime outbound cuando la conversación esté en `HUMAN_HANDOFF`.
- Registrar quién tomó la conversación y cuándo.
- Permitir reply manual reutilizando el pipeline outbound ya construido.
- Definir transiciones seguras de vuelta a `BOT_ACTIVE` o `WAITING_CUSTOMER`.
- Aplicar guardas de acceso por rol para operadores y usuarios con permiso de reactivación.

## Interfaces o contratos a definir

- contrato SPA de `auth/session` para la consola operativa
- endpoints de inbox y thread
- contrato de handoff/reassign/resume
- contrato de manual reply

## Riesgos y validaciones

- Evitar que el bot responda mientras el humano escribe o después de un reply manual.
- Evitar estados intermedios inconsistentes al retomar el bot.
- Validar permisos por rol para tomar o reactivar conversaciones.

## Checklist de implementación

- Implementar login y sesión mínima para operadores.
- Crear APIs de listado y detalle de conversaciones.
- Crear acción de handoff y toma de ownership.
- Crear manual reply de texto.
- Crear resume del bot.
- Registrar auditoría de ownership y cambios de estado.
- Validar bloqueo del runtime durante handoff.
- Validar guardas de acceso por rol.

## Criterio de done

- Un operador puede operar una conversación end-to-end sin herramientas externas.
- La consola operativa puede autenticarse con `auth/session` mínima sin depender de la Fase 8.
- El bot se pausa y se reactiva correctamente.
- El ownership humano queda trazado.

## Prompt sugerido para Codex

```text
Implementa solo la Fase 7 usando:
- docs/implementation_plan_index.md
- docs/phases/phase_7_handoff_internal_console_and_manual_reply.md
- docs/phases/phase_6_excel_document_retrieval_and_indexing.md
- docs/whatsapp_automation_mvp_architecture.md

Trabaja en backend/ y frontend/ solo para la consola operativa mínima.
Incluye login y sesión mínima con Sanctum para que esta fase sea implementable sin depender de la Fase 8.
No implementes panel admin completo, templates manuales, routing avanzado ni analytics.
Explora primero el pipeline de mensajes y estados; luego agrega auth mínima, handoff, manual reply y resume del bot con UI mínima.
Valida con flujos de conversación donde el bot se pause y no pueda enviar mensajes durante handoff, y con guardas de acceso por rol.
```
