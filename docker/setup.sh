#!/bin/sh
set -eu
if [ -z "${APP_KEY:-}" ]; then
    if [ "${APP_ENV:-local}" != local ]; then
        echo 'APP_KEY must be supplied outside local development.' >&2
        exit 1
    fi
    if [ ! -s storage/app.key ]; then
        php artisan key:generate --show > storage/app.key
        chmod 640 storage/app.key
    fi
    export APP_KEY="$(cat storage/app.key)"
fi
php artisan migrate --force
if [ "${DEMO_SEED:-false}" = true ]; then
    php artisan db:seed --force
fi
chown -R www-data:www-data storage bootstrap/cache
