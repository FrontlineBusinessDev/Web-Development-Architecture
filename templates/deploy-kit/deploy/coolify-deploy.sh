#!/usr/bin/env bash
# Entry of the deployer container (deploy/Dockerfile.deployer): use the image a PC sent, or build
# it on the host's Docker, then the zero-downtime rollout (deploy/rolling-deploy.sh).
#
# Coolify, on the deployer resource:
#   Storages: /var/run/docker.sock and /data/coolify/applications/<app-uuid>, each at the same path.
#   Env: COOLIFY_UUID=<app-uuid>; COOLIFY_API_TOKEN (read, read:sensitive) + COOLIFY_URL=http://coolify:8080
#   to read the app's variables (bucket settings, env sync). Source commit "Available during build".
#
# It never exits: Docker would restart it and deploy again. The last log line is the result.
set -uo pipefail

: "${COOLIFY_UUID:?COOLIFY_UUID (the app resource uuid) is not set on the deployer resource.}"
dir="/data/coolify/applications/${COOLIFY_UUID}"
if [ ! -f "$dir/docker-compose.yaml" ]; then
    echo "No $dir/docker-compose.yaml: mount /data/coolify/applications/${COOLIFY_UUID} at the same path."
    exec sleep infinity
fi

# One rollout at a time: a newer deploy waits for the one still running.
exec 9>"$dir/.deploy.lock"
flock 9

# Run once per deployer container (rule 67): a VPS reboot or a container restart keeps the container, so it
# doesn't deploy again; every Coolify deploy, Redeploy or rollback starts a new container, which rolls out (again).
container=$(hostname)
id=$(docker inspect -f '{{.Image}}' "$container" | sed 's/^sha256://' | cut -c1-12)
if [ -n "$container" ] && [ "$(cat "$dir/.deployed" 2>/dev/null)" = "$container" ]; then
    echo "This deployer container already deployed build ${id}. Redeploy the deployer in Coolify to roll out again."
    flock -u 9
    exec sleep infinity
fi

# Not "__APP_SLUG__-app": that is the tag the compose file runs (rolling-deploy.sh sets it).
image="__APP_SLUG__-build:${id:-$(date +%Y%m%d%H%M%S)}"

# Sent by scripts/publish.sh as __APP_SLUG__-pc:<first 12 characters of the commit>: use it.
built=""
pc_image="__APP_SLUG__-pc:$(printf '%s' "${SOURCE_COMMIT:-}" | cut -c1-12)"
if [ -z "${SOURCE_COMMIT:-}" ]; then
    echo "SOURCE_COMMIT is empty: set Source commit to \"Available during build\". Building here."
elif docker image inspect "$pc_image" >/dev/null 2>&1; then
    echo "${pc_image} is already on this server (sent from a PC): nothing to build here."
    image="$pc_image"
    built=1
elif docker image inspect "$image" >/dev/null 2>&1; then
    # A Redeploy of the same commit: the image this deployer built last time is still here (the one running).
    echo "${image} was built here before: rolling it out again."
    built=1
else
    echo "No ${pc_image} on this server (not sent with php artisan deploy): building here."
fi

if { [ -n "$built" ] || bash /src/deploy/build-image.sh "$image"; } \
    && APP_IMAGE="$image" COMPOSE_ARGS="-p ${COOLIFY_UUID} -f ${dir}/docker-compose.yaml" bash /src/deploy/rolling-deploy.sh; then
    echo "$container" > "$dir/.deployed"
    echo "Deploy succeeded: ${image}."
else
    echo "Deploy FAILED: ${image}. The site keeps serving the containers that were healthy."
fi
flock -u 9
exec sleep infinity
