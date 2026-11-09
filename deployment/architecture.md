# Deployment architecture

How every FBS app runs in production: one Coolify VPS, Traefik in front, two web containers replaced one at a time, PostgreSQL, Redis and a private bucket.

- **Applies to:** every FBS Laravel app. New apps start from [templates/deploy-kit/](../templates/deploy-kit/).
- **Sources:** People (reference) `docker-compose.yml`, `docs/deployment/coolify-single-vps.md`, `deploy/*`; MCP `docs/deployment/zero-downtime.md` (maintenance failover, exact tag, non-root, queue timing); Learn `docs/deployment/coolify-single-vps.md` (route file, CPU and memory caps, assets in the bucket); Work `DEPLOY.md` (`onOneServer()`, env sync).
- **Last updated:** 2026-11-07

Related: [docker-image.md](docker-image.md) (what the image holds), [publish-and-rollout.md](publish-and-rollout.md) (how a deploy runs), [rules 10, 23, 33 and 50](../rules/system-rules.md).

## Topology

```
                Internet (80/443)
                       |
          Coolify's Traefik (TLS, Let's Encrypt)
          route file written by `migrate` from APP_URL
                       |
        +--------------+---------------+--------------------------+
        | health check /up every 5 s   |                          |
   __APP__-web-1                 __APP__-web-2          __APP__-maintenance
   Octane :8000                  Octane :8000           php -S :8080, 503 page
        |                              |                (only while no web passes /up)
        +---------------+--------------+
                        |
     queue, <name>-queue, scheduler   (same image, no traffic)
                        |
   __APP__-redis (stack)        PostgreSQL 18 (Coolify resource)     Bucket (DigitalOcean Spaces)
   cache, sessions, queue       backed up by Coolify                 {AWS_BUCKET_FOLDER}/...
```

Every name on Coolify's shared network starts with the app slug (`fbs-people-web-1`, `fbs-people-redis`). Other apps on the same server have a `web-1` too, and Coolify's own Redis answers to `redis`, which gives `WRONGPASS`.

## Coolify resources

| Resource | Build pack | Deploys | Notes |
| --- | --- | --- | --- |
| PostgreSQL 18 | Coolify database | n/a | Its internal hostname is the app's `DB_HOST`. Scheduled backups on. |
| App | Docker Compose (`docker-compose.yml`) | **Manual.** Deployed from Coolify once, to write its compose file and `.env`. | Every **Domains** field empty. Its Redeploy works (rule 67: the image builds its own Vite files) but restarts every container at once: for repairs, not releases. |
| Deployer | Dockerfile (`deploy/Dockerfile.deployer`) | Manual, started by `php artisan deploy` through Coolify's API | Mounts the Docker socket and the app's folder. Runs the rollout. Its Redeploy rolls the commit out again. |

