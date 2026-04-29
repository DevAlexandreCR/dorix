# Índice de Implementación por Fases

## Resumen ejecutivo del MVP

Este índice convierte la arquitectura base en un plan operativo por fases, optimizado para trabajar con Codex en slices pequeños y con límites de contexto claros.

Documentos fuente:

- Arquitectura base: [whatsapp_automation_mvp_architecture.md](./whatsapp_automation_mvp_architecture.md)
- Plan consolidado previo: [master_plan.md](./master_plan.md)

Decisiones globales ya cerradas:

- Monorepo con `backend/`, `frontend/`, `infra/`.
- Backend Laravel API; frontend Vue 3 + TypeScript SPA.
- PostgreSQL, Redis, Horizon, Docker Compose, 1 VM.
- Tenancy single-database con `tenant_id` explícito.
- WhatsApp Cloud API como canal oficial.
- Runtime propio con OpenAI `gpt-5.1`.
- Tool Registry code-only.
- `conversation_state` como snapshot único.
- Data source inicial: Excel solamente.
- Vertical Excel MVP: inventario y conocimiento documental.
- Set obligatorio de tools MVP: `create_lead`, `save_customer_data`, `handoff_to_human`, `search_inventory`, `search_knowledge`.
- Non-text inbound persistido como `unsupported`.
- Fallo de runtime con fallback a `HUMAN_HANDOFF`.
- Manual reply del operador: solo texto.
- `auth/session` mínima de SPA disponible desde la Fase 7.

## Definición de done global del MVP

El MVP se considera listo cuando:

- existe un tenant configurable con una línea de WhatsApp operativa;
- el webhook recibe, persiste, deduplica y procesa mensajes inbound;
- el runtime puede responder o derivar a handoff con trazabilidad completa;
- una fuente Excel puede cargarse, indexarse e inyectar contexto al agente vía tools;
- un operador puede tomar la conversación, responder manualmente y devolver control al bot;
- la plataforma corre en Docker Compose con observabilidad básica, colas y cifrado de secretos.

## Reglas de ejecución para Codex

- Ejecutar las fases en orden; no adelantar trabajo de una fase futura salvo scaffolding indispensable.
- Limitar cada fase a 1 o 2 dimensiones principales.
- Usar el documento de la fase como fuente principal; consultar la arquitectura base solo para detalles puntuales.
- No reinterpretar decisiones cerradas del índice salvo instrucción explícita.
- Mantener cambios dentro de las carpetas listadas por la fase.
- Validar cada fase con checks o tests concretos antes de cerrarla.

## Tabla de fases

| Fase | Archivo | Objetivo principal | Dimensiones | Depende de | Estado |
| --- | --- | --- | --- | --- | --- |
| 0 | `docs/phases/phase_0_foundation_and_repo_bootstrap.md` | Bootstrap del repo y stack base | Infra + scaffolding | Ninguna | Pendiente |
| 1 | `docs/phases/phase_1_core_domain_and_multitenancy.md` | Dominio base y tenancy | Dominio + backend | Fase 0 | Pendiente |
| 2 | `docs/phases/phase_2_whatsapp_gateway_and_message_lifecycle.md` | Gateway y ciclo de mensaje | Integración + backend | Fase 1 | Pendiente |
| 3 | `docs/phases/phase_3_conversations_state_and_concurrency.md` | Estado conversacional y locking | Dominio + backend | Fase 2 | Pendiente |
| 4 | `docs/phases/phase_4_agent_runtime_and_llm_integration.md` | Runtime y LLM | Runtime + backend | Fase 3 | Pendiente |
| 5 | `docs/phases/phase_5_tools_registry_and_execution_logging.md` | Tools base y trazabilidad | Runtime + backend | Fase 4 | Pendiente |
| 6 | `docs/phases/phase_6_excel_document_retrieval_and_indexing.md` | Excel y retrieval documental | Integración + datos | Fase 5 | Pendiente |
| 7 | `docs/phases/phase_7_handoff_internal_console_and_manual_reply.md` | Consola operativa autenticada, handoff y reply manual | UI + backend | Fase 6 | Pendiente |
| 8 | `docs/phases/phase_8_admin_panel_and_tenant_configuration.md` | Panel admin y configuración | UI + backend | Fase 7 | Pendiente |
| 9 | `docs/phases/phase_9_observability_security_and_production_hardening.md` | Hardening de producción | Infra + seguridad | Fase 8 | Pendiente |

## Relación con las macro-fases del master plan

- `Foundation` corresponde a Fases 0 y 1.
- `WhatsApp Core` corresponde a Fases 2 y 3.
- `Agent Slice` corresponde a Fases 4 y 5.
- `Excel Slice` corresponde a Fase 6.
- `Operations UI` corresponde a Fases 7 y 8.
- `Production Hardening` corresponde a Fase 9.

## Orden oficial de ejecución

1. [phase_0_foundation_and_repo_bootstrap.md](./phases/phase_0_foundation_and_repo_bootstrap.md)
2. [phase_1_core_domain_and_multitenancy.md](./phases/phase_1_core_domain_and_multitenancy.md)
3. [phase_2_whatsapp_gateway_and_message_lifecycle.md](./phases/phase_2_whatsapp_gateway_and_message_lifecycle.md)
4. [phase_3_conversations_state_and_concurrency.md](./phases/phase_3_conversations_state_and_concurrency.md)
5. [phase_4_agent_runtime_and_llm_integration.md](./phases/phase_4_agent_runtime_and_llm_integration.md)
6. [phase_5_tools_registry_and_execution_logging.md](./phases/phase_5_tools_registry_and_execution_logging.md)
7. [phase_6_excel_document_retrieval_and_indexing.md](./phases/phase_6_excel_document_retrieval_and_indexing.md)
8. [phase_7_handoff_internal_console_and_manual_reply.md](./phases/phase_7_handoff_internal_console_and_manual_reply.md)
9. [phase_8_admin_panel_and_tenant_configuration.md](./phases/phase_8_admin_panel_and_tenant_configuration.md)
10. [phase_9_observability_security_and_production_hardening.md](./phases/phase_9_observability_security_and_production_hardening.md)

## Cómo usar estos documentos

- Abrir primero este índice.
- Abrir solo la fase vigente y, como máximo, una fase previa de dependencia.
- Usar el `Prompt sugerido para Codex` al final de cada fase como punto de partida.
- Si una fase excede el contexto razonable, subdividirla en `a` y `b` manteniendo el mismo formato.
- No asumir que Fase 8 introduce la primera autenticación de SPA; Fase 7 ya debe incluir `auth/session` mínima para la consola operativa.

## Referencias

- Arquitectura base: [whatsapp_automation_mvp_architecture.md](./whatsapp_automation_mvp_architecture.md)
- Plan consolidado: [master_plan.md](./master_plan.md)
- Fases operativas: `docs/phases/*.md`
