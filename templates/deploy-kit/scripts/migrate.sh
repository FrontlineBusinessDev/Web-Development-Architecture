#!/usr/bin/env bash
# The only place production migrates: the compose `migrate` service runs it once per deploy,
# while the old web containers keep serving. Migrations must be backward compatible
# (add first, drop in a later deploy: structure/database.md).
set -euo pipefail
export LOG_STACK="${LOG_STACK:-single,stderr}"

# Coolify's deploy log shows only "exit 1": print what failed and the migration state.
trap 'echo "migrate.sh failed: \"${BASH_COMMAND}\" (exit $?)." >&2;
      php artisan about --only=environment,drivers >&2 || true;
      php artisan migrate:status >&2 || true' ERR

bash scripts/wait-for-db.sh
php artisan migrate --force -v
# Reference data only (plans, permissions, lookup lists), written with firstOrCreate so a re-run
# never overwrites edits. DatabaseSeeder must skip demo rows when APP_ENV=production.
php artisan db:seed --force

# Traefik route: APP_URL's host -> web-1 and web-2, health-checked on /up, with the static
# maintenance page as failover. Traefik watches its dynamic folder, so a changed APP_URL applies
# on the next deploy with no proxy restart. Coolify's Domains fields stay empty.
if [ -n "${PROXY_ROUTES_FILE:-}" ]; then
    host="${APP_URL#*://}"
    host="${host%%/*}"
    host="${host%%:*}"
    if ! [[ "$host" =~ ^[a-z0-9.-]+$ ]]; then
        echo "migrate.sh: APP_URL host '${host}' is not a plain hostname; Traefik route not written." >&2
        exit 1
    fi
    https="${TRAEFIK_HTTPS_ENTRYPOINT:-https}"
    http="${TRAEFIK_HTTP_ENTRYPOINT:-http}"
    resolver="${TRAEFIK_CERT_RESOLVER:-letsencrypt}"
    # The other name (www.<host>, or <host> without www.) answers with a permanent redirect to <host>.
    www="www.${host#www.}"
    [ "$www" = "$host" ] && www="${host#www.}"
    # Write then rename: Traefik never reads a half-written file.
    cat > "${PROXY_ROUTES_FILE}.tmp" <<YAML
# Written by scripts/migrate.sh on every deploy. Do not edit: the next deploy overwrites it.
http:
  routers:
    __APP_SLUG__:
      rule: "Host(\`${host}\`)"
      entryPoints: [${https}]
      service: __APP_SLUG__
      tls:
        certResolver: ${resolver}
    __APP_SLUG__-http:
      rule: "Host(\`${host}\`)"
      entryPoints: [${http}]
      service: __APP_SLUG__
      middlewares: [__APP_SLUG__-https]
    __APP_SLUG__-www:
      rule: "Host(\`${www}\`)"
      entryPoints: [${https}]
      service: __APP_SLUG__
      middlewares: [__APP_SLUG__-to-host]
      tls:
        certResolver: ${resolver}
    __APP_SLUG__-www-http:
      rule: "Host(\`${www}\`)"
      entryPoints: [${http}]
      service: __APP_SLUG__
      middlewares: [__APP_SLUG__-to-host]
  middlewares:
    __APP_SLUG__-https:
      redirectScheme:
        scheme: https
        permanent: true
    __APP_SLUG__-to-host:
      redirectRegex:
        regex: "^https?://[^/]+(.*)"
        replacement: "https://${host}\${1}"
        permanent: true
  services:
    # No healthCheck on the failover itself: Traefik would then want one on the fallback too.
    __APP_SLUG__:
      failover:
        service: __APP_SLUG__-app
        fallback: __APP_SLUG__-maintenance
    __APP_SLUG__-app:
      loadBalancer:
        passHostHeader: true
        healthCheck:
          path: /up
          interval: 5s
          timeout: 3s
        servers:
          - url: http://__APP_SLUG__-web-1:8000
          - url: http://__APP_SLUG__-web-2:8000
    __APP_SLUG__-maintenance:
      loadBalancer:
        servers:
          - url: http://__APP_SLUG__-maintenance:8080
YAML
    mv "${PROXY_ROUTES_FILE}.tmp" "$PROXY_ROUTES_FILE"
    echo "migrate.sh: Traefik route for ${host} (and ${www}, redirected) written to ${PROXY_ROUTES_FILE}."
fi
echo "migrate.sh: done."
