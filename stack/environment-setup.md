# Environment setup

The PHP version, extensions, local tools and environment keys every developer machine and every image must match.

- **Applies to:** every FBS Laravel app, on every developer machine, in the Docker image and in the build gate.
- **Sources:** Learn `docs/reusable-components-in-all-projects.md` section 12; People `Dockerfile`, `docker/php/uploads.ini`, `.env.example`, `composer.json`; Learn and People `.php-version`.
- **Last updated:** 2026-11-06

## The goal: one environment everywhere

The same PHP version and the same extension list on every laptop, in the Docker build and in production. A missing extension must fail at `composer install`, not at runtime on the server.

## Version files

Commit these at the repo root:

| File | Content |
|---|---|
| `.php-version` | `8.4` |
| `.nvmrc` | `22` |
| `package.json` | `"engines": { "node": "^22" }` |
| `.editorconfig` | UTF-8, LF, 4 spaces (YAML 2) |

## PHP extensions

| Extension | Needed for | Declare in `composer.json` | In the image | Windows dev |
|---|---|---|---|---|
| `mbstring` | Laravel, PDFs, search | yes | built in | on |
| `openssl` | Laravel, certificates | yes | built in | on |
| `curl` | `Http::` client | yes | built in | on |
| `fileinfo` | Flysystem, uploads | yes | built in | on |
| `bcmath` | money maths (`bcadd`, `bcsub`) | yes | install | enable |
| `gd` (with FreeType) | images in PDFs, thumbnails | yes | install | enable |
| `intl` | number and date formatting | only if used | install | enable |
| `zip` | Composer, imports, exports | yes | install | on |
| `pdo_sqlite` | tests (SQLite in memory) | `require-dev` | not needed | on |
| `pdo_pgsql` + `pgsql` | production PostgreSQL | yes | install | enable |
| `redis` (phpredis) | cache, session, queue | yes | build from source (pinned tag) | enable |
| `opcache` | production speed | no | install | optional |
| `pcntl`, `posix` | Octane and queue signals (Linux only) | **no** (not on Windows) | install | n/a |

Trim the list to what the app really uses. Add only extensions the code calls.

### Declare them in `composer.json`

```json
"require": {
    "php": "^8.4",
    "ext-bcmath": "*", "ext-curl": "*", "ext-fileinfo": "*", "ext-gd": "*",
    "ext-mbstring": "*", "ext-openssl": "*", "ext-pdo_pgsql": "*",
    "ext-redis": "*", "ext-zip": "*"
},
"require-dev": {
    "ext-pdo_sqlite": "*"
}
```

- Do not declare `ext-pcntl` or `ext-posix`.
- Do not hide a missing extension with `config.platform`. That hides the failure this list exists to show.
- Check any machine with `composer check-platform-reqs`. Run it after every PHP upgrade.

## php.ini

| Setting | Value | Why |
|---|---|---|
| `upload_max_filesize` | above the largest upload the app accepts (People: `10M`) | PHP's 2M default drops files silently |
| `post_max_size` | above the largest request (People: `64M`) | same |
| `max_file_uploads` | `50` | multi-file uploads |

The image copies these from `docker/php/uploads.ini` into `$PHP_INI_DIR/conf.d/`. Set the same values on your machine.

## Windows developer setup

1. Install PHP 8.4 with Herd or Laragon, Node 22, Composer 2 and Git for Windows (Git Bash runs `scripts/publish.sh`).
2. Enable in the active `php.ini`: `gd`, `bcmath`, `zip`, `pdo_pgsql`, `pgsql`, `redis`, and `intl` if used. Restart PHP.
3. Raise the upload limits above.
4. Check with `php -v`, `php -m` and `composer check-platform-reqs`.
5. If a machine cannot get `pdo_pgsql` or `redis`, run the app in a dev container. Do not weaken `composer.json`.

