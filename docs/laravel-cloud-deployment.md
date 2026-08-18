# Deploy Receiving to Laravel Cloud: Beginner Guide

This guide is specific to this repository. It covers the web app, PostgreSQL, private object storage, queue workers, the scheduler, SMTP, Gemini, Cloudmersive malware scanning, initial admin creation, verification, and rollback.

The dashboard labels match Laravel Cloud's official documentation in July 2026. If a label changes slightly, find the equivalent resource on the environment's infrastructure canvas.

## 1. What must be deployed

| Part | Project requirement |
|---|---|
| Web runtime | Laravel 13 on PHP 8.4 |
| Frontend build | React 19/Inertia 3 using Node.js 22 and `npm` |
| Database | PostgreSQL |
| Sessions/cache/queue | PostgreSQL initially |
| Queue names | `otp`, `receiving`, `ai`, `default` |
| Persistent documents | Private Cloudflare R2-compatible bucket, disk name `r2` |
| Email | Production SMTP account |
| AI | Google Gemini API |
| Malware scanning | Cloudmersive Virus Scan API over authenticated HTTPS |
| Scheduler | Hourly abandoned-staging cleanup |
| Health URL | `/up` |

Laravel Cloud filesystems are ephemeral. Do not store uploaded documents on the `local` disk and do not depend on `php artisan storage:link` for persistence.

Official references:

