# 🚀 Receiving — Deployment Guide

> For the current, beginner-focused Laravel Cloud walkthrough tailored to this repository, see [`docs/laravel-cloud-deployment.md`](docs/laravel-cloud-deployment.md).

## Overview

This overview covers the existing **Railway** deployment. Use the linked beginner guide above for **Laravel Cloud**. The app requires:

| Component | Technology |
|-----------|-----------|
| Runtime | PHP 8.4 + Node 20 |
| Database | PostgreSQL |
| Queue | Database-backed (2 queues: `otp` + `receiving,ai,default`) |
| Storage | Cloudflare R2 |
| Mail | SMTP (Gmail) |
| AI | Google Gemini API |
| Scanner | Cloudmersive Virus Scan API (required in production) |

---

## Files Created for Deployment

| File | Purpose |
|------|---------|
| `.env.production` | Production env template — copy to `.env` on server |
| `scripts/deploy.sh` | One-command deploy script (`first-run`, `release`, `rollback`) |
| `Procfile` | Railway process definitions |
| `nixpacks.toml` | Railway build configuration |

---

## 🚂 Railway Deployment (Step by Step)

### Step 1: Create Railway Project

1. Go to [railway.app](https://railway.app) and create a new project
2. Connect your GitHub repository (`receiving`)
3. Railway will auto-detect it as a PHP app via `nixpacks.toml`

### Step 2: Add PostgreSQL Database

1. In your Railway project, click **"+ New"** → **"Database"** → **"PostgreSQL"**
2. Railway creates the database automatically and provides connection variables
3. Copy the connection variables from the PostgreSQL service's **"Connect"** tab:
   - `PGHOST` → use as `DB_HOST`
   - `PGPORT` → use as `DB_PORT`
   - `PGDATABASE` → use as `DB_DATABASE`
   - `PGUSER` → use as `DB_USERNAME`
   - `PGPASSWORD` → use as `DB_PASSWORD`

### Step 3: Set Environment Variables

In the Railway web service, go to **"Variables"** and enter your production environment variables.

> **💡 TIP:** We recommend copying the entire contents of `.env.production` into Railway's "Raw Editor", and then filling in the missing values.

### Important Railway Variable Mappings

Railway provides database variables with a `PG` prefix. You can map them directly in your environment variables like this:

```env
DB_CONNECTION=pgsql
DB_HOST=${PGHOST}
DB_PORT=${PGPORT}
DB_DATABASE=${PGDATABASE}
DB_USERNAME=${PGUSER}
DB_PASSWORD=${PGPASSWORD}
DB_SSLMODE=require
```

### Essential Production Variables to Fill

Make sure you also generate a new key and set your domain:

```env
APP_KEY=base64:YOUR_GENERATED_KEY    # Generate locally with: php artisan key:generate --show
APP_URL=https://your-railway-app-url.up.railway.app
```

### Step 4: First Deploy (Database Seed)

> **🚨 CAUTION:** The first deploy requires creating the database tables and seeding the admin account. This is a one-time operation.

**Option A — Railway Dashboard (Recommended):**
1. Click **Deploy** on your main web service to apply the environment variables.
2. Wait for the build and deployment to finish (green checkmark).
3. Click on your web service, open the **Command Palette** (`Ctrl + K` or `Cmd + K`), and search for **"Execute Command"**.
4. Run this exact command to create the tables and your admin account:
   ```bash
   php artisan migrate --seed --force
   ```

**Option B — Railway CLI:**
```bash
railway run php artisan migrate --seed --force
```

### Step 5: Queue Worker

Railway needs a **separate service** for the queue worker to process file uploads, malware scanning, and OTP emails in the background:

1. In your Railway project dashboard, click **"+ New"** → **"GitHub Repo"** → connect this same repository again.
2. Click on this new service, go to **Settings** → **Deploy** → **Start Command**.
3. Turn on the override and paste this exact command:
   ```bash
   php artisan queue:work --queue=otp,receiving,ai,default --tries=3 --timeout=300 --sleep=3
   ```
4. **🚨 CRITICAL:** You must copy **ALL** the exact same environment variables from your main web service into this queue worker service's **Variables** tab (especially `APP_KEY`). If you don't, the worker's Vite build will fail.
   *(Tip: Use Railway's Raw Editor to easily copy/paste all variables at once).*
5. The worker shares the same database, so queued jobs will process automatically.

### Step 6: Configure Domain & SSL

1. In Railway, go to your web service → **"Settings"** → **"Networking"** → **"Public Networking"**.
2. Click **Generate Domain** (or add your custom domain).
3. **Important:** Copy the generated `https://...` URL, go back to your **Variables** tab, and update the `APP_URL` variable to exactly match it.
4. *(Note: Laravel is configured to trust Railway's reverse proxy in `bootstrap/app.php` with `$middleware->trustProxies(at: '*');` so you don't get blank pages or mixed-content errors).*

### Step 7: Post-Deploy Verification

```bash
# Check production readiness via Railway CLI
railway run php artisan receiving:check-production

# Verify admin can login at your domain
# https://your-domain.com/login
```

After verifying the admin account works, **remove** these variables:
- `INITIAL_ADMIN_NAME`
- `INITIAL_ADMIN_EMAIL`
- `INITIAL_ADMIN_PASSWORD`

### Step 8: Configure Cloudmersive (Required)

Add the same scanner variables to the **Main Web Service** and the **Queue Worker**. Keep the API key in Railway secrets:

```env
RECEIVING_SCANNER_DRIVER="cloudmersive"
CLOUDMERSIVE_API_KEY="your-secret-api-key"
CLOUDMERSIVE_MONTHLY_CALL_LIMIT=800
CLOUDMERSIVE_MINIMUM_INTERVAL_MILLISECONDS=1100
CLOUDMERSIVE_MAX_FILE_KILOBYTES=3584
```

Use the monthly allowance shown in the Cloudmersive portal. The application keeps scans one-at-a-time across workers and automatically defers work when the allowance or provider rate limit is reached.

---

## 🔄 Subsequent Deployments

Railway auto-deploys on every push to your connected branch. Each deploy automatically:
1. Installs dependencies (`composer install`, `npm ci`)
2. Builds frontend (`npm run build`)
3. Caches config/routes/views
4. Runs pending migrations

No manual intervention needed for regular deploys.

---

## ☁️ Laravel Cloud Deployment (Future)

When you're ready to move to Laravel Cloud:

### Step 1: Connect Repository
1. Go to [cloud.laravel.com](https://cloud.laravel.com)
2. Create a new project and connect your GitHub repo

### Step 2: Configure Environment
1. Set all the same env variables from `.env.production`
2. Laravel Cloud manages the database — create a PostgreSQL database in the dashboard

### Step 3: Build & Deploy
Laravel Cloud handles the build pipeline automatically. Use the deploy script:
```bash
bash scripts/deploy.sh first-run   # First deploy (with seed)
bash scripts/deploy.sh release     # Subsequent deploys
```

### Step 4: Queue Workers
Laravel Cloud has native queue worker support — configure in the dashboard under **Workers**:
- Queue: `otp,receiving,ai,default`
- Tries: 3
- Timeout: 300s

---

## 📊 Database Admin Seed Details

The seeder creates:

| What | Details |
|------|---------|
| **Roles** | `admin` (full permissions), `uploader` (upload only) |
| **Admin Account** | Email: `t78534666@gmail.com`, auto-verified, assigned `admin` role |
| **Upload Types** | A2Z2GO, PINGCON, BONITA, KEYSYS INC., Purchase Order |

The seeder is **idempotent** — running it multiple times won't create duplicates. It uses `firstOrCreate` for the user and `updateOrCreate` for upload types.

> **📌 NOTE:** The admin password must be at least **12 characters**. The seeder will throw a `RuntimeException` if it's shorter.

---

## 🛡️ Malware Scanner Note

The production readiness check requires configured Cloudmersive credentials and free-tier-safe limits. `RECEIVING_SCANNER_DRIVER=none` is rejected in production because it would accept unscanned files.

The previous ClamAV adapter remains available only as an emergency rollback by setting `RECEIVING_SCANNER_DRIVER=clamav` with a protected reachable host. Do not use the rollback driver as a way to bypass scanner setup.

---

## 🔧 Quick Reference Commands

```bash
# Generate APP_KEY
php artisan key:generate --show

# First deploy (local/VPS)
bash scripts/deploy.sh first-run

# Regular release
bash scripts/deploy.sh release

# Rollback last migration
bash scripts/deploy.sh rollback

# Check production readiness
php artisan receiving:check-production

# Clear all caches
php artisan optimize:clear

# Queue workers (run in production)
php artisan queue:work --queue=otp --tries=3 --timeout=60 --sleep=1
php artisan queue:work --queue=receiving,ai,default --tries=3 --timeout=300 --sleep=3
```
