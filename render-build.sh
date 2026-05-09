#!/usr/bin/env bash
set -e

composer install --no-dev --optimize-autoloader

php artisan config:cache
php artisan route:cache
php artisan view:cache

# Run migrations + seed on every deploy
php artisan migrate --force
php artisan db:seed --force