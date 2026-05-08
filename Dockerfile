FROM serversideup/php:8.3-fpm-nginx

USER root

# App/runtime environment
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    WEBROOT=/var/www/html/public \
    PHP_ERRORS_STDERR=1 \
    RUN_SCRIPTS=1 \
    AUTORUN_ENABLED=false \
    SKIP_COMPOSER=1 \
    COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /var/www/html

# Copy Composer manifests first for better layer caching
COPY --chown=www-data:www-data composer.json composer.lock ./

# Install PHP deps exactly from lock (no dev)
USER www-data
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader

# Copy application code
USER root
COPY --chown=www-data:www-data . .
COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf

# Writable Laravel dirs
RUN mkdir -p storage bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    && chmod +x scripts/00-laravel-deploy.sh

USER www-data

EXPOSE 8080

# Start long-running web services in foreground for production.
# Resolve PHP-FPM binary name across image variants before launching Nginx.
# Run migrations/seed/cache in Render pre-deploy step using scripts/00-laravel-deploy.sh.
CMD ["sh", "-lc", "PHP_FPM_BIN=\"$(command -v php-fpm || command -v php-fpm8.3 || command -v php-fpm83)\" && [ -n \"$PHP_FPM_BIN\" ] && \"$PHP_FPM_BIN\" -D && nginx -g 'daemon off;'"]