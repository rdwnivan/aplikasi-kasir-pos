#!/usr/bin/env bash
# Entry point of the production image (Dockerfile). Free hosts have no pre-deploy step,
# so migrations run here on every boot; they are a no-op when nothing is pending.
set -euo pipefail
cd /app

if [ -z "${APP_KEY:-}" ]; then
    echo "[start] APP_KEY is not set; generate one with: php artisan key:generate --show" >&2
    exit 1
fi

# Public URL: explicit APP_URL, else what the host provides (Vercel system env vars).
if [ -z "${APP_URL:-}" ]; then
    if [ -n "${VERCEL_PROJECT_PRODUCTION_URL:-}" ]; then
        export APP_URL="https://${VERCEL_PROJECT_PRODUCTION_URL}"
    else
        export APP_URL="http://localhost:${PORT:-8080}"
    fi
fi

# Autoscaling hosts (Vercel) may boot several instances at once; set MIGRATE_ON_BOOT=false
# there and migrate from CI instead (.github/workflows/demo-db.yml, which also deploys).
if [ "${MIGRATE_ON_BOOT:-true}" = "true" ]; then
    php artisan migrate --force --no-interaction
fi
php artisan optimize

# Single-instance demo hosts: roles, demo accounts and catalogue (all idempotent), plus 30
# days of sales history when POS_DEMO_SALES_HISTORY=true and there are no sales yet. That takes
# a while on a small instance, so it runs in the background and the server starts accepting
# requests. Not for autoscaling hosts: two instances would seed at once, and a scaled-down
# instance is stopped mid-seed. Use demo-db.yml there.
if [ "${DEMO_SEED:-false}" = "true" ]; then
    echo "[start] seeding demo data in the background"
    php artisan db:seed --force --no-interaction &
fi

exec frankenphp run --config /app/docker/Caddyfile
