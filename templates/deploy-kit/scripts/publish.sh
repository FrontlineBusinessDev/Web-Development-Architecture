#!/usr/bin/env bash
# Deploy from a PC: build the app image here (Docker Desktop), send it straight into the server's
# Docker over SSH, git push, then start the Coolify deployer through its API. The deployer finds the
# image there instead of building on the VPS (deploy/coolify-deploy.sh). Setup: DEPLOY.md.
#   php artisan deploy        (runs this script; from Git Bash: bash scripts/publish.sh)
# The deployer is on manual deploys, so a plain git push deploys nothing: only this script does.
# Settings come from .env.deploy (git-ignored, copy .env.deploy.example).
set -euo pipefail
export MSYS_NO_PATHCONV=1 # Git Bash: keep /var/run/docker.sock and /src as they are

cd "$(dirname "$0")/.."

# One deploy at a time. A lock whose process is gone is taken over.
lock="$(git rev-parse --git-dir)/publish.lock"
if ! mkdir "$lock" 2>/dev/null; then
    other=$(cat "$lock/pid" 2>/dev/null || true)
    if [ -n "$other" ] && kill -0 "$other" 2>/dev/null; then
        echo "Another deploy is running (process ${other}). Let it finish, or close its terminal first."
        exit 1
    fi
fi
echo $$ > "$lock/pid"
trap 'rm -rf "$lock"' EXIT

[ -f .env.deploy ] || { echo "No .env.deploy: copy .env.deploy.example and fill it in (DEPLOY.md)."; exit 1; }
# One value from .env.deploy. Stray whitespace (CRLF files) is dropped.
setting() { grep -m1 "^$1=" .env.deploy | cut -d= -f2- | tr -d '[:space:]' || true; }
DEPLOY_SSH=$(setting DEPLOY_SSH)
COOLIFY_URL=$(setting COOLIFY_URL)
COOLIFY_API_TOKEN=$(setting COOLIFY_API_TOKEN)
COOLIFY_DEPLOYER_UUID=$(setting COOLIFY_DEPLOYER_UUID)
for v in DEPLOY_SSH COOLIFY_URL COOLIFY_API_TOKEN COOLIFY_DEPLOYER_UUID; do
    [ -n "${!v}" ] || { echo "${v} is not set in .env.deploy (DEPLOY.md)."; exit 1; }
done
# Started directly, the app resource restarts every container at once: always start the deployer.
[ "$COOLIFY_DEPLOYER_UUID" != "$(setting COOLIFY_UUID)" ] || { echo "COOLIFY_DEPLOYER_UUID is the app's uuid: use the deployer's (DEPLOY.md)."; exit 1; }

docker info >/dev/null 2>&1 || { echo "Docker is not running: start Docker Desktop."; exit 1; }
# BatchMode fails fast instead of asking for a password (set up an SSH key first: DEPLOY.md).
on_server() { ssh -o BatchMode=yes -o ConnectTimeout=15 -o ServerAliveInterval=15 -o ServerAliveCountMax=8 "$DEPLOY_SSH" "$@"; }
on_server_has() { on_server "docker image inspect $1 >/dev/null 2>&1 && echo yes || echo no"; }
on_server docker version --format '{{.Server.Version}}' >/dev/null || { echo "Cannot run docker on ${DEPLOY_SSH} over SSH (DEPLOY.md)."; exit 1; }

# The image is built from the commit, not the working tree, so it is exactly what the server checks out.
branch=$(git rev-parse --abbrev-ref HEAD)
[ "$branch" = master ] || { echo "On ${branch}: deploys go from master."; exit 1; }
git diff --quiet HEAD || { echo "Uncommitted changes: commit them first (the image is built from the commit)."; exit 1; }
# The name deploy/coolify-deploy.sh looks for.
repo=__APP_SLUG__-pc
tag="${repo}:$(git rev-parse --short=12 HEAD)"

has=$(on_server_has "$tag") || { echo "Lost the connection to ${DEPLOY_SSH#*@}: try again."; exit 1; }
if [ "$has" = yes ]; then
    echo "${tag} is already on ${DEPLOY_SSH#*@}."
else
    if ! docker image inspect "$tag" >/dev/null 2>&1; then
        # Built with the server's own tools (deploy/Dockerfile.deployer), so the checks, the asset upload
        # and the image are the same wherever they run. A failing `composer ci:check` stops here.
        git archive --format=tar HEAD | docker build -q -f deploy/Dockerfile.deployer -t __APP_SLUG__-deployer:local - >/dev/null
        docker run --rm -v /var/run/docker.sock:/var/run/docker.sock --env-file .env.deploy \
            -e DOCKER_DEFAULT_PLATFORM=linux/amd64 __APP_SLUG__-deployer:local bash /src/deploy/build-image.sh "$tag"
    fi
    # A docs-only commit builds the same image (docs are not in the build context): if the server
    # already has it under an older tag, name it there instead of sending it again.
    id=$(docker image inspect -f '{{.Id}}' "$tag")
    if [ "$(on_server_has "$id" || true)" = yes ]; then
        on_server docker tag "$id" "$tag"
        echo "Same image as one already on ${DEPLOY_SSH#*@}: tagged it ${tag} there."
    else
        echo "Sending ${tag} to ${DEPLOY_SSH#*@} (a few hundred MB, started $(date +%H:%M))..."
        SECONDS=0
        # The image counts as sent once the server has it, even if the connection broke at the very end.
        docker save "$tag" | gzip -1 | on_server docker load || true
        [ "$(on_server_has "$tag" || true)" = yes ] || { echo "Sending ${tag} failed after $((SECONDS / 60)) min: run php artisan deploy again."; exit 1; }
        echo "Sent in $((SECONDS / 60)) min."
    fi
    # Older local builds; their layers stay in the build cache. The server keeps its 3 newest (rolling-deploy.sh).
    docker image ls --format '{{.Repository}}:{{.Tag}}' "$repo" | grep -vxF "$tag" | xargs -r docker rmi >/dev/null 2>&1 || true
fi

git push origin master

# Start the deployer (it checks out the master just pushed). The token needs Coolify's "deploy" permission.
reply=$(curl -sS -X POST --max-time 60 -w '\n%{http_code}' -H "Authorization: Bearer ${COOLIFY_API_TOKEN}" \
    "${COOLIFY_URL%/}/api/v1/deploy?uuid=${COOLIFY_DEPLOYER_UUID}") || true
if [ "${reply##*$'\n'}" = 200 ]; then
    echo "Sent ${tag}, pushed master and started the deploy: Coolify rolls it out without building."
    echo "Watch the deployer's Runtime Logs for \"Deploy succeeded\"."
else
    echo "Sent ${tag} and pushed master, but Coolify did not start the deploy. Coolify answered ${reply##*$'\n'}: ${reply%$'\n'*}"
    echo "Click Redeploy on the deployer in Coolify: it uses the image already on the server."
    exit 1
fi
