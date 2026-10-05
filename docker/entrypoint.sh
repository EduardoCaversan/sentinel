#!/bin/sh
set -eu
if [ "${APP_ENV:-production}" = local ] && [ -z "${APP_KEY:-}" ] && [ -f storage/app.key ]; then
    export APP_KEY="$(cat storage/app.key)"
fi
if [ "${APP_ENV:-production}" != local ] && [ -z "${APP_KEY:-}" ]; then
    echo 'APP_KEY must be supplied outside local development.' >&2
    exit 1
fi
exec "$@"
