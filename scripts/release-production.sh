#!/bin/bash
set -euo pipefail

php artisan receiving:check-production
php artisan migrate --force
