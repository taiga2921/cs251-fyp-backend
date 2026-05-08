#!/usr/bin/env bash

echo "Running composer..."
composer install --no-dev --optimize-autoloader

echo "Clearing old cache..."
php artisan optimize:clear

echo "Running migrations..."
php artisan migrate --force

echo "Running database seeders..."
php artisan db:seed --force

echo "Caching config..."
php artisan config:cache

echo "Caching routes..."
php artisan route:cache