FROM php:8.2-fpm

WORKDIR /app

RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpq-dev \
    zlib1g-dev \
    libzip-dev \
    nginx \
    && docker-php-ext-install pdo pdo_mysql zip

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

COPY . .

RUN composer install --no-dev --optimize-autoloader

RUN chown -R www-data:www-data /app && chmod -R 755 /app/storage /app/bootstrap/cache

# Configure PHP-FPM to listen on Unix socket instead of TCP
RUN sed -i 's|listen = 9000|listen = /run/php-fpm.sock|g' /usr/local/etc/php-fpm.d/www.conf && \
    sed -i 's|;listen.owner = www-data|listen.owner = www-data|g' /usr/local/etc/php-fpm.d/www.conf && \
    sed -i 's|;listen.group = www-data|listen.group = www-data|g' /usr/local/etc/php-fpm.d/www.conf && \
    sed -i 's|;listen.mode = 0660|listen.mode = 0660|g' /usr/local/etc/php-fpm.d/www.conf

# Configure Nginx to serve Laravel via PHP-FPM Unix socket
RUN mkdir -p /etc/nginx/sites-available && \
    echo 'server { \
    listen 80; \
    server_name _; \
    root /app/public; \
    index index.php; \
    location / { \
        try_files $uri $uri/ /index.php?$query_string; \
    } \
    location ~ \.php$ { \
        fastcgi_pass unix:/run/php-fpm.sock; \
        fastcgi_index index.php; \
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name; \
        include fastcgi_params; \
    } \
}' > /etc/nginx/sites-available/default

EXPOSE 80

CMD ["sh", "-c", "php-fpm -D && nginx -g 'daemon off;'"]
