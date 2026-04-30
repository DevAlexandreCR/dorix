#!/bin/sh

set -eu

API_URL="${API_URL:-http://localhost:8080/api/health}"
FRONTEND_URL="${FRONTEND_URL:-http://localhost:5173}"

docker compose exec php-fpm php artisan route:list >/dev/null
docker compose exec horizon php artisan horizon:status >/dev/null
curl -fsS "$API_URL" >/dev/null
curl -fsS "$FRONTEND_URL" >/dev/null

printf 'Smoke checks passed.\n'
