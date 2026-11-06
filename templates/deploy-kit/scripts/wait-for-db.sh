#!/usr/bin/env bash
# Waits until DB_HOST:DB_PORT accepts TCP connections. Right after a Coolify restart the
# database may still be starting; without this, `migrate --force` fails and the race repeats.
set -euo pipefail
[ "${DB_CONNECTION:-pgsql}" = "sqlite" ] && exit 0

host="${DB_HOST:-127.0.0.1}"
port="${DB_PORT:-5432}"
echo "Waiting for database at ${host}:${port}..."
result=refused
for attempt in $(seq 1 30); do
    # Prints "ok", "dns" (the name doesn't resolve) or "refused" (resolves, nothing listening).
    result=$(php -r '$h = getenv("DB_HOST") ?: "127.0.0.1"; $p = (int) (getenv("DB_PORT") ?: 5432);
        if (! filter_var($h, FILTER_VALIDATE_IP) && gethostbyname($h) === $h) { echo "dns"; exit; }
        $c = @fsockopen($h, $p, $e, $s, 2); echo $c ? "ok" : "refused";')
    if [ "$result" = "ok" ]; then
        echo "Database is reachable."
        exit 0
    fi
    echo "Database not reachable yet (${result}, attempt ${attempt}/30), retrying in 2s..."
    sleep 2
done

echo "Database at ${host}:${port} did not become reachable after 30 attempts."
if [ "$result" = "dns" ]; then
    echo "The name '${host}' doesn't resolve from this container. Set DB_HOST to the database's"
    echo "internal hostname (Coolify -> the database -> General -> Internal URL), and check that"
    echo "this stack joins the database's network (COOLIFY_NETWORK, default 'coolify')."
else
    echo "'${host}' resolves, but nothing accepts connections on port ${port}. Check that the"
    echo "database is running and DB_PORT is its internal port (5432), not a public mapped port."
fi
exit 1
