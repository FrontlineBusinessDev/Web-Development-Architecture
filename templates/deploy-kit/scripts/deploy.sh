#!/usr/bin/env bash
# Web container start (Dockerfile CMD). Production never migrates here: the one-off `migrate`
# service does (scripts/migrate.sh), so two web containers never race each other.
set -euo pipefail
export LOG_STACK="${LOG_STACK:-single,stderr}" # stderr: Coolify's log viewer never shows the log file

# Config, routes, views and events cached for this image (bootstrap/cache belongs to the app user).
php artisan optimize

# Inertia SSR is off. If it is turned on, ship bootstrap/ssr and Node, and start it here.
exec php artisan octane:start --server=frankenphp --host=0.0.0.0 --port="${PORT:-8000}" \
    --workers="${OCTANE_WORKERS:-1}" --max-requests="${OCTANE_MAX_REQUESTS:-500}"
