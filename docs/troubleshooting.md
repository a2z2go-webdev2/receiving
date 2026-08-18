# Troubleshooting

Assets not loading:

- Run `npm run build`.
- Confirm `public/build` exists after build.
- Check that `APP_URL` is correct.

Database connection errors:

- Check `DB_CONNECTION`, host, port, database, username, and password.
- Confirm the database exists.
- Confirm the PHP database extension is enabled.

Queues not processing:

- Confirm `QUEUE_CONNECTION`.
- Start `php artisan queue:work --queue=otp --tries=3 --timeout=60 --sleep=1` locally for verification email.
- Start `php artisan queue:work --queue=receiving,ai,default --tries=3 --timeout=300` locally for receiving workloads.
- Configure workers in production.

HTTPS provider requests fail with `cURL error 60` on Windows:

- Point both `curl.cainfo` and `openssl.cafile` in the loaded `php.ini` to a maintained CA bundle.
- Restart the web server and queue workers after changing `php.ini`.
- Keep TLS verification enabled; do not solve certificate errors with `verify=false`.

Inertia page errors:

- Check controller page names against files in `resources/js/pages`.
- Run `npm run typecheck`.
