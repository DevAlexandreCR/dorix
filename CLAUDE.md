# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

Dorix is a WhatsApp business automation platform. The repo is a **monorepo** with three areas:

- `backend/` — Laravel 13 API (PHP 8.3+)
- `frontend/` — Vue 3 + TypeScript SPA
- `infra/` — Docker Compose, Dockerfiles, Nginx

## Core Rule: Docker-first

**All project commands run inside Docker, not on the host.** Do not assume `php`, `composer`, `npm`, or `artisan` are available locally.

- Backend env: `backend/.env`
- Frontend env: `frontend/.env`

## Commands

### Stack

```bash
docker compose up --build          # Start full stack
docker compose down -v             # Stop and remove volumes
docker compose logs -f php-fpm nginx frontend queue-worker horizon postgres redis
```

URLs when running:
- Frontend: `http://localhost:5173`
- Backend API: `http://localhost:8080/api`
- Health check: `http://localhost:8080/api/health`

### Backend (run inside `php-fpm` container)

```bash
docker compose exec php-fpm php artisan test                                  # All tests
docker compose exec php-fpm php artisan test tests/Feature/Agent/AgentSandboxTest.php  # Single file
docker compose exec php-fpm php artisan test --filter=testMethodName          # Single test
docker compose exec php-fpm php artisan migrate
docker compose exec php-fpm php artisan route:list
docker compose exec php-fpm composer install
docker compose exec horizon php artisan horizon:status
```

Frontend type checking:

```bash
docker compose exec frontend npm run typecheck   # vue-tsc, no emit
docker compose exec frontend npm run build       # TypeScript + Vite bundle
```

## Architecture

### Backend (`backend/app/`)

Domain-driven design with strict layer separation:

- `Domain/` — Core business logic, organized by subdomain (see below)
- `Http/Controllers/Api/` — Thin controllers; all logic lives in Domain services
- `Models/` — Eloquent models; most extend `TenantScopedModel` and use the `HasTenantScope` concern
- `Support/` — Cross-cutting infrastructure: `Auth`, `Tenancy`, `Observability`, `AgentEvents`, `Audit`
- `Jobs/` — Queueable jobs on the `messages` queue (processed by Redis / Horizon)
- `Enums/` — `Permission`, `TenantRole` (`tenant_admin`, `operator`, `viewer`), `ConversationStatus`, `ConversationSource`, `PlatformRole`

**Testing DB**: SQLite in-memory (configured in `phpunit.xml`). Queue uses `array` driver in tests.

### Incoming message pipeline

WhatsApp webhook → `MetaWhatsAppWebhookHandler` → `ProcessIncomingMessageJob` (queue: `messages`, 3 retries, 120s timeout) → `AgentContextLoader` builds `AgentContext` → `AgentRuntime` calls `LlmProviderInterface` → `AgentDecisionApplier` dispatches the decision outcome.

`AgentDecisionOutcome` values: `SendMessage`, `RequestMissingInformation`, `WaitForCustomer`, `NoReply`, `RequestHandoff`.

### Agent domain (`Domain/Agent/`)

| Class | Role |
|---|---|
| `AgentContextLoader` | Assembles `AgentContext` from DB: line, tenant, configs, last 12 messages, enabled tools, resolved model |
| `AgentRuntime` | Calls LLM, records events, returns `AgentDecision` |
| `AgentDecisionPolicy` | Validates/normalizes raw LLM decision; selects active `AgentPack` |
| `AgentDecisionApplier` | Executes decision: sends reply, triggers tool, requests handoff, or waits |
| `AgentModelCatalog` | Maps model keys (`balanced`, `high_accuracy`, `savings`) to actual model IDs; resolves effective model from line/tenant configs |
| `AgentPack` / `AgentPackRegistry` | Behavior packs that define available intents; intent lookup normalizes to snake_case |
| `PromptBuilder` | Builds the prompt passed to the LLM |
| `OpenAIResponsesLlmProvider` | Concrete `LlmProviderInterface` implementation |

### Tools domain (`Domain/Tools/`)

All tools implement `ToolInterface` with `definition(): ToolDefinition` and `execute(ToolInvocation): ToolResult`.

Built-in tools: `SearchKnowledgeTool`, `SearchInventoryTool`, `CreateLeadTool`, `SaveCustomerDataTool`, `HandoffToHumanTool`.

`ToolRegistry` resolves which tools are enabled per conversation/line. `ToolExecutionRunner` runs them inside `AgentDecisionApplier`.

### Multi-tenancy

Every Eloquent model that is tenant-scoped extends `TenantScopedModel` and uses `HasTenantScope`. Middleware resolves tenant context from the request into `TenantContext` (via `RequestTenantContextResolver`) and stores it in `TenantContextManager`. Queue jobs that need a tenant context implement `TenantAwareJob` and use the `RunsInTenantContext` trait. Never query tenant-scoped models without the tenant scope applied.

### Auth & Permissions

Laravel Sanctum (session + cookie). Gates defined via `Permission` enum. Roles: `PlatformRole` (super-admin) and `TenantRole` (`tenant_admin`, `operator`, `viewer`).

### Frontend (`frontend/src/`)

| Path | Purpose |
|---|---|
| `modules/auth` | Login flow |
| `modules/operations` | Conversation list and manual reply UI |
| `modules/sandbox` | Agent sandbox (test conversations without real WhatsApp) |
| `modules/admin` | Tenant admin panel: users, WhatsApp lines, agent configs, tool configs, credentials |
| `modules/automation` | Placeholder for future automation rules |
| `composables/` | `useSession`, `useLocale`, `useTheme`, `useTenantSelection`, `useNavigationAccess`, `useShellLayout` |
| `lib/api/` | `requestJson` / typed helpers (`getJson`, `postJson`, `patchJson`, `deleteJson`, `postForm`); handles CSRF cookie and `ApiError` |
| `i18n/` | Spanish Colombia (`es_CO`) primary, English fallback |

Router: `WorkspaceLayout` wraps authenticated routes (`/operations`, `/sandbox`, `/admin`). `AuthLayout` wraps `/login`. Guards enforce `requiresAuth` and `guestOnly` meta.

Vite dev server proxies `/api` to the backend container.

### API Structure

All authenticated routes live under `/api/v1` with `web` + `auth:sanctum` + `tenant.context` middleware. Public routes: `GET/POST /api/webhooks/meta/whatsapp`, `GET /api/health`.

Admin routes split into global (`/v1/admin/tenants`) and tenant-scoped (everything else under `/v1/admin`).

## Conventions

- New API routes go in `backend/routes/api.php`; controllers in `backend/app/Http/Controllers/Api`
- New domain logic goes in the appropriate `backend/app/Domain/<Subdomain>` namespace
- Tenancy helpers live in `backend/app/Support/Tenancy`
- New frontend features go under `frontend/src/modules/<feature>`
- Each module exposes an `api.ts` (API calls), `types.ts` (TypeScript types), and `views/` directory
- Keep strict separation between backend API and frontend SPA — no SSR, no Blade views for the SPA
- Agent events are recorded via `AgentEventRecorder` (not direct DB writes); audit trail via `AuditEventRecorder`

## Minimum Validation Checklist

Before closing any work:
1. `docker compose up --build` succeeds without manual workarounds
2. `http://localhost:8080/api/health` responds
3. `http://localhost:5173` loads the frontend shell
