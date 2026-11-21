#!/usr/bin/env bash
set -euo pipefail

: "${APP_KEY:?APP_KEY must be loaded from SSM}"
: "${DB_PASSWORD:?DB_PASSWORD must be loaded from SSM}"

mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/logs

php artisan config:cache --no-ansi

exec "$@"