#!/bin/sh

set -eu

cd /var/www/html/frontend

if [ ! -f package-lock.json ] || [ ! -x node_modules/.bin/vite ]; then
  npm install
fi

exec npm run dev -- --host 0.0.0.0 --port 5173