Setup steps: [publish-and-rollout.md](publish-and-rollout.md#one-time-setup).

## Services

All app services run the same image (`<slug>-app:latest`). Caps are Compose `deploy.resources.limits`, each overridable by the env var shown.

| Service | Command | CPU | Memory | Override | Notes |
| --- | --- | --- | --- | --- | --- |
| `migrate` | `scripts/migrate.sh` | 0.5 | 512M | `MIGRATE_*` | One-off per deploy. Migrates, seeds reference data, writes the Traefik route. Runs as root (Traefik's folder). |
| `web-1`, `web-2` | `scripts/deploy.sh` (Octane) | 0.5 each | 576M each | `WEB_*` | `OCTANE_WORKERS` 1, `OCTANE_MAX_REQUESTS` 500. `stop_grace_period` 30 s. |
| `queue` | `scripts/worker.sh` | 0.1 | 384M | `QUEUE_*` | `stop_grace_period` 60 s. |
| `<name>-queue` | `scripts/worker.sh` | as needed | as needed | own vars | Optional: long jobs (backups, AI, exports) on their own worker. |
| `scheduler` | `php artisan schedule:work` | 0.05 | 192M | `SCHEDULER_*` | |
| `maintenance` | `php -S 0.0.0.0:8080 scripts/maintenance.php` | 0.02 | 32M | `MAINTENANCE_*` | Static 503, no database. |
| `redis` | `redis-server` (7.4) | 0.05 | 160M | `REDIS_*` | Not on Coolify's network. |

The default stack stays near 1.7 CPU and 2.4 GB in total. Uploads are scanned by FBS Suites' central scanner, not a clamd per stack. On a shared VPS, add up every app's caps and keep them under the machine, with room for PostgreSQL and Coolify.

## A request's path

1. Traefik ends TLS and matches the host from the route file `migrate` wrote (`/data/coolify/proxy/dynamic/<slug>.yml`).
2. The route's service is a **failover**: the web load balancer first, the maintenance page only when no web server passes its health check.
3. The load balancer sends the request to `web-1` or `web-2` (both health-checked on `/up` every 5 s).
4. Octane serves it from a long-lived worker. Code must be Octane-safe: no per-request state in statics or singletons, caches hold arrays, URLs come from `config('app.url')` ([Octane-safe code](../rules/coding-standards.md#8-octane-safe-code)).
5. Trusted proxies are `*`: safe only because the app publishes no port and Traefik is the only way in.

The `http` router redirects to `https`. **Every product redirects `www.`:** `migrate.sh` also writes routers for the other name (`www.<host>`, or `<host>` without `www.` when `APP_URL` is the `www.` one) that answer with a permanent redirect to `APP_URL`'s host, path kept (`https://www.pay.example.com/login` goes to `https://pay.example.com/login`). Traefik gets that name its own certificate, so it needs a DNS record pointing at the server; without one, only the redirect fails. Without this router a `www.` visit gets Coolify's own 503 page ("This site is not available at the moment"), because Coolify's **Domains** fields stay empty in the stack.

## No go-live switch

A stack has no variable that takes it off its own domain. `migrate.sh` writes the route on every deploy, always.

- **Why:** FBS CRM and FBS Connect (`TRAEFIK_ROUTE=off|on`) and FBS Pay (`STACK_LIVE=false|true`) moved off their old single-container app with such a switch: deploy the stack with it off, check it, flip it on, deploy again. It took a domain down more than once:
    - Coolify writes a changed variable to the server's `.env` only on the next deploy. A saved-but-pending `on` looked done and wasn't.
    - Every deploy rewrites `.env` from Coolify's saved values, so a fix made on the server (`sed` in `.env`, a hand-written route file) is undone by the next deploy while the switch reads `off`.
    - With the switch `off` and the old app stopped or converted in place, nothing holds the domain: every visit, `www.` included, gets Coolify's own 503 page ("This site is not available at the moment").
    - It doubles the deploys and the steps of a cutover, and a forgotten flip has no error, only a dead site.
- **Do:**
    - Check a new stack before it goes live through `php artisan deploy` on your PC (the gate) and the containers' own `/up` on the `coolify` network, not by keeping it off the domain.
    - Cut over in one sitting: **Stop** the old app (not Delete, it is the way back), then deploy the stack. The maintenance page answers until `web-1` passes `/up`.
    - Roll back by starting the old app and stopping the stack, not by a variable.
- **Don't:** add a variable whose wrong value leaves the domain without a route.
- **Known differences today:** FBS CRM and FBS Connect still read `TRAEFIK_ROUTE`. Once each is live on its stack, set it to `on` and delete the switch from `scripts/migrate.sh`, `docker-compose.yml` and DEPLOY.md, as FBS Pay did (it read `STACK_LIVE`).

## Health and drain

`/up` is Laravel's health route. The app adds a listener on `DiagnosingHealth` that fails when:

- the file `storage/framework/draining` exists (the rollout touched it), or
- `select 1` fails, or
- the queue's Redis does not answer `PING`.

Keep `/up` that cheap: it runs every 5 s in every web container. The listener is in [templates/deploy-kit/README.md](../templates/deploy-kit/README.md#add-to-the-app).

Draining a container: the rollout touches the flag, Traefik's next check (within 5 s) takes it out, `DRAIN_SECONDS` (12) lets requests inside finish, then the container is recreated and must report healthy before the next one is touched. Details: [publish-and-rollout.md](publish-and-rollout.md#what-the-deployer-does).

## Redis

- One Redis 7 per app stack, password required (`REDIS_PASSWORD`, `openssl rand -hex 32`, no `$` in it: Compose interpolates it).
- `appendonly yes` with `appendfsync everysec`: queued retries wait up to 12 h and must survive a restart.
- `maxmemory-policy volatile-lru`: only keys with a TTL (cache, sessions) are evicted, never queued jobs. `REDIS_MAXMEMORY` 96mb by default.
- Cache, sessions and queue all use it (`CACHE_STORE`, `SESSION_DRIVER`, `QUEUE_CONNECTION` = `redis`, client `phpredis`).

## PostgreSQL

- PostgreSQL 18 as a separate Coolify database resource, on Coolify's network, so Coolify backs it up and an app redeploy never touches it.
- `scripts/wait-for-db.sh` waits for it before `migrate` and the workers start.
- Migrations must work with the release still running: [database.md](../structure/database.md#migrations).

## Queues and the scheduler

- **Timing rule:** the connection's `retry_after` (90 s in `config/queue.php`) is longer than the worker's `--timeout` (30 s), which is longer than any job's own `$timeout` (at most 20 s). Otherwise a slow job runs twice.
- **Long jobs** (backups, AI turns, big exports) go on their own queue name, their own worker service (`<name>-queue` with `QUEUE_NAMES` and `QUEUE_TIMEOUT`), and their own connection whose `retry_after` is longer than that timeout (`QUEUE_WORKER_CONNECTION`).
- **Retries:** jobs are safe to run twice and set their own backoff (rule 33). The hub sender uses `[60, 300, 1800, 7200, 43200]` ([fbs-suites-hub.md](../integrations/fbs-suites-hub.md)).
- **Shutdown:** the worker is PID 1 (`exec`), so SIGTERM lets it finish the current job within `stop_grace_period`.
- **Scheduler:** one `schedule:work` container. Every schedule uses `->onOneServer()->withoutOverlapping()`, so a second scheduler during a rollout never runs a task twice.

## Files and object storage

- No file lives in a container. Uploads, exports and backups go to the private bucket (rule 10); the stack has no storage volume.
- One root folder per project and environment: `AWS_BUCKET_FOLDER` (rule 50). Layout: `{root}/uploads/...` (private, per tenant), `{root}/public/...` (logos), `{root}/build/...` (Vite files), backups in the same layout.
- **Static files:** when `ASSET_URL` is set (`{AWS_URL}/{AWS_BUCKET_FOLDER}`), the deploy uploads the Vite build to `{root}/build` before the image is built, and the image keeps only `manifest.json`. Hashed names are uploaded once and never deleted, so pages still open on an older release keep loading. Empty `ASSET_URL` means the app serves its own files. The bucket needs a CORS rule for the app's origin (`GET`, `HEAD`).

## Backups

| What | How |
| --- | --- |
| PostgreSQL | Coolify's scheduled backups of the database resource, to the bucket. Restore tested once per quarter. |
| Tenant data | The app's own per-tenant export and backup (rule 11), into the tenant's folder. |
| Redis | AOF on the `redis-data` volume. Only queue state matters; the cache can be lost. |
| Files | Already in the bucket. Turn on the bucket's versioning or a second-region copy if the provider offers it. |

## Environment variables (names only)

Infrastructure values live in the app resource's variables in Coolify. App-level keys (payment, AI, mail provider) are entered in the platform console, encrypted in the database (rule 12).

| Group | Keys |
| --- | --- |
| App | `APP_NAME`, `APP_ENV`, `APP_KEY`, `APP_URL`, `APP_DEBUG` (false), `TRUSTED_PROXIES` |
| Database | `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` (`DB_CONNECTION` is set by Compose) |
| Redis | `REDIS_PASSWORD`, `REDIS_MAXMEMORY` (host, client and drivers are set by Compose) |
| Octane | `OCTANE_WORKERS`, `OCTANE_MAX_REQUESTS` |
| Storage | `FILESYSTEM_DISK`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_ENDPOINT`, `AWS_URL`, `AWS_BUCKET_FOLDER`, `ASSET_URL` |
| Mail | `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` |
| FBS Suites hub | `FBS_HUB_URL`, `FBS_HUB_SECRET` |
| Virus check | `FBS_SCAN_URL`, `FBS_SCAN_SECRET`, `SCAN_REQUIRED` (FBS Suites' central scanner) |
| Stack | `COOLIFY_NETWORK`, `PROXY_DYNAMIC_DIR`, `TRAEFIK_HTTPS_ENTRYPOINT`, `TRAEFIK_HTTP_ENTRYPOINT`, `TRAEFIK_CERT_RESOLVER`, `*_CPUS`, `*_MEMORY` |
| Deployer resource | `COOLIFY_UUID`, `COOLIFY_URL`, `COOLIFY_API_TOKEN`, `DRAIN_SECONDS`, `READY_SECONDS`, `SKIP_PGSQL_TESTS` |

The full list per environment is in [stack/environment-setup.md](../stack/environment-setup.md).

## Known differences today

> Existing apps are left as they are (owner's decision, 2026-11-06). This list is a record, not a to-do: new applications follow the standard and must not repeat these. See [the new-project checklist](../docs-and-ai/new-project-checklist.md), section 11.

| App | Differs from the standard |
| --- | --- |
| People (reference) | Floating `1-php8.4` tag; runs as root; Traefik labels with a hard-coded domain and no maintenance fallback; a `storage-app` volume; a Caddy edge for company domains; checks not in the image build; GitHub workflows on `main`. |
| Learn | Floating tag; app-written Traefik file for many school hosts (keep it: it needs per-host routes); SSR on, so Node ships; only demo checks in the build; image `sub-sms-app-pc`; a plain build keeps its Vite files and `scripts/deploy.sh` uploads them at boot (`assets:sync`) instead of rule 67's `.in-image` marker. |
| FBS Suites MCP | No Octane (classic `frankenphp php-server`); two always-on web containers named `web-blue` / `web-green` (names kept from its older blue/green setup), replaced one at a time; two queue workers (`queue`, `queue-2`), `--tries=6`. |
| CRM | Dockerfile build pack with Coolify's rolling update and Pay's `<app uuid>:<commit>` PC image (rule 67); migrations and seeders on every container start; floating tag; deploys from `main`; `DB_PREFIX` table prefix. |
| Connect | As CRM; worker and scheduler are a separate Coolify resource redeployed by hand; deploys from `master` (`feat/multi-tenant` was merged into it on 2026-11-07 and is retired). |
| Pay | `serversideup/php:8.5-fpm-nginx` with s6 workers, no Octane; Coolify's own rolling update; database driver for cache, session and queue. |
| FCA-SMS | Dockerfile build pack with Coolify's rolling update; migrates and seeds on every boot; no Redis, no caps, no health checks in Compose; deploys from `main`. |
| Stock | `COPY . .` ships the sources; database driver for cache, session and queue; Traefik labels with a hard-coded domain; PHPUnit; JavaScript, not TypeScript. |
| Work | One `worker` container runs queue and schedule; supervisord in the image (Compose overrides it); Node 24; deploys from `main`; `www` redirect in the app. |
