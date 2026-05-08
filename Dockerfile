FROM richarvey/nginx-php-fpm:3.1.6

COPY . .

ENV SKIP_COMPOSER 0
ENV WEBROOT /var/www/html/public
ENV PHP_ERRORS_STDERR 1
ENV RUN_SCRIPTS 1

# Run migrations during build
RUN composer install --no-dev && \
    php artisan config:cache && \
    php artisan route:cache && \
    php artisan migrate --force

ENV APP_ENV production
ENV APP_DEBUG false

CMD ["/start.sh"]