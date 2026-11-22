#!/usr/bin/env bash
# Entry point of the production image (Dockerfile). Free hosts have no pre-deploy step,
# so migrations run here on every boot; they are a no-op when nothing is pending.
set -euo pipefail
cd /app

if [ -z "${APP_KEY:-}" ]; then
    echo "[start] APP_KEY is not set; generate one with: php artisan key:generate --show" >&2
    exit 1
fi

# Public URL: explicit APP_URL, else what the host provides (Hugging Face Spaces: SPACE_HOST).
if [ -z "${APP_URL:-}" ]; then
    if [ -n "${SPACE_HOST:-}" ]; then
        export APP_URL="https://${SPACE_HOST}"
    else
        export APP_URL="http://localhost:${PORT:-8080}"
    fi
fi

php artisan migrate --force --no-interaction
php artisan optimize

# Demo deploys: roles, demo accounts and catalogue (all idempotent), plus 30 days of sales
# history when POS_DEMO_SALES_HISTORY=true and there are no sales yet. That takes a while on
# a small instance, so it runs in the background and the server starts accepting requests.
if [ "${DEMO_SEED:-false}" = "true" ]; then
    echo "[start] seeding demo data in the background"
    php artisan db:seed --force --no-interaction &
fi

exec frankenphp run --config /app/docker/Caddyfile
