#!/bin/sh

set -eu

cd /var/www/html/backend

if [ ! -f composer.lock ] || [ ! -f vendor/autoload.php ]; then
  composer install --no-interaction --prefer-dist
fi

