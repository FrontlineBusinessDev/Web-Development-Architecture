#!/usr/bin/env bash
# Queue worker service (docker-compose.yml: queue, and any <name>-queue).
# Timing rule: the connection's retry_after (config/queue.php, 90) > --timeout (30) > every
# job's own $timeout (at most 20). A queue with longer jobs gets its own connection and service.
set -euo pipefail
export LOG_STACK="${LOG_STACK:-single,stderr}"

bash scripts/wait-for-db.sh

args=()
if [ -n "${QUEUE_WORKER_CONNECTION:-}" ]; then
    args+=("$QUEUE_WORKER_CONNECTION") # empty = QUEUE_CONNECTION
fi

# exec: PHP is PID 1, gets SIGTERM directly and finishes its current job before exiting.
exec php artisan queue:work "${args[@]}" \
    --queue="${QUEUE_NAMES:-default}" \
    --timeout="${QUEUE_TIMEOUT:-30}" \
    --tries="${QUEUE_TRIES:-3}" \
    --backoff=10 \
    --max-time=3600 \
    --memory=256 \
    --sleep=1
