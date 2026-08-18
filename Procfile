web: php artisan serve --host=0.0.0.0 --port=${PORT:-8080}
worker: php artisan queue:work --queue=otp,receiving,ai,default --tries=3 --timeout=300 --sleep=3
