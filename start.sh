#!/usr/bin/env sh

echo "Clearing old Laravel cache..."
php artisan optimize:clear

echo "Running Laravel migration..."
php artisan migrate --force

echo "Running Laravel seeder..."
php artisan db:seed --force

echo "Caching Laravel config..."
php artisan optimize

echo "Starting server..."
exec /init