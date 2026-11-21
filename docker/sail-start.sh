#!/usr/bin/env bash
# Started by supervisord inside the Sail container (SUPERVISOR_PHP_COMMAND in compose.yaml).
# Does the one-time setup a fresh clone needs, then serves the app on port 80.
set -euo pipefail
cd /var/www/html

if [ ! -f .env ]; then
    echo "[sail-start] creating .env from .env.example"
    cp .env.example .env
fi

if ! grep -qE '^APP_KEY=.+' .env; then
    echo "[sail-start] generating APP_KEY"
    php artisan key:generate --force --no-interaction
fi

if [ ! -d node_modules ]; then
    echo "[sail-start] installing node modules"
    npm ci --no-audit --no-fund
fi

if [ ! -f public/build/manifest.json ]; then
    echo "[sail-start] building frontend assets"
    npm run build
fi

exec php -d variables_order=EGPCS artisan serve --host=0.0.0.0 --port=80
