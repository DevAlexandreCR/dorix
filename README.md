# Dorix

Bootstrap inicial del MVP como monorepo con:

- `backend/`: Laravel API separada
- `frontend/`: Vue 3 + TypeScript SPA
- `infra/`: Docker Compose, Nginx y contenedores de desarrollo

## Requisitos

- Docker
- Docker Compose

## Arranque local

Todos los comandos del proyecto están pensados para ejecutarse en Docker.

Variables de entorno:

- Backend: editar `backend/.env`
- Frontend: editar `frontend/.env`

```bash
docker compose up --build
```

Servicios expuestos:

- Frontend: `http://localhost:5173`
- Backend API: `http://localhost:8080`
- Backend health: `http://localhost:8080/api/health`
- API meta: `http://localhost:8080/api/v1/meta`
- PostgreSQL: `localhost:5432`
- Redis: `localhost:6379`

## Comandos útiles

```bash
docker compose exec php-fpm php artisan test
docker compose exec php-fpm php artisan route:list
docker compose exec php-fpm composer install
docker compose exec queue-worker php artisan queue:work --tries=3 --backoff=5 --timeout=120
docker compose exec frontend npm run build
docker compose logs -f php-fpm nginx frontend queue-worker horizon
docker compose down -v
```

## Convenciones base

- Backend separado de frontend. No mezclar la SPA dentro de Laravel.
- Rutas HTTP de plataforma en `backend/routes/api.php`.
- Versionado API inicial bajo `/api/v1`.
- Auth base elegida: Sanctum.
- Cola y observabilidad base: Redis + Horizon.
- Estructura placeholder de dominio en `backend/app/Domain`.
- Estructura funcional del frontend en `frontend/src/modules`.

## Estado de la fase 0

- Stack Docker local con `nginx`, `php-fpm`, `queue-worker`, `horizon`, `postgres`, `redis`, `frontend`.
- Health endpoint simple en backend.
- Shell mínimo de frontend que consulta el backend.
- Documentación Docker-first para continuar fases 1 a 9.