- [Cloud quickstart](https://cloud.laravel.com/docs/quickstart)
- [Environments, runtimes, build and deploy commands](https://cloud.laravel.com/docs/environments)
- [Serverless PostgreSQL](https://cloud.laravel.com/docs/resources/databases/postgres)
- [Laravel Object Storage](https://cloud.laravel.com/docs/resources/object-storage)
- [Queues](https://cloud.laravel.com/docs/queues)
- [Scheduled tasks](https://cloud.laravel.com/docs/scheduled-tasks)

## 2. Cloudmersive free-tier constraints

Do not skip this section.

In production this application sends every validated file to Cloudmersive before promoting or processing it. Scanner loss, rate limits, and quota exhaustion intentionally block promotion; the file stays private in staging and its queue job retries automatically.

The supplied account reports 800 calls/month and one call/second. Cloudmersive's current public free-tier page lists 600 calls/month, one call/second, one simultaneous request, and 3.5 MB per file, so confirm the actual account dashboard before launch. Configure the lower real allowance if it differs.

The application uses three safeguards across all Cloud workers:

1. A shared database/Redis cache lock allows one in-flight scan and spaces starts by 1.1 seconds.
2. A database row reserves calls for the calendar month and stops before the configured allowance is exceeded.
3. Upload validation uses the lower of the admin file limit and the configured Cloudmersive plan limit, preventing an oversized file from spending a call.

Cloudmersive describes the free tier as an evaluation plan with lower availability. Do not represent it as an SLA-backed production service. Monitor deferred jobs and provider usage, and plan an upgrade or alternative scanner before business volume approaches the cap. See [Cloudmersive pricing](https://cloudmersive.com/pricing-small-business) and [Virus Scan API reference](https://api.cloudmersive.com/docs/virus.asp).

## 3. Recommended first architecture

Use two Cloud environments:

- `staging`: separate database and private bucket; test documents only.
- `production`: separate database and private bucket; create it after staging passes.

Start with the repository's existing `database` queue connection and two Cloud background processes. This matches the current code and production-readiness gate.

Do not begin with Laravel Cloud Managed Queues. A managed queue changes `QUEUE_CONNECTION` to `cloud`; each managed queue handles one name, while this app has four; and the heavy workflow is designed around a 300-second budget. Treat managed queues as a later, separately tested migration.

`Procfile`, `nixpacks.toml`, and `scripts/deploy.sh` are Railway-oriented. Do not copy their start or migration behavior into Laravel Cloud.

## 4. Prepare locally

### 4.1 Confirm the GitHub branch

This checkout uses `https://github.com/RjDurin04/receiving.git` and currently deploys from `master`.

```powershell
cd C:\Projects\receiving
git status
git branch --show-current
git log -1 --oneline
git push origin master
```

Commit intended changes before continuing. Never commit `.env` or real secrets.

### 4.2 Run release checks

Run these sequentially:

```powershell
git diff --check
composer lint:check
composer analyse
composer audit --locked
npm run format:check
npm run types:check
npm run lint:check
npm run check
npm run build
npm audit --omit=dev
php artisan test
```

This local command is useful but is expected to report development-environment failures:

```powershell
php artisan receiving:check-production --json
```

### 4.3 Prepare secrets

Have these ready:

- Gemini API key.
- Transactional SMTP credentials and a verified sender.
- Cloudmersive API key and the call/file allowances shown in its account portal.
- Initial administrator name/email and a random password of 20+ characters.
- A custom domain if available; the free Cloud domain is fine for staging.

Generate a new application key for staging:

```powershell
php artisan key:generate --show
```

Save the full `base64:...` result in a password manager. Generate a different key for production.

## 5. Create the Cloud application

1. Go to [cloud.laravel.com](https://cloud.laravel.com), create an account, choose a plan, and add payment details.
2. Click **+ New application**.
3. Choose **Continue with GitHub** and authorize `RjDurin04/receiving`.
4. Select the repository and name the application `Receiving`.
5. Choose **Asia Pacific (Singapore)** (`ap-southeast-1`) unless users and required services are primarily elsewhere.
6. Create the application.
7. Rename the default environment `staging` if the UI permits.
8. Confirm its branch is `master`.

Keep app compute, database, bucket, and worker compute in the same region.

## 6. Configure runtime and compute

Open staging **General Settings** or click its App compute cluster.

| Setting | Value |
|---|---|
| PHP | `8.4` |
| Node | `22` |
| Octane | Off; the package is not installed |
| Inertia SSR | Off; use the normal client build |
| App replicas | 1 initially |
| Scale to Zero | Fine for idle staging; disable while testing long jobs |

Begin production with one always-on replica. Add autoscaling only after sessions, locks, scheduler behavior, workers, and provider concurrency have been tested.

## 7. Add PostgreSQL

1. On the staging canvas click **Add database**.
2. Select **Laravel Serverless Postgres**.
3. Name the cluster `receiving-staging-postgres`.
4. Select Singapore and the smallest practical compute size.
5. Allocate at least 5 GB storage.
6. Create `receiving_staging` and attach it to staging.
7. Set backup retention. Production should retain at least 7 days; 14-30 days is safer when policy and budget allow.

Cloud injects `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD`. Do not override them. This repo already migrates tables for sessions, cache locks, jobs, batches, and failed jobs, so Redis is optional for the first release.

## 8. Add private Laravel Object Storage

1. Click **Add bucket**.
2. Select **Laravel Object Storage**.
3. Create `receiving-staging-documents`.
4. Set disk name to exactly `r2`.
5. Set visibility to **Private**.
6. Attach it to staging.

Cloud manages CORS for attached Cloud/custom domains. This repository already installs the S3 Flysystem adapter.

### Map Cloud's bucket variables to this repo

Cloud injects `AWS_*` variables; this repo's `r2` disk reads `R2_*`. Open the generated variables or bucket **View credentials** and add:

| Add this custom variable | Copy value from |
|---|---|
| `R2_ACCESS_KEY_ID` | `AWS_ACCESS_KEY_ID` |
| `R2_SECRET_ACCESS_KEY` | `AWS_SECRET_ACCESS_KEY` |
| `R2_BUCKET_NAME` | `AWS_BUCKET` |
| `R2_ENDPOINT` | `AWS_ENDPOINT` |

Also add:

```env
R2_REGION=auto
R2_USE_PATH_STYLE_ENDPOINT=true
RECEIVING_DISK=r2
FILESYSTEM_DISK=r2
RECEIVING_PROXY_UPLOADS=true
```

Begin in proxy mode. Set `RECEIVING_PROXY_UPLOADS=false` only after a browser presigned PUT works from the real domain. For an existing external R2 bucket, enter its own `R2_*` values and allow the exact staging/production HTTPS origins in CORS.

## 9. Add environment variables

Open environment **Settings > Environment variables**. Leave generated Cloud variables in place. Replace every `<placeholder>` below.

### Core

```env
APP_NAME="Receiving Operations"
APP_ENV=production
APP_KEY=<base64-application-key>
APP_DEBUG=false
APP_URL=<https-environment-url>
APP_TIMEZONE=Asia/Singapore
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_MAINTENANCE_DRIVER=cache
APP_MAINTENANCE_STORE=database

HASH_DRIVER=argon2id
LOG_LEVEL=info

DB_CONNECTION=pgsql
DB_SSLMODE=require

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
SESSION_DOMAIN=null

CACHE_STORE=database
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=360
QUEUE_FAILED_DRIVER=database-uuids
BROADCAST_CONNECTION=log
```

`APP_ENV=production` is intentional in the Cloud environment named staging: it exercises production security behavior. Isolation comes from separate resources and credentials.

Cloud normally configures its `stderr` JSON log channel. Do not override generated logging variables unless the **Logs** tab still receives application logs.

Use the environment's HTTPS Cloud domain for `APP_URL`. If it appears only after the first successful deploy, temporarily use `https://receiving-staging.invalid`, deploy once, replace it with the assigned domain, and immediately redeploy before testing links or login.

### SMTP

```env
MAIL_MAILER=smtp
MAIL_SCHEME=null
MAIL_HOST=<smtp-host>
MAIL_PORT=587
MAIL_TIMEOUT=10
MAIL_USERNAME=<smtp-username>
MAIL_PASSWORD=<smtp-password-or-api-key>
MAIL_FROM_ADDRESS=<verified-sender>
MAIL_FROM_NAME="Receiving Operations"
```

Use the provider's documented scheme/port if it differs. A transactional provider is preferable to personal Gmail.

### Gemini

```env
GEMINI_API_KEY=<gemini-key>
GEMINI_MODEL=gemini-2.5-flash
GEMINI_BASE_URL=https://generativelanguage.googleapis.com/v1beta
GEMINI_TIMEOUT_SECONDS=120
GEMINI_HTTP_ATTEMPTS=2
RECEIVING_AI_BATCH_SIZE=1
RECEIVING_AI_RETRY_LIMIT=3
RECEIVING_AI_RETRY_BACKOFF_SECONDS=60
RECEIVING_REVIEW_RECIPIENT_RULE=uploader
RECEIVING_WORKER_TIMEOUT_SECONDS=300
RECEIVING_WORKER_TIMEOUT_SAFETY_SECONDS=30
```

Keep the batch at 1 for the first release to limit memory, retries, provider rate, and paid duplicate work.

### Cloudmersive malware scanning

```env
RECEIVING_SCANNER_DRIVER=cloudmersive
CLOUDMERSIVE_API_KEY=<secret-key-from-cloudmersive>
CLOUDMERSIVE_BASE_URL=https://api.cloudmersive.com
CLOUDMERSIVE_CONNECT_TIMEOUT_SECONDS=10
CLOUDMERSIVE_TIMEOUT_SECONDS=30
CLOUDMERSIVE_MONTHLY_CALL_LIMIT=800
CLOUDMERSIVE_MINIMUM_INTERVAL_MILLISECONDS=1100
CLOUDMERSIVE_MAX_FILE_KILOBYTES=3584
CLOUDMERSIVE_LOCK_WAIT_SECONDS=30
```

Never commit the key or place it in a browser-visible variable. Use the allowance displayed for this account. A configuration pass proves only safe settings; complete the live scanner probes below before production.

### Receiving defaults

```env
RECEIVING_OTP_EXPIRES_MINUTES=5
RECEIVING_OTP_MAX_ATTEMPTS=5
RECEIVING_OTP_GRANT_MINUTES=30
RECEIVING_MAX_FILES=20
RECEIVING_MAX_FILE_KILOBYTES=3584
RECEIVING_STAGING_URL_MINUTES=15
RECEIVING_STAGING_CLEANUP_HOURS=24
RECEIVING_SIGNED_URL_MINUTES=30
RECEIVING_REVIEW_LINK_HOURS=24
RECEIVING_COMPRESSION_ENABLED=true
RECEIVING_MAX_IMAGE_WIDTH=2400
RECEIVING_MAX_IMAGE_HEIGHT=2400
RECEIVING_JPEG_QUALITY=85
RECEIVING_ALLOW_ORIGINAL_ON_COMPRESSION_FAILURE=false
RECEIVING_LOCATION_MAX_ACCURACY_METERS=1000
RECEIVING_LOCATION_MAX_AGE_SECONDS=120
INERTIA_SSR_ENABLED=false
VITE_APP_NAME="Receiving Operations"
```

## 10. Configure build and deploy commands

Open **Settings > Deployments**.

### Build command

```bash
composer install --no-dev --optimize-autoloader --no-interaction
npm ci
npm run build
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Do not add `storage:link`, `queue:restart`, `optimize:clear`, `artisan serve`, or commands from `nixpacks.toml`. Cloud serves the app and restarts workers itself. Build and deploy commands each have a 15-minute limit.

### First deploy only

Set:

```bash
php artisan receiving:check-production
php artisan migrate --seed --force
```

Temporarily add:

```env
INITIAL_ADMIN_NAME=<administrator-name>
INITIAL_ADMIN_EMAIL=<administrator-email>
INITIAL_ADMIN_PASSWORD=<strong-random-password>
```

The seed creates roles, permissions, upload types, purchase-order schedule data, and the initial admin. Use a unique 20+ character password.

After the first successful deploy, change the deploy command to:

```bash
php artisan receiving:check-production
php artisan migrate --force
```

Remove all three `INITIAL_ADMIN_*` variables and **Save & Deploy** again.

Never automate `migrate:rollback`. Application and data rollback are separate decisions.

## 11. Add both queue workers

Without workers, the website can load while OTP, scanning, AI, and mail remain stuck.

For staging/low volume, click the **App compute cluster > Background processes** and add two custom processes, one instance each.

OTP worker:

```bash
php artisan queue:work --queue=otp --tries=3 --timeout=60 --sleep=1
```

Receiving worker:

```bash
php artisan queue:work --queue=receiving,ai,default --tries=3 --timeout=300 --sleep=3
```

Save and redeploy.

For production, prefer a dedicated Worker cluster when your plan supports it. Use the same two processes, start at one process each, and allocate at least 512 MiB for the receiving worker because it downloads, scans, and may re-encode documents.

`DB_QUEUE_RETRY_AFTER=360` must remain greater than the 300-second worker timeout. A shorter lease can run the same storage/mail/Gemini work twice.

Do not let an app-cluster worker with multi-minute jobs hibernate. Cloud warns long jobs can be interrupted when app compute scales to zero.

## 12. Enable the scheduler

The app runs `receiving:cleanup-staging` hourly.

1. Click App compute (or the Worker cluster used for schedules).
2. Enable **Scheduler**.
3. Save and redeploy.

Cloud invokes `schedule:run` each minute. Do not add a cron process. Start with one scheduler replica. Before multi-replica scheduling, add and verify `onOneServer` behavior; the current task already uses shared-cache `withoutOverlapping`.

## 13. First deployment

Before clicking **Deploy**, verify database/bucket attachment, the `R2_*` mapping, real provider values, first-deploy seeding, two background processes, and Scheduler.

Watch both Build and Deploy logs. Success requires:

- Every `receiving:check-production` row passes.
- Migrations and seeders complete.
- The deployment becomes active.

If it fails, fix the first real error instead of repeatedly clicking Deploy.

## 14. Cloud command smoke tests

Run these separately under the environment **Commands** tab:

```bash
php artisan receiving:check-production
php artisan migrate:status
php artisan schedule:list
php artisan queue:failed
```

Expected: readiness passes, all migrations ran, hourly cleanup is listed, and there are no unexplained failed jobs.

Test R2 only against staging:

```bash
php artisan tinker --execute="\Illuminate\Support\Facades\Storage::disk('r2')->put('healthchecks/laravel-cloud.txt', 'ok'); dump(\Illuminate\Support\Facades\Storage::disk('r2')->get('healthchecks/laravel-cloud.txt')); \Illuminate\Support\Facades\Storage::disk('r2')->delete('healthchecks/laravel-cloud.txt');"
```

Expected: `ok`, then successful exit. Cloud Commands are non-interactive and limited to 30 minutes; never run a permanent worker there.

## 15. Staging end-to-end verification

### Web and authentication

1. Open `https://<staging-domain>/up`; expect HTTP 200.
2. Open `/login`; it must render, not show a blank page.
3. Log in as the seeded admin and open `/admin`.
4. Trigger admin OTP; verify prompt delivery and an HTTPS staging link.
5. Confirm requests and errors appear in Cloud **Logs** without exposing secrets.

### Scanner

Use test data only:

1. Upload a known-clean small image/PDF; confirm a clean scan activity.
2. Upload the official harmless EICAR antivirus test file; confirm rejection and no final promotion.
3. Make the staging scanner unreachable and verify another file fails closed.
4. Upload several small clean files together; confirm they finish without 429 failures and Cloudmersive requests remain one-at-a-time.
5. Compare the current `cloudmersive_scan_usages.request_count` row with the Cloudmersive portal. The application count can be conservatively higher for failed outbound calls, but it must never exceed the configured monthly limit.

No production release is allowed if any scanner test fails.

### Complete receiving workflow

Upload one non-sensitive representative invoice and verify:

1. Browser upload completes.
2. `receiving` validates, scans, compresses, and promotes it.
3. Status leaves Pending/Processing.
4. `ai` calls Gemini and stores parseable extracted data.
5. Authorized admin details display it.
6. Expected mail arrives.
7. Review links are HTTPS and expire correctly.
8. `queue:failed` remains clean.

Browser upload success alone is not enough.

### Direct-to-R2 mode

After proxy mode passes:

1. Set `RECEIVING_PROXY_UPLOADS=false` in staging and redeploy.
2. Upload from the real staging domain.
3. In browser Network tools, confirm the presigned PUT has no CORS/403 error.
4. Repeat scanning, AI, mail, and status verification.

If it fails, restore `RECEIVING_PROXY_UPLOADS=true` and redeploy. Never make the bucket public or disable scanning.

## 16. Create production

Only after staging passes:

1. Choose staging **... > Replicate** and name it `production`.
2. Use the intended release branch (`master` currently).
3. Attach a separate production PostgreSQL logical database.
4. Create a separate private `receiving-production-documents` bucket with disk `r2`; object storage is not automatically replicated.
5. Map its `AWS_*` values to production `R2_*` values.
6. Use a new `APP_KEY` and production SMTP/Gemini/scanner/admin secrets.
7. Set production backup retention.
8. Disable Scale to Zero for app-cluster long workers or use a Worker cluster.
9. Repeat first-deploy seeding, then remove `INITIAL_ADMIN_*`.
10. Repeat every command and end-to-end smoke test with non-sensitive data.

Never share staging's database or bucket with production.

## 17. Add a custom domain

1. Open production **Network > Add domain**.
2. Enter the domain and copy every displayed DNS record to the authoritative DNS provider.
3. Wait for ownership, origin, and SSL to show connected.
4. Set `APP_URL=https://<production-domain>`; keep `SESSION_DOMAIN=null` unless cross-subdomain cookies are required.
5. For external R2, add the exact production HTTPS origin to CORS.
6. Save and redeploy.
7. Repeat login, OTP, signed link, upload, scanner, Gemini, and email tests.

Cloud issues and renews TLS. Do not enable HSTS preload until every required subdomain is permanently HTTPS-ready.

## 18. Monitoring and cost controls

- Enable deployment/resource notifications and billing alerts.
- Watch app/worker CPU and memory, PostgreSQL CPU/storage/connections, and Logs.
- Alert on failed jobs, queue depth, and oldest Pending/Processing upload age.
- Receiving workers may scale, but Cloudmersive scanning remains globally serialized by design; monitor queue age before adding upload volume.
- Keep unused staging compute hibernating.
- Retain backups and periodically prove a restore.
- Remember that filesystem and log retention are finite; durable data belongs in managed resources.

## 19. Release and rollback

Before each release: run local checks, review migrations, deploy the exact commit to staging, complete one clean workflow, confirm no new failures, record the production commit, confirm a recovery point, and deploy off-peak.

Roll back when critical pages return 5xx, clean uploads cannot finish, scanner/OTP stops, queue age continually grows, error rate exceeds 2x baseline for five minutes, or p95 latency exceeds 1.5x baseline for ten minutes.

Rollback sequence:

1. If only direct R2 fails, set `RECEIVING_PROXY_UPLOADS=true` and redeploy.
2. Pause receiving/AI workers if a bad release is causing destructive or expensive retries; keep OTP isolated when safe.
3. Redeploy the last known-good commit. A deploy hook may target a commit hash; otherwise point the environment to a branch containing it.
4. Do not automatically roll back the database. First prove the migration is reversible and newer writes will not be lost.
5. Recheck `/up`, login, OTP, one clean upload, scanner, Gemini, mail, and failed jobs.

Cloud restarts workers after deployment; do not add `queue:restart`.

## 20. Common failures

| Symptom/check | Likely fix |
|---|---|
| Production environment | `APP_ENV=production` |
| Debug enabled | `APP_DEBUG=false` |
| Missing app key | Set the full generated `base64:` key |
| HTTPS URL | Correct `APP_URL` |
| Session checks | Database driver, encryption true, Secure cookie, SameSite lax |
| Async/shared state checks | `QUEUE_CONNECTION=database`, `CACHE_STORE=database` |
| Retry lease | `DB_QUEUE_RETRY_AFTER=360`, worker timeout 300 |
| Private storage | Correct `R2_*`; `RECEIVING_DISK=r2` |
| Mail check | Real SMTP; not `log` or `array` |
| Gemini check | Key, model, HTTPS base URL |
| Scanner check | Cloudmersive key/HTTPS URL, free-tier guardrails, then clean/EICAR/rate-limit probes |
| OTP never arrives | OTP worker, SMTP delivery log, Cloud Logs, `queue:failed` |
| Upload then nothing | Worker must consume `receiving,ai,default`; check scanner and failed jobs |
| R2 CORS/403 | Enable proxy mode, verify bucket attachment/mapping/origin; never make it public |
| Build passes but app fails | Inspect Deploy log, `/up`, APP_URL/key, DB attachment, PHP 8.4 |

## 21. Final go-live checklist

- [ ] Local lint, analysis, tests, build, and dependency audits pass.
- [ ] Staging and production have separate databases and private buckets.
- [ ] PHP 8.4 and Node 22 are selected; Octane/SSR are off.
- [ ] HTTPS URL, debug false, encrypted sessions, and secure cookies are set.
- [ ] `receiving:check-production` and migrations pass in Cloud.
- [ ] OTP and receiving/AI/default workers are running.
- [ ] Database retry lease is 360 seconds and heavy worker timeout is 300.
- [ ] Scheduler lists hourly cleanup.
- [ ] R2 write/read/delete passes.
- [ ] Direct upload passes, or proxy mode stays enabled.
- [ ] Cloudmersive passes clean, EICAR, multi-file serialization, and unavailable-scanner staging tests.
- [ ] The configured monthly and file limits match the actual Cloudmersive account portal.
- [ ] Gemini extracts a representative document.
- [ ] SMTP delivers OTP and workflow mail.
- [ ] `/up`, `/login`, `/admin`, upload, AI, and email pass end to end.
- [ ] Failed jobs are empty or explained.
- [ ] Backups, restore procedure, monitoring, billing alerts, rollback triggers, and known-good commit are recorded.

After every box passes, connect the production domain, perform one low-risk final transaction, and watch the first full operating cycle.
