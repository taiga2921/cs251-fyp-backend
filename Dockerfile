FROM serversideup/php:8.3-fpm-nginx-alpine

WORKDIR /var/www/html

COPY . .

USER root

RUN composer install --no-dev --optimize-autoloader

RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

COPY start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

USER www-data

EXPOSE 8080

CMD ["/usr/local/bin/start.sh"]