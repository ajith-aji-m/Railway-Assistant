#!/bin/sh
set -e

cd /var/www/html

if [ -z "$APP_KEY" ]; then
    echo "APP_KEY is not set. Generate one with: php artisan key:generate --show" >&2
    exit 1
fi

# The storage volume may start empty: recreate Laravel's directory layout.
mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
    storage/framework/views storage/logs

if [ "$DB_CONNECTION" = "sqlite" ]; then
    mkdir -p "$(dirname "$DB_DATABASE")"
    [ -f "$DB_DATABASE" ] || touch "$DB_DATABASE"
fi

chown -R www-data:www-data storage bootstrap/cache

php artisan optimize

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    php artisan migrate --force
fi

# The mock network seeder is idempotent (updateOrCreate), so it is safe on every start.
if [ "${RUN_SEEDERS:-true}" = "true" ]; then
    php artisan db:seed --force
fi

# Files created by the artisan commands above belong to root; hand them to Apache.
chown -R www-data:www-data storage bootstrap/cache

exec "$@"
