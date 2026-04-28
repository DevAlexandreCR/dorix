# Plan de Implementación MVP - Plataforma Multi-Tenant de Automatización por WhatsApp

## Resumen

Arrancar el proyecto como monorepo con `backend/` (Laravel API), `frontend/` (Vue 3 + TypeScript SPA) e `infra/` (Docker Compose para 1 VM), priorizando un vertical slice funcional antes de ampliar integraciones.  
El MVP inicial queda limitado a WhatsApp Cloud API + runtime propio + handoff interno + fuente de datos Excel. No se implementan API genérica, adapters de base de datos cliente, templates manuales, builder visual ni UI operativa de dead letters en esta primera fase.

Arquitectura base ya cerrada:

- Backend: Laravel API con Sanctum, PostgreSQL, Redis, Horizon y colas asíncronas.
- Frontend: SPA Vue 3 + TypeScript desacoplada.
- Tenancy: single database multi-tenant con `tenant_id` obligatorio en tablas de negocio y en jobs.
- Runtime: provider baseline OpenAI con `gpt-5.1`, Tool Registry code-only y `conversation_state` como snapshot único por conversación.
- Despliegue: 1 VM con Docker Compose, Nginx, SSL, Redis, Postgres, worker, Horizon y almacenamiento persistente en disco local.
- Operación: non-text inbound se guarda como `unsupported`; fallos de runtime derivan a `HUMAN_HANDOFF`; retries base `3` con backoff exponencial.

## Cambios de Arquitectura e Implementación

### 1. Bootstrap de plataforma

- Crear estructura monorepo:
  - `backend/` Laravel API
  - `frontend/` Vue SPA
  - `infra/` Docker Compose, Nginx, SSL, procesos y volúmenes
- Configurar Docker local/prod con servicios mínimos:
  - `nginx`, `php-fpm`, `queue-worker`, `horizon`, `postgres`, `redis`, `frontend`
- Montar autenticación con Laravel Sanctum para SPA y RBAC inicial con roles:
  - `platform_admin`, `tenant_admin`, `operator`, `viewer`
- Definir middleware y servicios de contexto tenant:
  - resolución explícita de tenant para API interna
  - prohibición de queries de negocio sin `tenant_id`
  - jobs serializados con `tenant_id` explícito

### 2. Dominio, esquema y contratos mínimos

- Implementar migraciones y modelos para:
  - `tenants`, `users`, `tenant_users`
  - `whatsapp_lines`, `api_credentials`, `agent_configs`
  - `conversations`, `conversation_messages`, `conversation_states`
  - `data_sources`, `uploaded_files`
  - `tenant_tool_configs`, `tool_executions`
  - `handoff_requests`, `agent_events`, `audit_events`
- Reglas de esquema obligatorias:
  - `whatsapp_lines.phone_number_id` único global
  - `conversation_states.conversation_id` único
  - dedup inbound con índice único `(tenant_id, provider_message_id)`
  - `waba_id` nullable como metadata operativa, no pivote de dominio
- Modelar `conversation_messages` para soportar:
  - `direction`, `message_type`, `body`, `payload`, `provider_message_id`
  - estados inbound/outbound y callbacks de delivery/error
  - `idempotency_key` explícita en outbound persistida antes del call a Meta
- Estados iniciales de conversación:
  - `BOT_ACTIVE`, `WAITING_CUSTOMER`, `HUMAN_HANDOFF`, `CLOSED`, `ERROR`
- Expiración de estado:
  - al expirar, limpiar solo `memory_summary`; conservar `collected_data`

### 3. Gateway de WhatsApp

- Implementar endpoint único de webhook para:
  - verificación de challenge
  - recepción de inbound
  - recepción de status callbacks
- Flujo inbound obligatorio:
  - validar payload
  - resolver `whatsapp_line` por `phone_number_id`
  - derivar `tenant_id`
  - persistir mensaje/raw payload relevante
  - registrar evento (`message_saved` o `message_deduplicated`)
  - despachar `ProcessIncomingMessageJob`
- Soporte de tipos:
  - texto soportado
  - non-text guardado como `unsupported`, sin rechazo duro ni procesamiento semántico
- Flujo outbound:
  - crear registro local outbound + `idempotency_key`
  - enviar a WhatsApp Cloud API
  - persistir aceptación/rechazo
  - actualizar mismo mensaje con callbacks posteriores
- Concurrencia:
  - lock por conversación antes de ejecutar runtime para evitar respuestas solapadas

### 4. Runtime del agente y tooling

- Implementar contratos internos:
  - `AgentContext`
  - `AgentDecision`
  - `AgentRuntime`
  - `PromptBuilder`
  - `ToolRegistry`
- Contexto mínimo cargado por runtime:
  - tenant, line, conversation, messages recientes, state snapshot, agent config, tools habilitadas
- Outcomes cerrados:
  - `send_message`, `request_missing_information`, `call_tool`, `wait_for_customer`, `request_handoff`, `no_reply`, `error`
- Política de fallo:
  - si falla LLM o tool sin respuesta segura, mover conversación a `HUMAN_HANDOFF`
  - registrar evento, error técnico y contexto
- Prompting:
  - prompt por tenant/line
  - versionado persistido backend-only; sin UI de historial en esta fase
- Tooling:
  - definiciones code-only
  - `tenant_tool_configs` solo para enablement, overrides y bindings
  - registrar toda ejecución en `tool_executions`
- Implementar el set obligatorio de tools del MVP:
  - Fase 5: `create_lead`, `save_customer_data`, `handoff_to_human`
  - Fase 6: `search_inventory`, `search_faq`
- Dejar fuera en esta fase:
  - tool marketplace
  - tool definitions administrables
  - lógica con condicionales por nombre de tenant

