#!/bin/sh
set -eu

export PORT="${PORT:-8080}"
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
php artisan migrate --force

if [ ! -e public/storage ] && [ ! -L public/storage ]; then
    php artisan storage:link
fi

# Artisan can create cache files as root during initialization.
chown -R www-data:www-data storage bootstrap/cache
nginx -t
php-fpm -t
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
