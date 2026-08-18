#!/bin/bash
set -euo pipefail

composer install --no-dev --optimize-autoloader
npm ci
npm run build

php artisan config:cache
php artisan route:cache
php artisan view:cache
