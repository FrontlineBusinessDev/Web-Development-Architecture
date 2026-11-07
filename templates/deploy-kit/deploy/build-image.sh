#!/usr/bin/env bash
# Builds the app image from /src. Runs inside the deployer image (deploy/Dockerfile.deployer)
# against the host's Docker: on the server from deploy/coolify-deploy.sh, and on a developer's PC
# from scripts/publish.sh, so both build the same way.
#   bash /src/deploy/build-image.sh <image:tag>
#
#   1. checks:  the Dockerfile's `checks` stage (composer ci:check). A failure stops everything.
#   2. pgsql:   the same test suite against a throwaway PostgreSQL 17 (production's database).
#   3. assets:  when ASSET_URL is set, the Vite build goes to the bucket before the image is built.
#   4. image:   the final image (with ASSETS_UPLOADED=1 it keeps only the Vite manifest).
# Exits non-zero, with the reason as the last line, when it fails.
set -uo pipefail

image="${1:?Usage: build-image.sh <image:tag>}"
dir="/data/coolify/applications/${COOLIFY_UUID:-none}"
work="__APP_SLUG__-ci-$$"

# The app's settings: this container's own env first, then the app's Coolify variables (with
# COOLIFY_API_TOKEN), then the app's .env as Coolify last wrote it. Values never reach the log.
api_envs=""
if [ -n "${COOLIFY_API_TOKEN:-}" ] && [ -n "${COOLIFY_UUID:-}" ]; then
    api_envs=$(curl -fsS --max-time 60 -H "Authorization: Bearer ${COOLIFY_API_TOKEN}" \
        "${COOLIFY_URL:-http://coolify:8080}/api/v1/applications/${COOLIFY_UUID}/envs" || true)
fi
app_env() {
    local v="${!1:-}"
    if [ -z "$v" ] && [ -n "$api_envs" ]; then
        v=$(jq -r --arg k "$1" '[.[] | select(.key == $k and (.is_preview | not))][0] | (.real_value // .value // empty)' <<<"$api_envs" 2>/dev/null)
    fi
    if [ -z "$v" ] && [ -f "$dir/.env" ]; then
        v=$(grep -m1 "^$1=" "$dir/.env" | cut -d= -f2- | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'$/\1/")
    fi
    printf '%s' "$v"
}

cleanup() {
    docker rm -f "${work}-db" >/dev/null 2>&1 || true
    docker network rm "${work}" >/dev/null 2>&1 || true
    docker image rm "${work}:checks" "${work}:assets" >/dev/null 2>&1 || true
}
trap cleanup EXIT

# 1. The gate. Same stage the final build depends on, so step 4 reuses it from the cache.
echo "Running composer ci:check (format, lint, types, audits, tests)..."
docker build --target checks -t "${work}:checks" /src || { echo "composer ci:check failed: nothing was deployed."; exit 1; }

# 2. The suite on PostgreSQL. phpunit.xml's SQLite settings give way to these env vars.
#    SKIP_PGSQL_TESTS=1 is for an emergency fix only, and the skip is written in the log.
if [ "${SKIP_PGSQL_TESTS:-0}" = 1 ]; then
    echo "SKIP_PGSQL_TESTS=1: the PostgreSQL test run was skipped."
else
    echo "Running the tests against PostgreSQL 17..."
    docker network create "$work" >/dev/null
    docker run -d --name "${work}-db" --network "$work" -e POSTGRES_DB=testing \
        -e POSTGRES_USER=testing -e POSTGRES_PASSWORD=testing postgres:17-alpine >/dev/null
    for _ in $(seq 1 30); do
        docker exec "${work}-db" pg_isready -U testing -d testing >/dev/null 2>&1 && break
        sleep 1
    done
    docker run --rm --network "$work" -e DB_CONNECTION=pgsql -e DB_HOST="${work}-db" -e DB_PORT=5432 \
        -e DB_DATABASE=testing -e DB_USERNAME=testing -e DB_PASSWORD=testing \
        "${work}:checks" php artisan test || { echo "The tests failed on PostgreSQL: nothing was deployed."; exit 1; }
fi

# 3. Static files. ASSET_URL must equal AWS_URL/AWS_BUCKET_FOLDER: the files go to
#    s3://AWS_BUCKET/AWS_BUCKET_FOLDER/build. Empty ASSET_URL = the app serves its own files.
ASSET_URL=$(app_env ASSET_URL)
uploaded=0
if [ -n "$ASSET_URL" ]; then
    for v in AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY AWS_BUCKET AWS_ENDPOINT; do
        [ -n "$(app_env "$v")" ] || { echo "ASSET_URL is set but ${v} is not (deployment/architecture.md)."; exit 1; }
    done
    folder=$(app_env AWS_BUCKET_FOLDER)
    target="s3://$(app_env AWS_BUCKET)${folder:+/${folder}}/build"
    region=$(app_env AWS_DEFAULT_REGION)
    endpoint=$(app_env AWS_ENDPOINT)
    export AWS_ACCESS_KEY_ID="$(app_env AWS_ACCESS_KEY_ID)" AWS_SECRET_ACCESS_KEY="$(app_env AWS_SECRET_ACCESS_KEY)" \
        AWS_DEFAULT_REGION="${region:-us-east-1}"
    out=$(mktemp -d)
    echo "Building the Vite files for ${ASSET_URL}..."
    # Names carry a content hash: --size-only uploads only new files. Nothing is deleted, because
    # pages still open on an older deploy (and rollbacks) need their files.
    if ! { docker build --target assets --build-arg ASSET_URL="$ASSET_URL" --build-arg ASSETS_UPLOADED=1 -t "${work}:assets" /src \
        && cid=$(docker create "${work}:assets") \
        && docker cp "${cid}:/app/public/build/." "$out" \
        && docker rm "$cid" >/dev/null \
        && aws --endpoint-url "$endpoint" s3 sync "$out" "$target" --size-only --acl public-read \
            --exclude manifest.json --cache-control 'public, max-age=31536000, immutable'; }; then
        rm -rf "$out"
        echo "The Vite files could not be built or uploaded: nothing was deployed."
        exit 1
    fi
    rm -rf "$out"
    uploaded=1
fi

# 4. The image. Every stage above is reused from the build cache.
echo "Building ${image}..."
docker build -t "$image" --build-arg ASSET_URL="$ASSET_URL" --build-arg ASSETS_UPLOADED="$uploaded" /src \
    || { echo "The image ${image} could not be built."; exit 1; }
