# Publish and rollout

How a change gets from a developer's PC to production with no downtime, and how to undo it.

- **Applies to:** every FBS Laravel app on the standard deploy. Each project's own steps live in its `DEPLOY.md` ([template](../templates/deploy-kit/DEPLOY.md)).
- **Sources:** People `DEPLOY.md`, `scripts/publish.sh`, `deploy/*`; MCP `scripts/publish.sh` (lock, image-arrived check), `deploy/rolling-deploy.sh` (keep 3 builds); Learn `scripts/publish.sh` (docs-only retag); Work `deploy/rolling-deploy.sh` (env sync).
- **Last updated:** 2026-11-07

Related: [architecture.md](architecture.md), [docker-image.md](docker-image.md), [git-workflow.md](../rules/git-workflow.md), [rule 23](../rules/system-rules.md#rule-23-safe-deploys-and-migrations).

## The short version

```
php artisan deploy (your PC, on master, clean tree)
  -> build in the deployer image: composer ci:check, tests on PostgreSQL, Vite files to the bucket, image
  -> docker save | gzip | ssh <server> docker load        (no registry)
  -> git push origin master
  -> POST {COOLIFY_URL}/api/v1/deploy?uuid=<deployer>
       -> deployer: finds <slug>-pc:<commit>, migrates, replaces web-1 then web-2, restarts workers
```

Rules:

- A plain `git push` deploys nothing: the deployer and the app are on manual deploys in Coolify. Releases go out with `php artisan deploy`.
- Deploys go from `master`, from a commit: the image is built from `git archive HEAD`, never the working tree.
- Redeploy in Coolify works on every resource (rule 67, [below](#redeploy-in-coolify)). On the **deployer** it is a zero-downtime rollout; on the **app** it rebuilds and restarts every container at once, so keep it for repairs.
- Migrations must work with the release still running ([database.md](../structure/database.md#migrations)).

## Redeploy in Coolify

Every resource can be deployed or redeployed from Coolify at any time, with or without a PC (rule 67).

| Pattern | Resource | Redeploy does |
| --- | --- | --- |
| Compose app + deployer (the standard, FBS People) | **Deployer** | Rolls the branch's commit out again, one web container at a time: the PC's `<slug>-pc:<commit>` when it is there, else the image it built last time, else a build on the server. |
| | **App** | Builds the `Dockerfile` with no build args: the image builds its own Vite files (`ASSETS=image` in FBS People, `ASSETS_UPLOADED=0` in the template) and marks them (`public/build/.in-image`), so the app serves them itself. Restarts every container at once. |
| Dockerfile app + Coolify's rolling update (FBS Pay, FBS Connect, FBS CRM) | **App** | Finds the PC's `<app uuid>:<full commit>` ("Build step skipped") or builds the full `Dockerfile` on the server ("Image not found ... Building new image"), then Coolify's rolling update. |
| Either | **Worker** resource | Builds from source and restarts the workers. |

Never point a resource at an image only a PC provides (`FROM <slug>-pc:latest`), and never let a build refuse to run without values only `scripts/publish.sh` passes: Docker cleanup or a fresh server then leaves the button broken.

## One-time setup

### On the server (once per server)

1. **PostgreSQL 18** as a Coolify database resource. Scheduled backups on.
2. **App** resource: Docker Compose, `docker-compose.yml`, manual deploys, every Domains field empty. Fill in its variables ([architecture.md](architecture.md#environment-variables-names-only)), then deploy it **once** from Coolify: that writes `/data/coolify/applications/<app-uuid>/docker-compose.yaml` and `.env`.
3. **Deployer** resource: Dockerfile, `deploy/Dockerfile.deployer`, same repository and branch `master`, manual deploys.
    - Storages: `/var/run/docker.sock` and `/data/coolify/applications/<app-uuid>`, each mounted at the same path.
    - Env: `COOLIFY_UUID=<app-uuid>`, `COOLIFY_URL=http://coolify:8080`, `COOLIFY_API_TOKEN` (read, read:sensitive).
    - Source commit: **Available during build** (the deployer gets `SOURCE_COMMIT`).
4. DNS A record of the domain → the server. Traefik gets the certificate.
5. The bucket's CORS rule for the app's origin (`GET`, `HEAD`) when `ASSET_URL` is set.

### On each developer's PC

1. Docker Desktop and Git Bash (Windows).
2. An SSH key (`ssh-keygen -t ed25519`), its public line **appended** (`>>`) to the server's `~/.ssh/authorized_keys`. A single `>` erases Coolify's own key.
3. Connect once to trust the server: `ssh root@<server> docker version`.
4. `.env.deploy` from `.env.deploy.example`, git-ignored:

| Key | Value |
| --- | --- |
| `DEPLOY_SSH` | `root@<server>` |
| `COOLIFY_URL` | the Coolify dashboard address |
| `COOLIFY_UUID` | the app resource's uuid |
| `COOLIFY_DEPLOYER_UUID` | the deployer resource's uuid (never the app's) |
| `COOLIFY_API_TOKEN` | a personal Coolify token with **read**, **read:sensitive** and **deploy** |

## Every deploy

On your PC, in the project folder: commit on `master`, start Docker Desktop, run `php artisan deploy`.

What `scripts/publish.sh` does:

1. Takes a lock (`.git/publish.lock`): one deploy at a time per clone; a lock whose process is gone is taken over.
2. Reads `.env.deploy` and refuses when a key is missing, or when the deployer uuid is the app's.
3. Checks Docker locally and over SSH (`BatchMode`, keep-alives), the branch (`master`) and a clean tree.
4. Names the image `<slug>-pc:<first 12 characters of the commit>`. If the server already has it, skips to step 7.
5. Builds it: `git archive HEAD` into the deployer image, which runs `deploy/build-image.sh` against the PC's Docker:
    1. the `checks` stage (`composer ci:check`);
    2. the test suite on a throwaway PostgreSQL 18;
    3. when `ASSET_URL` is set, the Vite build, uploaded to `{bucket}/{AWS_BUCKET_FOLDER}/build` (new files only);
    4. the final image (with only the Vite manifest when the files went to the bucket).

    Any failure stops here. Nothing is sent and nothing is pushed.
6. Sends it: if the server already has an image with the same id (a docs-only commit), it only tags it there. Otherwise `docker save | gzip | ssh docker load`, then checks the server really has it.
7. `git push origin master`.
8. Starts the deployer: `POST /api/v1/deploy?uuid=<deployer>`. A non-200 answer is printed; the image is already on the server, so Redeploy on the deployer finishes the job.

## What the deployer does

`deploy/coolify-deploy.sh` (the deployer container's entry) then `deploy/rolling-deploy.sh`:

1. Takes a `flock` on the app folder: one rollout at a time on the server.
2. Skips when this deployer **container** already deployed (`.deployed` holds its hostname), so a VPS reboot or a container restart never redeploys, while every Coolify Deploy, Redeploy or rollback (a new container) rolls out.
3. Uses `<slug>-pc:<SOURCE_COMMIT>` when a PC sent it; else the image it built last time for this commit (`<slug>-build:<id>`); otherwise builds on the server with the same `build-image.sh` (slower, same checks).
4. Copies the app's Coolify variables into its `.env` (keys defined in Coolify only; the old file stays as `.env.bak`). So "Save in Coolify, then `php artisan deploy`" applies a variable change.
5. Checks the compose file runs exactly one app image, creates any missing external network, and tags the new image as `<slug>-app:latest`.
6. `compose run --rm migrate`: migrations, reference seeders and the Traefik route file, while the old containers keep serving.
7. For `web-1`, then `web-2`:
    1. if the other web container is not healthy, says so (the maintenance page answers meanwhile);
    2. touches `storage/framework/draining`: `/up` fails and Traefik stops routing to it within 5 s;
    3. waits `DRAIN_SECONDS` (12) for requests inside to finish;
    4. recreates it on the new image and waits up to 120 s for it to report healthy;
    5. if it never does, prints its last log lines and **stops the rollout**: the other container keeps serving the old version;
    6. waits `READY_SECONDS` (8) so Traefik routes to it again before the next one is drained.
8. Recreates the queue workers, the scheduler and the maintenance page (each worker finishes its current job first).
9. Keeps the 3 newest builds, removes older ones and dangling layers.
10. Logs `Deploy succeeded: <image>.` or `Deploy FAILED: <image>.` as its last line, then sleeps (Docker would otherwise restart it and deploy again).

## Rollback

| Situation | Do |
| --- | --- |
| The new release misbehaves | Coolify → Deployer → Deployments → roll back to the previous deployment. Its older image rolls that version out the same way (from the server's 3 kept builds, or rebuilt). |
| No deployer history to use | In the running deployer container (it stays up after each deploy), with one of the 3 kept images: `APP_IMAGE=<slug>-pc:<older commit> COMPOSE_ARGS="-p <app-uuid> -f /data/coolify/applications/<app-uuid>/docker-compose.yaml" bash /src/deploy/rolling-deploy.sh`. `docker image ls <slug>-pc` lists them. |
| A feature is risky | Turn its feature flag off instead of rolling back (rule 23). |

Migrations never roll back on their own. Because they are backward compatible, the older code runs on the newer schema. A destructive migration needs its own reviewed plan and a backup first.

## Check it worked

Coolify → Deployer → **Runtime Logs** (not Deployment Logs). The last line says `Deploy succeeded` or `Deploy FAILED`. Then open the site, and check one page that the change touched.

## Troubleshooting

| Message | Cause and fix |
| --- | --- |
| `Docker is not running` | Start Docker Desktop and wait for *Engine running*. |
| `No .env.deploy` / `... is not set in .env.deploy` | Create or complete `.env.deploy`. |
| `Cannot run docker on ... over SSH`, `Permission denied (publickey)` | The key is not in the server's `authorized_keys`, or the server was never trusted. |
| `Uncommitted changes` | Commit first. |
| `On <branch>: deploys go from master` | Merge into `master` first ([git-workflow.md](../rules/git-workflow.md)). |
| `Another deploy is running` | Wait, or close the terminal that runs it. |
| `composer ci:check failed` | Run `composer ci:check` locally and fix it. Never skip a check to deploy. |
| `The tests failed on PostgreSQL` | A query that works on SQLite fails on PostgreSQL ([database.md](../structure/database.md#postgresql-gotchas)). |
| `ASSET_URL is set but AWS_... is not` | The token lacks `read:sensitive`, or the app's `AWS_*` variables are empty. |
| `Sending ... failed` | The connection dropped. Run `php artisan deploy` again: a finished build is reused. |
| `Coolify answered 401/403` | The token lacks **deploy**. Make a new one (permissions can't be edited). |
| `Coolify answered 404` | `COOLIFY_DEPLOYER_UUID` is wrong. |
| `SOURCE_COMMIT is empty` (deployer log) | Set the deployer's Source commit to *Available during build*. |
| `The app's .env is missing` (deployer log) | Deploy the app resource once from Coolify, or copy `.env.bak` back as `.env` on the server. |
| `web-N is unhealthy; stopping the rollout` | Read the printed log lines. Usually a missing variable, a failed `/up` (database or Redis), or a boot error. The old version keeps serving. |

An emergency fix that must ship while the PostgreSQL run is broken for an unrelated reason: set `SKIP_PGSQL_TESTS=1` on the deployer (and in `.env.deploy`) for that one deploy, and remove it after. The log records the skip. `composer ci:check` is never skipped.

## Security

- The SSH key logs in as `root`. Keep it on your PC. When a PC is lost or a person leaves, remove their line from `authorized_keys` and revoke their Coolify token.
- One Coolify token per person. It can read every secret of the app, so `.env.deploy` stays on that PC and is never committed or shared.
- Nothing secret goes into the image. The deployer copies variables into the server's `.env`; values never reach a log.
- The app publishes no port: Traefik is the only way in, which is why trusted proxies can be `*`.
