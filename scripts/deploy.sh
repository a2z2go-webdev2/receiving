#!/bin/bash
# ─────────────────────────────────────────────────────────────────────────────
#  Receiving — One-Command Production Deploy
#  Usage: bash scripts/deploy.sh [first-run|release|rollback]
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

BOLD="\033[1m"
GREEN="\033[0;32m"
YELLOW="\033[1;33m"
RED="\033[0;31m"
NC="\033[0m"

step() { echo -e "\n${GREEN}▸ $1${NC}"; }
warn() { echo -e "${YELLOW}⚠ $1${NC}"; }
fail() { echo -e "${RED}✖ $1${NC}"; exit 1; }

# ── Determine mode ───────────────────────────────────────────────────────────
MODE="${1:-release}"

case "$MODE" in
  first-run|release|rollback) ;;
  *) fail "Usage: bash scripts/deploy.sh [first-run|release|rollback]" ;;
esac

echo -e "${BOLD}🚀 Receiving — $MODE deployment${NC}"

# ── Validate .env exists ────────────────────────────────────────────────────
if [ ! -f ".env" ]; then
  fail ".env file not found. Copy .env.production and fill in credentials first."
fi

# ── Step 1: Install PHP dependencies ────────────────────────────────────────
step "Installing PHP dependencies (production)..."
composer install --no-dev --optimize-autoloader --no-interaction

# ── Step 2: Install Node dependencies & build ───────────────────────────────
step "Installing Node dependencies..."
npm ci --production=false

step "Building frontend assets..."
npm run build

# ── Step 3: Run migrations ──────────────────────────────────────────────────
if [ "$MODE" = "first-run" ]; then
  step "Running migrations with seed (first deploy)..."
  php artisan migrate --seed --force
elif [ "$MODE" = "rollback" ]; then
  step "Rolling back last migration batch..."
  php artisan migrate:rollback --force
else
  step "Running migrations..."
  php artisan migrate --force
fi

# ── Step 4: Cache configuration ─────────────────────────────────────────────
step "Caching configuration, routes, and views..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# ── Step 5: Storage link ────────────────────────────────────────────────────
step "Ensuring storage symlink..."
php artisan storage:link 2>/dev/null || true

# ── Step 6: Production readiness check ──────────────────────────────────────
if [ "$MODE" != "rollback" ]; then
  step "Running production readiness checks..."
  php artisan receiving:check-production || warn "Some checks failed — review above."
fi

# ── Step 7: Restart queue workers ───────────────────────────────────────────
step "Restarting queue workers..."
php artisan queue:restart

echo -e "\n${GREEN}${BOLD}✔ Deployment ($MODE) complete!${NC}"

if [ "$MODE" = "first-run" ]; then
  echo -e "${YELLOW}"
  echo "  ┌──────────────────────────────────────────────────────────┐"
  echo "  │  Admin account seeded with: INITIAL_ADMIN_EMAIL          │"
  echo "  │  You can now remove INITIAL_ADMIN_* vars from .env.      │"
  echo "  │  Don't forget to start queue workers (see deploy guide). │"
  echo "  └──────────────────────────────────────────────────────────┘"
  echo -e "${NC}"
fi