Octane does not run on Windows. Local development uses `composer run dev` (PHP's server, the queue listener and Vite). Octane is production only, so write Octane-safe code anyway ([backend patterns](../structure/backend-patterns.md#octane-safety)).

## First run

```bash
composer setup          # composer install, .env from .env.example, key:generate, migrate, npm install, npm run build
composer run dev        # server + queue + Vite
php artisan test        # must be green before your first commit
```

A project without a `composer setup` script runs the steps by hand: `composer install`, `npm install`, `cp .env.example .env`, `php artisan key:generate`, `php artisan migrate --seed`.

Laravel's `@vite` directive throws `ViteManifestNotFoundException` without `npm run dev` or `npm run build`.

## Environment keys

Only names are listed here. Never write a real value into a doc, a commit or a chat.

| Group | Keys |
|---|---|
| App | `APP_NAME`, `APP_ENV`, `APP_KEY`, `APP_DEBUG`, `APP_URL`, `APP_LOCALE`, `TRUSTED_PROXIES` |
| Logs | `LOG_CHANNEL`, `LOG_STACK` (`single,stderr` in containers), `LOG_LEVEL` |
| Database | `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` (no `DB_PREFIX`, see [database](../structure/database.md)) |
| Redis | `REDIS_CLIENT` (`phpredis`), `REDIS_HOST`, `REDIS_PASSWORD`, `REDIS_PORT` |
| Drivers | `CACHE_STORE`, `SESSION_DRIVER`, `QUEUE_CONNECTION` (`redis` in production), `SESSION_SECURE_COOKIE` (`true` behind HTTPS) |
| Octane | `OCTANE_SERVER`, `OCTANE_WORKERS`, `OCTANE_MAX_REQUESTS` |
| Storage | `FILESYSTEM_DISK`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_ENDPOINT`, `AWS_URL`, `AWS_USE_PATH_STYLE_ENDPOINT`, `AWS_BUCKET_FOLDER`, `ASSET_URL` |
| Mail | `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` |
| Sign-in | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `MICROSOFT_CLIENT_ID`, `MICROSOFT_CLIENT_SECRET`, `MICROSOFT_TENANT` |
| Push | `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT` |
| FBS Suites hub | `FBS_HUB_URL`, `FBS_HUB_SECRET` ([hub contract](../integrations/fbs-suites-hub.md)) |
| AI | `AI_ENABLED`, `ANTHROPIC_API_KEY` |
| Deploy stack | `COOLIFY_NETWORK`, `*_CPUS`, `*_MEMORY` ([deployment architecture](../deployment/architecture.md)) |

Rules:

- `.env`, `.env.deploy` and every `.env.*` except `.env.example` (and `.env.deploy.example`) are git-ignored. Never commit them.
- `.env.example` lists every key that `config/*` reads, with an empty or safe value. A key the config reads and the example lacks is a bug.
- Keep `$` out of `REDIS_PASSWORD`: Docker Compose interpolates it.
- Secrets a tenant or the platform owns (gateway keys, AI keys) live encrypted in the database and are managed in the platform console, not in `.env` (People rule 12).
- Build links from `config('app.url')`, never from the request's Host header.

## Known differences today

> Existing apps are left as they are (owner's decision, 2026-11-06). This list is a record, not a to-do: new applications follow the standard and must not repeat these. See [the new-project checklist](../docs-and-ai/new-project-checklist.md), section 11.

| App | Differs from the standard |
|---|---|
| CRM, Connect, Pay, Stock, Work, MCP | No `ext-*` entries in `composer.json` |
| Learn | `ext-gd` and `ext-pdo_pgsql` not declared yet (the reference Windows machine lacked them) |
| All but Learn and People | No `.php-version`; all but Learn have no `.nvmrc` |
| Stock, Pay | Session, cache and queue on the `database` driver, not Redis |
| People, FCA-SMS | Deploy env example is named `.envdeploy.example`; Stock and Pay use `.env.example.deploy`; the standard name is `.env.deploy.example` (MCP, Work) |
| Stock | `.env.example.deploy` contains a real server address in a comment |
| CRM, Connect | No deploy env example committed |
