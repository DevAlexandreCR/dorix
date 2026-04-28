# Fase 0 - Foundation and Repo Bootstrap

## Objetivo

Crear el esqueleto operativo del proyecto para que el equipo pueda desarrollar el MVP en un monorepo estable y arrancable localmente.

## Scope incluido

- Estructura `backend/`, `frontend/`, `infra/`.
- Bootstrap de Laravel API.
- Bootstrap de Vue 3 + TypeScript SPA.
- Docker Compose base para desarrollo local.
- Convenciones de entorno, `.env.example`, scripts de arranque y README técnico inicial.
- Decisiones base de auth, tenancy, servicios y naming.

## Fuera de scope

- Lógica de negocio de tenants, WhatsApp, runtime o handoff.
- Integraciones externas reales.
- UI de producto más allá del shell mínimo.

## Precondiciones

- Leer [implementation_plan_index.md](/Users/alexandrecr/devs/gorda/auto/docs/implementation_plan_index.md).
- Tomar [whatsapp_automation_mvp_architecture.md](/Users/alexandrecr/devs/gorda/auto/docs/whatsapp_automation_mvp_architecture.md) como baseline técnica.

## Decisiones cerradas

- Monorepo único.
- Laravel API separado de la SPA Vue.
- Auth con Sanctum.
- PostgreSQL, Redis y Horizon en el stack base.
- Target de despliegue inicial: 1 VM con Docker Compose.

## Entregables

- Estructura de carpetas definida.
- Proyecto Laravel inicializado y arrancable.
- Proyecto Vue inicializado y arrancable.
- `docker-compose.yml` y archivos de soporte en `infra/`.
- README con pasos de arranque local.

## Cambios técnicos esperados

- Crear `backend/`, `frontend/`, `infra/`.
- Configurar servicios Docker mínimos: `nginx`, `php-fpm`, `frontend`, `postgres`, `redis`, `queue-worker`, `horizon`.
- Definir variables de entorno base para app, DB, Redis y frontend.
- Dejar placeholder para módulos de dominio futuros sin implementarlos todavía.

## Interfaces o contratos a definir

- Contrato de comunicación frontend -> backend.
- Estructura de rutas API base.
- Convención de namespaces para dominios en backend.
- Convención de módulos o carpetas funcionales en frontend.

## Riesgos y validaciones

- Evitar mezclar frontend dentro del app Laravel.
- Evitar Docker demasiado acoplado a producción final.
- Validar que todos los servicios levanten sin workarounds manuales.

## Checklist de implementación

- Crear estructura monorepo.
- Inicializar Laravel en `backend/`.
- Inicializar Vue 3 + TypeScript en `frontend/`.
- Configurar Docker Compose base.
- Configurar Nginx y networking interno.
- Agregar `.env.example` para backend y frontend.
- Documentar comandos de arranque.
- Verificar que frontend y backend respondan localmente.

## Criterio de done

- `docker compose up` levanta el stack base.
- Backend responde health endpoint simple.
- Frontend carga su shell inicial.
- La estructura del repo queda lista para fases 1 a 9.

## Prompt sugerido para Codex

```text
Implementa solo la Fase 0 usando:
- docs/implementation_plan_index.md
- docs/phases/phase_0_foundation_and_repo_bootstrap.md
- docs/whatsapp_automation_mvp_architecture.md

Trabaja únicamente en:
- backend/
- frontend/
- infra/
- README o docs mínimos de arranque

No implementes dominio, WhatsApp, runtime, tools, Excel ni panel admin.
Explora primero el repo y luego crea el scaffolding mínimo del monorepo.
Valida con checks concretos de arranque local y reporta cualquier bloqueo estructural.
```