### 5. Data sources y Excel

- Separar contratos:
  - `DataSourceReader` con `search()` y `find()`
  - `DataSourceImporter` para importación y sync
- No crear abstracción genérica de API ni DB en la primera implementación.
- Implementar vertical de Excel:
  - upload de archivo
  - validación y persistencia de metadata
  - parser/importador a tablas internas de catálogo y FAQ
  - trazabilidad de importaciones y errores
- Recuperación para runtime:
  - búsqueda estructurada en Postgres sobre tablas normalizadas/importadas para inventario y FAQ
  - sin vector DB ni embeddings en baseline

### 6. Handoff interno y panel admin

- Slice 7: consola operativa autenticada para operadores:
  - login y sesión mínima con Sanctum
  - viewer de conversaciones
  - thread de mensajes
  - manual reply de texto
  - cambio a `HUMAN_HANDOFF`
  - reactivación del bot
- Slice 8: panel admin y configuración:
  - CRUD de tenants
  - CRUD de WhatsApp lines
  - storage seguro de credenciales
  - configuración de prompt/agente
  - toggles de automatización
  - visualización básica de logs/eventos
- Reglas de handoff:
  - mientras conversación esté en `HUMAN_HANDOFF`, bloquear respuestas automáticas
  - `assigned_to_user_id` se usa solo cuando un humano toma la conversación
  - al retomar el bot, limpiar `assigned_to_user_id`
- Credenciales:
  - cifradas en reposo
  - `tenant_admin` ve metadata/estado, pero no rota ni reemplaza secretos

### 7. Infra, seguridad y observabilidad

- Seguridad:
  - cifrado en reposo para tokens y credenciales
  - sanitización de logs para no exponer secretos
  - payloads de soporte solo con campos necesarios
- Observabilidad:
  - persistir al menos el set de eventos definido en el spec
  - usar Horizon como capa principal de observación de colas
- Fiabilidad:
  - `3` retries con backoff exponencial
  - fallo persistente visible por logs/Horizon, sin UI específica en esta fase
  - toda falla termina en estado persistido, nunca silencioso

## APIs, Interfaces y Tipos Públicos a Definir

- API interna SPA:
  - auth/session con Sanctum
  - tenants, tenant users, whatsapp lines, agent configs, data sources, uploads, conversations, handoff, logs
- Webhook público:
  - endpoint de verificación y recepción WhatsApp
- Interfaces internas obligatorias:
  - `AgentRuntimeInterface`
  - `ToolInterface`
  - `DataSourceReader`
  - `DataSourceImporter`
  - `CredentialResolver`
  - `TenantContextResolver`
- Tipos/DTOs base:
  - webhook inbound/status DTOs
  - outbound send DTO
  - runtime input/output DTOs
  - tool input/output DTOs
  - Excel import result DTO

## Fases de Entrega

1. `Foundation`
   - monorepo, Docker Compose, Laravel, Vue SPA, Sanctum, roles, tenancy base, migraciones núcleo
2. `WhatsApp Core`
   - webhook, line resolution, dedup, persistencia de mensajes, outbound sender, callbacks, Horizon, locking
3. `Agent Slice`
   - runtime, provider OpenAI `gpt-5.1`, prompt config, Tool Registry, logs de runtime, fallback a handoff
4. `Excel Slice`
   - upload, parse/import, búsqueda de inventario y FAQ, tools funcionales
5. `Operations UI`
   - slice 7: login/sesión mínima, inbox, thread, manual reply texto, handoff/resume
   - slice 8: panel admin, configuración, credenciales, prompts, toggles y logs básicos
6. `Production Hardening`
   - auditoría, cifrado completo, retries/backoff verificados, observabilidad, smoke tests de despliegue

## Mapeo entre macro-fases y fases operativas

- `Foundation` -> Fases 0 y 1
- `WhatsApp Core` -> Fases 2 y 3
- `Agent Slice` -> Fases 4 y 5
- `Excel Slice` -> Fase 6
- `Operations UI` -> Fases 7 y 8
- `Production Hardening` -> Fase 9

## Plan de Pruebas

- Unit tests:
  - resolución de tenant/line
  - dedup inbound
  - generación y validación de `idempotency_key`
  - transición de estados de conversación
  - política de fallback a handoff
  - adapters/readers/importers
- Feature tests backend:
  - webhook verification
  - inbound text happy path
  - inbound duplicate no reprocesa
  - non-text inbound queda persistido como `unsupported`
  - outbound send + callback status update
  - reintentos idempotentes no duplican reply visible
  - resume de bot limpia `assigned_to_user_id`
- Integration tests:
  - runtime + tools + catálogo Excel
  - locking por conversación con mensajes cercanos
  - permisos por rol y aislamiento multi-tenant
- Acceptance scenarios:
  - alta tenant + line + credenciales + prompt
  - carga Excel y búsqueda desde tool
  - conversación bot end-to-end
  - handoff automático por fallo de runtime
  - reply manual texto y retorno del bot

## Supuestos y decisiones cerradas

- Primera implementación limitada a Excel; API genérica y DB adapters quedan para siguiente milestone.
- Manual replies solo texto; templates manuales quedan fuera.
- Storage inicial de uploads/payloads en disco local persistente.
- Despliegue inicial en 1 VM con Docker Compose.
- Prompt versioning visible solo en backend/configuración.
- Tool definitions no se persisten como catálogo administrable.
- `waba_id` se guarda solo como metadata opcional.
- Nombres vigentes de modelos OpenAI validados en docs oficiales:
  - https://platform.openai.com/docs/models
  - https://platform.openai.com/docs/models/gpt-5.1
