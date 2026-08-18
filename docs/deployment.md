# Deployment

Build:

```powershell
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Deploy:

```powershell
php artisan receiving:check-production
php artisan migrate --force
```

Required runtime processes:

1. Web application.
2. Queue workers consuming `receiving,ai,default` with a timeout above the Gemini request timeout.
3. Laravel scheduler for hourly abandoned-staging cleanup.
4. Outbound HTTPS access to Cloudmersive; provider loss or exhausted allowance intentionally blocks file promotion while file jobs retry safely.

Release checks:

- The R2 bucket is private, CORS is restricted to deployed origins, and presigned PUT/GET probes pass.
- `RECEIVING_PROXY_UPLOADS=false` is enabled only after the real-origin presigned PUT probe succeeds; `true` is the rollback switch.
- Cloudmersive rejects EICAR, clean files pass, parallel files remain serialized, and the portal usage count matches the application's monthly ledger.
- Gemini returns parseable JSON for a representative invoice.
- The queue and scheduler are running and observable.
- The queue backend retry/visibility lease is greater than `RECEIVING_WORKER_TIMEOUT_SECONDS` (300 seconds by default).
- Mail reaches each To/CC/BCC path and secure transaction/review links expire correctly.
- `APP_DEBUG=false`, sessions are encrypted, TLS is enforced, and cookies are Secure/HTTP-only/SameSite.
- Database backups and an R2 retention policy are approved before real document retention begins.

Use progressive rollout with a non-production R2 bucket first. Roll back the application release if clean uploads cannot complete; do not bypass validation or scanning to keep traffic moving.
