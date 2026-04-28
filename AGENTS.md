# AGENTS.md

## Contexto del repo

Este repositorio arranca el MVP como monorepo con tres áreas:

- `backend/`: Laravel 13 API
- `frontend/`: Vue 3 + TypeScript SPA
- `infra/`: Docker Compose, Dockerfiles y Nginx para desarrollo

La fase actual solo cubre foundation/bootstrap. No introducir lógica de dominio, WhatsApp, runtime, Excel ni panel admin fuera de los placeholders ya definidos.

## Regla operativa principal

Ejecuta comandos del proyecto dentro de Docker, no en el host.

No asumas `php`, `composer`, `npm` o `artisan` instalados localmente. Para trabajo cotidiano usa los servicios del `docker-compose.yml`.

Fuente de variables:

- Backend Docker + Laravel comparten `backend/.env`.
- Frontend Docker + Vite comparten `frontend/.env`.

## Comandos Docker de referencia

Levantar el stack:

```bash
docker compose up --build
```

Bajar el stack y limpiar volúmenes:

```bash
docker compose down -v
```

Laravel / backend:

```bash
docker compose exec php-fpm php artisan route:list
docker compose exec php-fpm php artisan test
docker compose exec php-fpm composer install
docker compose exec php-fpm php artisan migrate
docker compose exec horizon php artisan horizon:status
```

Frontend:

```bash
docker compose exec frontend npm install
docker compose exec frontend npm run dev -- --host 0.0.0.0 --port 5173
docker compose exec frontend npm run build
```

Logs:

```bash
docker compose logs -f php-fpm nginx frontend queue-worker horizon postgres redis
```

## Convenciones para cambios futuros

- API HTTP en `backend/routes/api.php` y controladores en `backend/app/Http/Controllers/Api`.
- Namespaces de dominio reservados en `backend/app/Domain`.
- Helpers de tenancy reservados en `backend/app/Support/Tenancy`.
- Módulos funcionales del frontend bajo `frontend/src/modules`.
- Mantener separación estricta entre backend API y SPA.

## Validaciones mínimas al cerrar trabajo

- `docker compose up --build` levanta sin workarounds manuales.
- `http://localhost:8080/api/health` responde.
- `http://localhost:5173` carga el shell del frontend.
