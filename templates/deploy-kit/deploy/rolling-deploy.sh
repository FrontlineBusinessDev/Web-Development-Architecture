#!/usr/bin/env bash
# Zero-downtime rollout on the server (deployment/publish-and-rollout.md):
#   1. copy the app's Coolify variables into its .env (optional, needs COOLIFY_API_TOKEN);
#   2. tag the new image as the one the compose file runs, then migrate while the old containers serve;
#   3. replace web-1, web-2, ... ONE AT A TIME: drain (/up fails, Traefik stops routing), recreate,
#      wait until healthy. A container that never gets healthy stops the rollout; the others keep serving;
#   4. recreate the queues, the scheduler and the maintenance page; keep the 3 newest builds.
#
#   APP_IMAGE=__APP_SLUG__-build:<id> \
#   COMPOSE_ARGS="-p <app-uuid> -f /data/coolify/applications/<app-uuid>/docker-compose.yaml" \
#     bash deploy/rolling-deploy.sh
# Without APP_IMAGE it builds with compose (local testing). Rollback = the same command with an older APP_IMAGE.
#
# Migrations must be backward compatible (add first, drop in a later deploy): the old code keeps
# running against the new schema until its container is replaced.
set -euo pipefail

# shellcheck disable=SC2086 # COMPOSE_ARGS is a list of flags
compose() { docker compose ${COMPOSE_ARGS:-} "$@"; }
health() { docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$1" 2>/dev/null || echo gone; }

if [ -n "${APP_IMAGE:-}" ]; then
    # Retried: Docker's containerd store can fail unpacking layers in parallel; a retry finishes.
    for attempt in 1 2 3; do
        docker image inspect "$APP_IMAGE" >/dev/null 2>&1 && break
        docker pull "$APP_IMAGE" && break
        [ "$attempt" = 3 ] && exit 1
        echo "Pull failed (attempt ${attempt}/3), retrying..."
        sleep 5
    done
else
    compose build migrate
fi

# 1. Variables saved in Coolify reach the app's .env only when Coolify deploys the app itself, which
#    we never do. Copy them in now so "Save, then php artisan deploy" applies them. Only keys defined
#    in Coolify are touched; values never reach the log.
env_file="/data/coolify/applications/${COOLIFY_UUID:-none}/.env"
if [ -n "${COOLIFY_API_TOKEN:-}" ] && [ -n "${APP_IMAGE:-}" ] && [ -f "$env_file" ]; then
    synced=$(mktemp)
    trap 'rm -f "$synced" "$synced.new"' EXIT
    # A blank, locked, preview-only, build-only or multi-line variable is left as Coolify last wrote it.
    if curl -fsS --retry 4 --retry-delay 10 --max-time 60 -H "Authorization: Bearer ${COOLIFY_API_TOKEN}" \
        "${COOLIFY_URL:-http://coolify:8080}/api/v1/applications/${COOLIFY_UUID}/envs" \
        | jq -r '.[] | select((.is_preview | not) and (.is_runtime != false))
                 | (.real_value // .value) as $v | select($v != null and ($v | contains("\n") | not))
                 | "\(.key)=\($v)"' > "$synced" && [ -s "$synced" ]; then
        # Replace each key's line (or append it), and print the names of the keys that changed.
        awk -v src="$synced" '
            BEGIN { while ((getline line < src) > 0) { k = substr(line, 1, index(line, "=") - 1); val[k] = line; order[++n] = k } }
            { k = substr($0, 1, index($0, "=") - 1) }
            k != "" && (k in val) { if (val[k] != $0) print "  changed: " k > "/dev/stderr"; print val[k]; seen[k] = 1; next }
            { print }
            END { for (i = 1; i <= n; i++) if (!(order[i] in seen)) { print "  added: " order[i] > "/dev/stderr"; print val[order[i]] } }
        ' "$env_file" > "$synced.new"
        if cmp -s "$synced.new" "$env_file"; then
            echo "Env vars: unchanged since Coolify's last write."
        else
            cp -p "$env_file" "$env_file.bak"
            cat "$synced.new" > "$env_file" # keeps the file's owner and mode
            echo "Env vars: updated from Coolify (previous file: .env.bak)."
        fi
    else
        echo "Env vars: could not read them from Coolify (the token needs read:sensitive); .env left as it is." >&2
    fi
fi
if [ -n "${APP_IMAGE:-}" ] && [ -n "${COOLIFY_UUID:-}" ] && [ ! -f "$env_file" ]; then
    echo "The app's .env is missing (${env_file}). Deploy the app resource once from Coolify to write it,"
    echo "or copy .env.bak back as .env on the server (DEPLOY.md)."
    exit 1
fi

# 2. The app's services share one image; sidecars (redis) have their own and stay out of it.
if [ -n "${APP_IMAGE:-}" ]; then
    target=$(compose config --format json \
        | jq -r '.services | to_entries[] | select(.key | test("^(migrate|web-[0-9]+|([a-z]+-)?queue|scheduler|maintenance)$")) | .value.image' \
        | sort -u)
    if [ "$(printf '%s\n' "$target" | wc -l)" != 1 ]; then
        echo "Expected one app image in the compose file, got: ${target}"
        exit 1
    fi
fi

# Coolify creates the stack's external networks when it deploys the app; create any that are missing.
for net in $(compose config --format json | jq -r '.networks // {} | to_entries[] | select(.value.external == true) | .value.name // .key'); do
    docker network inspect "$net" >/dev/null 2>&1 || { docker network create --attachable "$net" >/dev/null && echo "Network ${net} was missing; created it."; }
done

# From here on a Coolify restart or `compose up` starts this version. Running containers keep
# their old image until replaced.
if [ -n "${APP_IMAGE:-}" ]; then
    docker tag "$APP_IMAGE" "$target"
fi

compose run --rm migrate

# 3. Web containers, one at a time.
webs=$(compose config --services | grep -E '^web-[0-9]+$' | sort)
other_healthy() {
    local s id
    for s in $webs; do
        [ "$s" = "$1" ] && continue
        id=$(compose ps -q "$s")
        [ -n "$id" ] && [ "$(health "$id")" = healthy ] && return 0
    done
    return 1
}
for service in $webs; do
    old=$(compose ps -q "$service")
    if [ -n "$old" ]; then
        # Never drain the last healthy container without saying so: the maintenance page answers meanwhile.
        other_healthy "$service" || echo "No other web container is healthy: the maintenance page answers while ${service} restarts."
        echo "Draining ${service}..."
        # /up turns 500: Traefik's next check (every 5 s) takes it out of rotation, and requests
        # already inside finish before the container stops (stop_grace_period).
        docker exec "$old" touch storage/framework/draining || true
        sleep "${DRAIN_SECONDS:-12}"
    fi

    echo "Replacing ${service}..."
    compose up -d --no-deps --force-recreate "$service"
    container=$(compose ps -q "$service")
    status=starting
    for _ in $(seq 1 60); do
        status=$(health "$container")
        [ "$status" = healthy ] || [ "$status" = unhealthy ] && break
        sleep 2
    done
    if [ "$status" != healthy ]; then
        echo "${service} is ${status}; stopping the rollout. The other web containers are untouched."
        compose logs --tail 50 "$service" || true
        exit 1
    fi
    # Docker sees it healthy first; Traefik routes to it after its own next /up check (5 s).
    # Draining the next one sooner could leave none in rotation.
    sleep "${READY_SECONDS:-8}"
    echo "${service} is healthy."
done

# 4. Background services: SIGTERM lets each worker finish its current job; a short restart drops no web traffic.
compose up -d --no-deps --force-recreate $(compose config --services | grep -xE '([a-z]+-)?queue|scheduler|maintenance')

# Old images fill the VPS disk: keep the 3 newest builds for rollback, drop the rest and dangling layers.
# An image a container still uses stays (`docker rmi` refuses it).
if [ -n "${APP_IMAGE:-}" ]; then
    docker image ls --format '{{.Repository}}:{{.Tag}}' "${APP_IMAGE%:*}" | tail -n +4 | xargs -r docker rmi || true
fi
docker image prune -f >/dev/null || true
echo "Rolling deploy complete: ${APP_IMAGE:-the local build}."
