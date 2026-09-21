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
        fastcgi_pass 127.0.0.1:9000; \
        fastcgi_index index.php; \
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name; \
        include fastcgi_params; \
    } \
}' > /etc/nginx/sites-available/default

EXPOSE 80

CMD ["sh", "-c", "php-fpm -D && nginx -g 'daemon off;'"]
