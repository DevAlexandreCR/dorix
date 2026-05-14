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

- `Domain/` — Reserved namespaces for domain logic: `Agent`, `AgentSandbox`, `Conversations`, `DataSources`, `Tools`, `Tenancy`, `Automation`, `WhatsApp`
- `Http/Controllers/Api/` — API controllers versioned under `/api/v1`
- `Models/` — Eloquent models with multi-tenant concerns
- `Support/` — Cross-cutting helpers: `Auth`, `Tenancy`, `Observability`, `AgentEvents`, `Audit`
- `Jobs/` — Queueable jobs (processed by Redis queue / Horizon)

Key infrastructure:
- **Auth**: Laravel Sanctum (token + session-based)
- **Multi-tenancy**: Middleware injects tenant context from each request
- **Queue**: Redis + Laravel Horizon (dashboard at Horizon service)
- **Testing DB**: SQLite in-memory (configured in `phpunit.xml`)

### Frontend (`frontend/src/`)

- `modules/` — Feature modules: `auth`, `admin`, `operations`, `sandbox`, `automation`
- `components/` — Shared UI and shell components
- `layouts/` — `AuthLayout`, `WorkspaceLayout`
- `composables/` — Shared Vue composables (`useSession`, `useLocale`, `useTheme`, `useTenantSelection`, `useNavigationAccess`)
- `lib/api/` — API client helpers
- `i18n/` — Spanish (Colombia, primary) / English (fallback)
- Tailwind CSS 4, Vue Router, Vue i18n

Vite dev server proxies `/api` to the backend container.

### API Structure

All authenticated routes live under `/api/v1`. Public routes: `GET/POST /api/webhooks/meta/whatsapp`, `GET /api/health`.

Tenant-scoped routes require tenant context resolved by middleware. Admin routes split into global (`/v1/admin/tenants`) and tenant-scoped (everything else under `/v1/admin`).

## Conventions

- New API routes go in `backend/routes/api.php`; controllers in `backend/app/Http/Controllers/Api`
- New domain logic goes in the appropriate `backend/app/Domain/<Subdomain>` namespace
- Tenancy helpers live in `backend/app/Support/Tenancy`
- New frontend features go under `frontend/src/modules/<feature>`
- Keep strict separation between backend API and frontend SPA — no SSR, no Blade views for the SPA

## Minimum Validation Checklist

Before closing any work:
1. `docker compose up --build` succeeds without manual workarounds
2. `http://localhost:8080/api/health` responds
3. `http://localhost:5173` loads the frontend shell
