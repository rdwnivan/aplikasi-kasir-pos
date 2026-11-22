# Production image: FrankenPHP (Caddy + PHP 8.5) serving public/, used by the Render
# deploy (render.yaml). Local development uses Sail (compose.yaml) instead.
#
#   docker build -t kasir-pos .
#   docker run -p 8080:8080 -e APP_KEY=base64:... -e DB_URL=postgresql://... kasir-pos
#
# docker/render-start.sh migrates (and optionally seeds) on boot, then starts the server.

ARG FRANKENPHP_IMAGE=dunglas/frankenphp:1.13.6-php8.5-bookworm

# --- PHP runtime shared by the build and the final image ---------------------------------
FROM ${FRANKENPHP_IMAGE} AS base

RUN install-php-extensions bcmath intl pdo_pgsql zip opcache pcntl \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php.ini $PHP_INI_DIR/conf.d/zz-app.ini

WORKDIR /app

# --- Build: Composer dependencies and the Vite bundle ------------------------------------
# The Wayfinder Vite plugin runs `php artisan wayfinder:generate`, so the frontend build
# needs PHP and Node in the same stage.
FROM base AS build

COPY --from=composer:2.9.7 /usr/bin/composer /usr/local/bin/composer
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx \
    && apt-get update && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

COPY package.json package-lock.json .npmrc ./
RUN npm ci --no-audit --no-fund

COPY . .
RUN cp .env.example .env \
    && composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && php artisan package:discover --ansi \
    && npm run build \
    && rm -rf node_modules .env storage/logs/*.log

# --- Final image ---------------------------------------------------------------------------
FROM base

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    PORT=8080

COPY --from=build /app /app

# Run as an unprivileged user; it listens on a high port, so FrankenPHP needs no capabilities.
RUN apt-get update && apt-get install -y --no-install-recommends libcap2-bin \
    && rm -rf /var/lib/apt/lists/* \
    && useradd --create-home --uid 1000 app \
    && setcap -r /usr/local/bin/frankenphp \
    && mkdir -p /data/caddy /config/caddy \
    && chown -R app:app /data /config /app/storage /app/bootstrap/cache

USER app

EXPOSE 8080

CMD ["bash", "/app/docker/render-start.sh"]
