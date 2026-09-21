FROM php:8.2-apache

WORKDIR /app

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpq-dev \
    zlib1g-dev \
    libzip-dev \
    && docker-php-ext-install pdo pdo_mysql zip

# Copy Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy application code
COPY . .

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader

# Set permissions
RUN chown -R www-data:www-data /app && chmod -R 755 /app

# Fix Apache MPM conflict: disable all MPMs, enable only prefork
RUN a2dismod mpm_event mpm_worker || true && \
    a2enmod mpm_prefork rewrite

# Point Apache DocumentRoot to Laravel's public folder
RUN sed -i 's|/var/www/html|/app/public|g' /etc/apache2/sites-available/000-default.conf && \
    sed -i 's|/var/www/html|/app/public|g' /etc/apache2/apache2.conf

# Allow .htaccess overrides
RUN echo '<Directory /app/public>\n    AllowOverride All\n    Require all granted\n</Directory>' \
    > /etc/apache2/conf-available/app.conf && \
    a2enconf app

EXPOSE 80

CMD ["apache2-foreground"]
