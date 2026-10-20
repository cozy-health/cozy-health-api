#!/bin/sh
set -eu

export DB_QUEUE="${DB_QUEUE:-default}"
export PORT="${PORT:-8080}"
export RUN_QUEUE_WORKER="${RUN_QUEUE_WORKER:-false}"
export RUN_SCHEDULER="${RUN_SCHEDULER:-false}"
case "$RUN_QUEUE_WORKER" in
    true|false) ;;
    *) echo 'RUN_QUEUE_WORKER must be true or false' >&2; exit 1 ;;
esac
case "$RUN_SCHEDULER" in
    true|false) ;;
    *) echo 'RUN_SCHEDULER must be true or false' >&2; exit 1 ;;
esac

# Worker services execute only their requested process; initialization and
# schema migrations belong to the API deployment's pre-deploy command.
if [ "$#" -gt 0 ]; then
    exec "$@"
fi
case "$PORT" in
    ''|*[!0-9]*) echo 'PORT must be a valid TCP port' >&2; exit 1 ;;
esac
if [ "$PORT" -lt 1 ] || [ "$PORT" -gt 65535 ]; then
    echo 'PORT must be between 1 and 65535' >&2
    exit 1
fi

# Substitute only PORT; preserve Nginx's $uri and FastCGI variables.
envsubst '${PORT}' < /etc/nginx/templates/app.conf.template > /etc/nginx/sites-available/default

mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R 755 storage bootstrap/cache

php artisan config:clear
php artisan route:clear
php artisan view:clear

if [ ! -e public/storage ] && [ ! -L public/storage ]; then
    php artisan storage:link
fi

# Artisan can create cache files as root during initialization.
chown -R www-data:www-data storage bootstrap/cache
nginx -t
php-fpm -t
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
