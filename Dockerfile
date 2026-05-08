FROM serversideup/php:8.3-fpm-nginx

USER root

COPY . .

ENV SKIP_COMPOSER 0
ENV WEBROOT /var/www/html/public
ENV PHP_ERRORS_STDERR 1
ENV RUN_SCRIPTS 1
ENV AUTORUN_ENABLED false

ENV APP_ENV production
ENV APP_DEBUG false

RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

USER www-data

RUN composer install --no-dev --optimize-autoloader --ignore-platform-req=ext-sodium

RUN php artisan config:cache && \
    php artisan route:cache && \
    php artisan view:cache

EXPOSE 8080

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8080"]