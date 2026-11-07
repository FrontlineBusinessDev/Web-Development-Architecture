# syntax=docker/dockerfile:1
#
# Production image for one Coolify VPS (Web-Development-Architecture: deployment/docker-image.md).
#
#   phpredis -> base (runtime PHP) -> tools (whole source + dev deps) -> checks (the deploy gate)
#                                                                    -> assets (Vite build)
#            -> build (production vendor/) -> final image (only what runs, non-root)
#
# `checks` runs `composer ci:check`. The `build` stage copies a file out of it, so BuildKit can
# never skip it: a failing check fails the build, and nothing deploys.

# Exact tags: bump them on purpose, never a floating "1-php8.4".
ARG FRANKENPHP_IMAGE=dunglas/frankenphp:1.13.4-php8.4-bookworm
ARG NODE_MAJOR=22

# phpredis from its GitHub release, not PECL (pecl.php.net timeouts have failed deploys);
# a clone, because the release archive leaves out the bundled liblzf submodule.
FROM ${FRANKENPHP_IMAGE} AS phpredis
RUN apt-get update && apt-get install -y --no-install-recommends git \
    && git clone --quiet --depth 1 --branch 6.3.0 --recurse-submodules --shallow-submodules \
        https://github.com/phpredis/phpredis.git /phpredis

# 1. Runtime PHP. Add only the extensions the app uses (stack/environment-setup.md).
FROM ${FRANKENPHP_IMAGE} AS base

ENV PORT=8000 \
    LOG_STACK=single,stderr

WORKDIR /app

# PHP's upload defaults (2M / 8M / 20 files) drop files silently.
RUN --mount=type=bind,from=phpredis,source=/phpredis,target=/tmp/phpredis,rw \
    apt-get update && apt-get install -y --no-install-recommends curl \
    && install-php-extensions bcmath gd intl opcache pcntl pdo_pgsql pgsql zip /tmp/phpredis \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf 'upload_max_filesize = 10M\npost_max_size = 64M\nmax_file_uploads = 50\n' \
        > "$PHP_INI_DIR/conf.d/zz-uploads.ini" \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# 2. Tools: the whole source with dev dependencies, Composer and Node. Nothing here ships.
FROM base AS tools
ARG NODE_MAJOR

ENV COMPOSER_ALLOW_SUPERUSER=1

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apt-get update && apt-get install -y --no-install-recommends git unzip \
    && curl -fsSL "https://deb.nodesource.com/setup_${NODE_MAJOR}.x" | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && apt-get clean && rm -rf /var/lib/apt/lists/* \
    && cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

# Dependencies first, so a code-only change reuses these layers.
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-autoloader
COPY package.json package-lock.json ./
RUN npm ci

COPY . .
RUN composer dump-autoload --no-interaction --optimize

# 3. Checks: the deploy gate (rules/system-rules.md, rules 22 and 49). Format, lint, types,
#    audits and tests (quality/testing-and-ci.md). The .env exists only in this stage.
FROM tools AS checks
RUN cp .env.example .env \
    && php artisan key:generate --ansi \
    && composer ci:check \
    && touch /tmp/ci-passed

# 4. Assets: the Vite build. ASSET_URL becomes Vite's base only when the files go to the bucket
# (deploy/build-image.sh passes ASSETS_UPLOADED=1). A plain build (Coolify's Redeploy of the app, rule 67)
# drops it, even if Coolify passes the app's ASSET_URL, so the image serves its own /build files.
FROM tools AS assets
ARG ASSET_URL=""
ARG ASSETS_UPLOADED=0
RUN if [ "$ASSETS_UPLOADED" != 1 ]; then unset ASSET_URL; fi; npm run build

# 5. Build: production vendor/ and the runtime files.
FROM base AS build

ENV COMPOSER_ALLOW_SUPERUSER=1

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apt-get update && apt-get install -y --no-install-recommends git unzip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-dev --no-scripts --no-autoloader

# The gate: this copy makes the build depend on `checks`.
COPY --from=checks /tmp/ci-passed /tmp/ci-passed

COPY . .
COPY --from=assets /app/public/build ./public/build

# ASSETS_UPLOADED=1: deploy/build-image.sh already put the files in the bucket, so only the
# manifest stays in the image (the app serves them from ASSET_URL). Otherwise the image keeps them and
# public/build/.in-image tells config/app.php to serve them itself (asset_url null; docker-image.md).
ARG ASSETS_UPLOADED=0
RUN composer dump-autoload --no-interaction --optimize --no-dev \
    && php artisan octane:install --server=frankenphp --no-interaction \
    && if [ "$ASSETS_UPLOADED" = 1 ]; then find public/build -mindepth 1 ! -name manifest.json -delete; \
       else touch public/build/.in-image; fi

# 6. Final image: only what runs (deployment/docker-image.md).
FROM base

# Non-root. Port 8000 needs no capability, and a binary with file caps fails under no-new-privileges.
RUN useradd --uid 1000 --user-group --no-create-home --shell /usr/sbin/nologin app \
    && setcap -r /usr/local/bin/frankenphp \
    && chown -R app:app /config /data

COPY --from=build /app/app ./app
COPY --from=build /app/bootstrap ./bootstrap
COPY --from=build /app/config ./config
COPY --from=build /app/database ./database
COPY --from=build /app/public ./public
COPY --from=build /app/resources/views ./resources/views
COPY --from=build /app/routes ./routes
COPY --from=build /app/scripts ./scripts
COPY --from=build /app/vendor ./vendor
COPY --from=build /app/artisan /app/composer.json ./
# Code stays root-owned; only the folders Laravel writes to belong to the app user.
COPY --from=build --chown=app:app /app/storage ./storage
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chown -R app:app storage bootstrap/cache

USER app

EXPOSE 8000

# Ready = /up answers 200: database + Redis reachable and no drain flag (deployment/architecture.md).
HEALTHCHECK --interval=5s --timeout=3s --start-period=30s --retries=3 \
    CMD curl -fsS -o /dev/null "http://127.0.0.1:${PORT}/up" || exit 1

CMD ["bash", "scripts/deploy.sh"]
