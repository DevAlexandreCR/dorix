# Production Runbook

## Objetivo

Checklist mínimo para validar el MVP en el baseline de `1 VM + Docker Compose` sin exponer secretos ni depender de pasos manuales implícitos.

## Pre-flight

- Confirmar que `backend/.env` y `frontend/.env` estén cargados con valores productivos.
- Confirmar `APP_KEY` de Laravel y `QUEUE_CONNECTION=redis`.
- Confirmar que credenciales sensibles de tenant o línea se administren vía panel y queden cifradas en `api_credentials.secret`.
- Confirmar publicación de puertos esperados:
  - API vía Nginx en `:8080`
  - SPA vía Vite/Nginx del frontend en `:5173`

## Arranque

```bash
docker compose up --build -d
```

## Checks operativos

```bash
docker compose logs -f php-fpm nginx frontend queue-worker horizon postgres redis
docker compose exec horizon php artisan horizon:status
docker compose exec php-fpm php artisan queue:failed
docker compose exec php-fpm php artisan route:list
```

## Smoke tests

```bash
./infra/scripts/smoke-check.sh
curl -fsS http://localhost:8080/api/health
curl -fsS http://localhost:5173
```

El `health` debe responder `status=ok` e incluir checks de:

- `database`
- `cache`
- `queue`
- `horizon`

## Señales mínimas a revisar

- `agent_events` debe registrar:
  - dispatch, start y fallo de `ProcessIncomingMessageJob`
  - queue/start/success/failure de imports Excel
  - resolución de `data_source` y búsquedas de `search_inventory` / `search_knowledge`
- `tool_executions` debe guardar solo resúmenes sanitizados, sin `retrieved_context` completo ni secretos.
- `audit_events` debe mantener metadata operativa sin valores sensibles.
- Horizon debe mostrar jobs taggeados por `tenant`, `conversation`, `message` o `import`.

## Incidentes comunes

- `health.queue.status=failed`
  - revisar `QUEUE_FAILED_DRIVER`
  - validar acceso a Redis y tabla `failed_jobs`
- `health.horizon.status=failed`
  - confirmar `QUEUE_CONNECTION=redis`
  - revisar logs del servicio `horizon`
- importaciones Excel en `failed`
  - revisar `data_source_imports.metadata.failure`
  - reintentar desde panel o `POST /api/v1/data-sources/{id}/imports/{importId}/retry`
- retrieval sin resultados o handoff inesperado
  - revisar `tool_data_source_resolved`
  - revisar `data_source_search_completed` o `data_source_search_unavailable`
  - validar binding de `data_source_id` en `tenant_tool_configs`
