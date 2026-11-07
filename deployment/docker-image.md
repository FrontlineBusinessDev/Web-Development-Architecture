# Docker image

What the production image holds, how it is built, and how to check it after a change. Rule of thumb: the image ships only what runs.

- **Applies to:** every FBS Laravel app's `Dockerfile` and `.dockerignore`. Template: [templates/deploy-kit/Dockerfile](../templates/deploy-kit/Dockerfile).
- **Sources:** MCP and Work `CLAUDE.md` ("Ship only what runs"); MCP `Dockerfile` (exact tag, non-root, runtime-folder copy, strict `.dockerignore`); People `Dockerfile` (phpredis from git, upload limits); People `SYSTEM-RULES.md` rules 22 and 49 (checks in the build).
- **Last updated:** 2026-11-07

Related: [architecture.md](architecture.md), [publish-and-rollout.md](publish-and-rollout.md), [testing-and-ci.md](../quality/testing-and-ci.md), [environment-setup.md](../stack/environment-setup.md).

## Why it matters

Old images and build cache are what fill the VPS disk. A small image deploys faster, leaves less on the server and has less to attack. The checks run inside the build, so the image is also the proof that the code passed them.

## Stages

| Stage | From | Holds | Ships? |
| --- | --- | --- | --- |
| `phpredis` | FrankenPHP | a git clone of phpredis 6.3.0 (PECL times out; the release archive lacks the liblzf submodule) | no |
| `base` | FrankenPHP (exact tag) | PHP extensions, `php.ini-production`, upload limits | yes, as the final stage's base |
| `tools` | `base` | Composer 2, Node 22, git, unzip, **dev** dependencies, the whole source, `php.ini-development` | no |
| `checks` | `tools` | runs `composer ci:check` with a throwaway `.env` | no (only an empty marker file) |
| `assets` | `tools` | `npm run build`, with `ASSET_URL` as Vite's base only when the files go to the bucket (`ASSETS_UPLOADED=1`) | `public/build` only |
| `build` | `base` | `composer install --no-dev`, optimized autoloader, `octane:install`, the marker from `checks` | the runtime folders |
| final | `base` | the runtime folders, the non-root `app` user | yes |

**The gate.** BuildKit skips any stage the target does not use. The `build` stage copies `/tmp/ci-passed` from `checks`, so the checks always run, and a failing check fails the build: nothing deploys (rule 49). The copy sits after `composer install --no-dev`, so a code change still reuses the vendor layer.

**A plain build works (rule 67).** Coolify's Redeploy of the app builds the `Dockerfile` with no build args (and may pass the app's own variables, `ASSET_URL` among them). So the image never needs a value only `scripts/publish.sh` passes: with no `ASSETS_UPLOADED=1`, the `assets` stage ignores `ASSET_URL`, the image keeps its Vite files and touches `public/build/.in-image`, and the app serves them itself. The app's `config/app.php` reads it:

```php
'asset_url' => is_file(public_path('build/.in-image')) ? null : env('ASSET_URL'),
```

FBS People does the same with `ARG ASSETS=image|bucket` (`bucket` fetches the manifests and SSR bundle by `ASSET_HASH`).

