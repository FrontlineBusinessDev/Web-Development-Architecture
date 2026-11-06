# Deploy kit

Ready-to-copy files for the standard deploy: FrankenPHP + Octane on one Coolify VPS, built on your PC, rolled out one web container at a time by a deployer resource.

Applies to: every new Laravel app, and any app moving onto the standard deploy.
Sources: FBS People (reference), with fixes proved in FBS Suites MCP, FBS Learn and FBS Work.
Last updated: 2026-11-06

Read first: [deployment/architecture.md](../../deployment/architecture.md), [deployment/docker-image.md](../../deployment/docker-image.md), [deployment/publish-and-rollout.md](../../deployment/publish-and-rollout.md).

## Copy

Copy the folder's contents into the project root, keeping the paths:

| File | Goes to |
| --- | --- |
| `Dockerfile`, `.dockerignore`, `docker-compose.yml` | project root |
| `DEPLOY.md`, `.env.deploy.example` | project root |
| `scripts/*.sh`, `scripts/maintenance.php` | `scripts/` |
| `deploy/*` | `deploy/` |

Then make the scripts executable in git: `git update-index --chmod=+x scripts/*.sh deploy/*.sh`.

## Replace the placeholders

| Placeholder | Meaning | Example |
| --- | --- | --- |
| `__APP_SLUG__` | Short kebab-case name. It names the images, the network aliases on Coolify's shared network, the Traefik routers and the route file. Unique per server. | `fbs-people` |
| `__APP_NAME__` | The product name people read (maintenance page, DEPLOY.md). | `FBS People` |

```bash
grep -rl '__APP_SLUG__\|__APP_NAME__' . --exclude-dir={vendor,node_modules,.git} \
  | xargs sed -i -e 's/__APP_SLUG__/fbs-people/g' -e 's/__APP_NAME__/FBS People/g'
```

Then check what stays project-specific:

- `Dockerfile`: the PHP extension list (only what the app uses, `stack/environment-setup.md`) and the exact `FRANKENPHP_IMAGE` tag.
- `docker-compose.yml`: extra queues (`<name>-queue` with `QUEUE_NAMES`, `QUEUE_TIMEOUT`, `QUEUE_WORKER_CONNECTION`), CPU and memory caps.
- `scripts/migrate.sh`: the seeder line (reference data only).
- `scripts/maintenance.php`: the paths that should get JSON (default `/api`).

## Add to the app

The scripts expect these pieces of app code.

**`php artisan deploy`** in `routes/console.php`:

```php
Artisan::command('deploy', function () {
    if (app()->isProduction()) {
        $this->error('Run this on your PC, not on the server.');

        return 1;
    }
    $bash = PHP_OS_FAMILY === 'Windows' ? (getenv('GIT_BASH') ?: 'C:\Program Files\Git\bin\bash.exe') : 'bash';

    return Process::forever()->path(base_path())
        ->run([$bash, 'scripts/publish.sh'], fn (string $type, string $out) => $this->output->write($out))
        ->exitCode();
})->purpose('Build the image on this PC, send it to the server over SSH, push master and start the deployer');
```

**`/up` fails while draining** (`app/Listeners/FailHealthWhenDraining.php`, listening to `Illuminate\Foundation\Events\DiagnosingHealth`):

```php
public function handle(DiagnosingHealth $event): void
{
    if (is_file(storage_path('framework/draining'))) {
        throw new RuntimeException('Draining for a deploy.');
    }
    DB::select('select 1');
    if (config('queue.default') === 'redis') {
        Redis::connection(config('queue.connections.redis.connection', 'default'))->ping();
    }
}
```

**Also:**

- `composer ci:check` and the npm scripts it calls ([quality/testing-and-ci.md](../../quality/testing-and-ci.md)).
- `config/octane.php` committed.
- `config/queue.php`: redis `retry_after` 90; no job `$timeout` above 20.
- Every schedule in `routes/console.php` uses `->onOneServer()->withoutOverlapping()`.
- Trusted proxies read `TRUSTED_PROXIES` (`bootstrap/app.php`).
- `ASSET_URL` in the app's variables when the Vite files are served from the bucket.
- `.env.deploy` in `.gitignore`.

## Check after copying

Build the image and check it still serves the app: `/up` passes, pages load with their assets, uploads work, the queue worker and the scheduler run, and `scripts/migrate.sh` succeeds. The full list is in [deployment/docker-image.md](../../deployment/docker-image.md#after-any-change).
