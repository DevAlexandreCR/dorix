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

## Integración Google Calendar (agendamiento)

Las tools de agendamiento del agente (`check_availability`, `create_appointment`)
usan la API de Google Calendar. Se requiere un proyecto en Google Cloud con un
OAuth client (tipo "Web application"):

- Scopes solicitados: `https://www.googleapis.com/auth/calendar.events` y
  `https://www.googleapis.com/auth/calendar.freebusy`.
- Redirect URI autorizado en el OAuth client: el valor de
  `GOOGLE_CALENDAR_REDIRECT_URI` (por defecto
  `http://localhost:8080/api/oauth/google/callback`).
- Variables en `backend/.env`: `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`,
  `GOOGLE_CALENDAR_REDIRECT_URI`, `FRONTEND_URL` (a donde se redirige tras el
  callback, con éxito o error). `GOOGLE_CALENDAR_BASE_URL`,
  `GOOGLE_OAUTH_TOKEN_URL` y `GOOGLE_OAUTH_AUTHORIZE_URL` son opcionales, con
  default a los endpoints públicos de Google.

**Bloqueante de release, no de desarrollo:** los scopes de Calendar son
"sensitive" en Google. Mientras el OAuth client esté en modo testing, los
refresh tokens emitidos expiran a los 7 días — inservible en producción. Antes
de salir a producción hay que completar la verificación del OAuth client con
Google (trámite de semanas; conviene iniciarlo en paralelo al desarrollo). En
desarrollo y en el sandbox del agente esto no bloquea nada (se usa un cliente
fake).

### Configuración de horarios de agendamiento (gap conocido)

El cómputo de disponibilidad (`SchedulingConfigResolver`) lee calendar id,
timezone y horario de atención desde `overrides` de `tenant_tool_configs` para
el tool `check_availability` (con fallback línea → tenant; ambas tools de
agendamiento comparten esta misma config). Hoy no existe UI de admin para
editar `overrides` y `AdminToolConfigController::persistConfig()` no lo
persiste, así que hay que sembrarlo manualmente (tinker o seeder) con esta
forma:

```json
{
  "calendar_id": "primary",
  "timezone": "America/Bogota",
  "business_hours": {
    "monday": [{"start": "09:00", "end": "13:00"}, {"start": "14:00", "end": "18:00"}],
    "sunday": []
  }
}
```

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
