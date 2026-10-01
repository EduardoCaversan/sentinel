#!/bin/sh
set -eu
if [ -z "${APP_KEY:-}" ] && [ -f storage/app.key ]; then
    export APP_KEY="$(cat storage/app.key)"
fi
exec "$@"
