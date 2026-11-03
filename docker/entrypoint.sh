#!/bin/sh
# Prepares Laravel for the running environment, then starts the container's command:
#   apache2-foreground                (web, the default)
#   php artisan queue:work ...        (worker)
#   php artisan schedule:work         (scheduler: billing, penalties, maintenance, loans)
#   php artisan migrate --force       (one-off migration job)
set -e

if [ -z "$APP_KEY" ]; then
    echo "APP_KEY is not set. Generate one with: php artisan key:generate --show" >&2
    exit 1
fi

mkdir -p storage/app/private storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

php artisan optimize
php artisan filament:optimize

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
fi

exec "$@"