**The PostgreSQL run.** A Docker build cannot start a database. `deploy/build-image.sh` builds the `checks` stage, starts a throwaway `postgres:17-alpine`, and runs `php artisan test` from the `checks` image against it before the final build ([testing-and-ci.md](../quality/testing-and-ci.md#the-postgresql-run)).

## What ships

The final stage copies only these, from `build`:

- `app`, `bootstrap`, `config`, `database` (migrations and the reference seeders run in `scripts/migrate.sh`), `public` (with `public/build`), `resources/views`, `routes`, `scripts`, `vendor`, `artisan`, `composer.json`
- the `storage` folder skeleton (its `.gitignore` files only), owned by `app`

Inertia SSR is off, so `resources/js` and Node are not needed at runtime. If a product turns SSR on, it ships `bootstrap/ssr` and the `node` binary (only the binary, no npm), and `scripts/deploy.sh` starts it.

## What never ships

- **Build tools and sources** stay in `tools` and `build`: Node, npm, Composer, `node_modules`, `package*.json`, `vite.config.ts`, `tsconfig.json`, `.npmrc`, `resources/js`, `resources/css`, `tests`, `phpunit.xml`, `phpstan.neon`, `pint.json`.
- **Never in any stage** (`.dockerignore`): `.git`, `.github`, `.env*` except `.env.example`, `*.sqlite` and database dumps, `docs`, `graphify-out`, `Claude outputs`, `node_modules`, `vendor`, `public/build`, `public/hot`, `storage` contents, `bootstrap/cache/*.php`, `docker-compose.yml`, `deploy/` except `deploy/*.sh`, editor and agent files (`CLAUDE.md`, `AGENTS.md`, `README.md`, `DEPLOY.md`, `.editorconfig`, `.claude`, `.idea`, `.vscode`).
- `tests/`, `phpunit.xml`, `phpstan.neon` and `pint.json` **are** in the build context, because the `checks` stage runs them. They never reach the final stage.
- **Dev packages:** the final vendor folder comes from `composer install --no-dev`. Never `composer install` with dev packages for production.
- **Secrets:** nothing secret is baked in. The app reads the server's `.env` at runtime. The `.env` in `checks` is a copy of `.env.example` with a fresh key, and it stays in that stage.

## Pinned tags

| Image | Tag |
| --- | --- |
| FrankenPHP | an exact tag in `ARG FRANKENPHP_IMAGE`, for example `dunglas/frankenphp:1.13.4-php8.4-bookworm`. Never `1-php8.4` or `latest`. Bump it on purpose, in its own commit. |
| Composer | `composer:2` (major pinned) |
| Node | `NODE_MAJOR=22`, the same major as `.nvmrc` |
| Redis | `redis:7.4-alpine` |
| PostgreSQL (test run) | `postgres:17-alpine`, the production major |
| Deployer | `docker:27-cli` |

## PHP extensions

`install-php-extensions` installs them with their build packages and removes the build packages after. The template installs:

`bcmath gd intl opcache pcntl pdo_pgsql pgsql zip redis`

Keep only what the app uses, and declare the same list in `composer.json` (`ext-*`) so a missing one fails `composer install` on every machine ([environment-setup.md](../stack/environment-setup.md#php-extensions)). `pdo_sqlite` comes with the base image (the tests use it).

## Runtime settings

- **Non-root.** The final image runs as `app` (uid 1000). Code stays owned by root; only `storage` and `bootstrap/cache` (and FrankenPHP's `/config` and `/data`) belong to `app`. `setcap -r` on the FrankenPHP binary, because port 8000 needs no capability and a binary with file capabilities fails under `no-new-privileges`. Only `migrate` runs as root, to write Traefik's route file.
- **Upload limits:** `upload_max_filesize = 10M`, `post_max_size = 64M`, `max_file_uploads = 50` (PHP's defaults drop files silently). Raise them above the app's own largest upload rule.
- **Logs:** `LOG_STACK=single,stderr`, so Coolify's log viewer shows them.
- **Health check:** `HEALTHCHECK` on `http://127.0.0.1:${PORT}/up` every 5 s (timeout 3 s, start period 30 s, 3 retries). Compose turns it off for the non-web services.
- **Start:** `CMD ["bash", "scripts/deploy.sh"]`: `php artisan optimize`, then `octane:start --server=frankenphp`. The image never migrates on start.
- **npm:** the build runs `npm ci`. A lockfile made on Windows can lack Linux binaries of optional packages; regenerate it, rather than switching to `npm install`.

## Cleaning up the VPS

- The rollout keeps the 3 newest builds of the app for rollback and removes the rest, then `docker image prune -f`.
- Keep Coolify's Docker cleanup on (Server → Settings). Otherwise, now and then on the server:

```bash
docker image prune -af
```

```bash
docker builder prune -af
```

- `deploy/build-image.sh` removes its temporary `checks` and `assets` images and the test database after each build.

## After any change

After touching the `Dockerfile`, `.dockerignore`, `docker-compose.yml` or `scripts/`, build the image and check that it still serves the app:

- [ ] The build passes, including `composer ci:check` in the `checks` stage.
- [ ] `/up` answers 200, and 500 after `touch storage/framework/draining`.
- [ ] Pages load with their CSS and JS (from the bucket when `ASSET_URL` is set).
- [ ] A file upload works and lands in the bucket under `AWS_BUCKET_FOLDER`.
- [ ] The queue worker picks up a job, and the scheduler runs its tasks.
- [ ] `scripts/migrate.sh` succeeds: migrations, reference seeders, Traefik route file.
- [ ] `docker run --rm --entrypoint id <image>` prints `uid=1000(app)`.
- [ ] `docker run --rm --entrypoint ls <image> -a /app` shows no `tests`, `node_modules`, `.env`, `resources/js` or `docs`.
- [ ] `docker image ls <image>` size is not much larger than the previous build.
