# Deploy __APP_NAME__

Your PC builds the app image with Docker Desktop and sends it straight into the server's Docker over SSH. Coolify then replaces the web containers one at a time with it, so the site stays up. There is no registry, account or quota.

Only `php artisan deploy` deploys. A plain `git push` to `master` deploys nothing: the deployer is on manual deploys, and the command starts it through Coolify's API.

```
php artisan deploy  ->  composer ci:check + tests on PostgreSQL + build, on your PC
                    ->  docker save | gzip | ssh <server> docker load
                    ->  git push origin master  ->  Coolify API: start the deployer
                    ->  deployer finds __APP_SLUG__-pc:<commit> and rolls it out
```

How the server stack works: the shared [deployment/architecture.md](https://github.com/FrontlineBusinessDev/Web-Development-Architecture/blob/master/deployment/architecture.md).

---

## One-time setup

Do these steps in order, in Git Bash on your PC unless a step says otherwise.

### 1. Make an SSH key

Press Enter at each question (default file, no passphrase):

```bash
ssh-keygen -t ed25519 -C __APP_SLUG__-deploy
```

`~/.ssh/id_ed25519` is the private key: it never leaves this PC. `~/.ssh/id_ed25519.pub` goes on the server. If it asks to overwrite an existing key, answer `n` and use the one you have.

### 2. Put the public key on the server

1. Print it: `cat ~/.ssh/id_ed25519.pub`. Copy the whole line.
2. Open Coolify → **Servers → your server → Terminal** and run, with your line between the quotes:
    ```bash
    mkdir -p ~/.ssh && chmod 700 ~/.ssh && echo 'ssh-ed25519 AAAA... __APP_SLUG__-deploy' >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys
    ```
    Keep `>>` (append). A single `>` erases Coolify's own key and cuts Coolify off from the server.

### 3. Connect once

Answer `yes` to trust the server. It prints Docker's version:

```bash
ssh root@<server> docker version --format '{{.Server.Version}}'
```

### 4. Create `.env.deploy`

Copy `.env.deploy.example` to `.env.deploy` and fill it in. It is git-ignored: never commit it.

| Key | Where to get it |
| --- | --- |
| `DEPLOY_SSH` | `root@` and the server's address. |
| `COOLIFY_URL` | The Coolify dashboard's address. |
| `COOLIFY_UUID` | The **app** resource: the last part of its address, `.../application/<uuid>`. |
| `COOLIFY_DEPLOYER_UUID` | The **deployer** resource: the last part of its address. Never the app's. |
| `COOLIFY_API_TOKEN` | Coolify → **Keys & Tokens → API tokens** (turn the API on under **Settings → Configuration**). Tick **read**, **read:sensitive** and **deploy**. One per person. |

Without `read:sensitive`, Coolify hides the bucket settings and the build stops. Without `deploy`, the image is sent and `master` pushed, but the rollout does not start.

### 5. The Coolify resources (once per server)

| Resource | Settings |
| --- | --- |
| **PostgreSQL 17** | A Coolify database. Copy its internal hostname into the app's `DB_HOST`. Turn on scheduled backups to the bucket. |
| **App** | Docker Compose, `docker-compose.yml`. Manual deploys. Leave every **Domains** field empty: `migrate` writes the Traefik route from `APP_URL`. |
| **Deployer** | Dockerfile, `deploy/Dockerfile.deployer`. Manual deploys. Storages: `/var/run/docker.sock` and `/data/coolify/applications/<app-uuid>`, each mounted at the same path. Env: `COOLIFY_UUID=<app-uuid>`, `COOLIFY_API_TOKEN` (read, read:sensitive), `COOLIFY_URL=http://coolify:8080`. Source commit: **Available during build**. |

Fill in the app's variables (`APP_KEY`, `APP_URL`, `DB_*`, `REDIS_PASSWORD`, `AWS_*`, `ASSET_URL`), then deploy the **app** once from Coolify. That first deploy writes the compose file and `.env` on the server and builds a self-contained image (the Vite files inside it).

**Redeploy in Coolify always works** (rule 67):

| You press Redeploy on | What happens |
| --- | --- |
| the **deployer** | It rolls out the branch's commit again, one web container at a time: with the PC's image when the server has it, else the image it built last time for that commit, else it builds on the server. |
| the **app** | Coolify builds the `Dockerfile` itself, with the Vite files inside the image (the app serves its own `/build`), and restarts every container at once. Use it to repair the stack, not for releases. |

Point the domain's DNS A record at the server. Traefik gets the certificate.

---

## Every deploy

1. Commit your work on `master`.
2. Start Docker Desktop.
3. Run:
    ```bash
    php artisan deploy
    ```

It checks SSH, Docker, the branch and that nothing is uncommitted. It runs `composer ci:check` and the tests on PostgreSQL inside the build, uploads the Vite files to the bucket, builds the image from the commit, sends it to the server, pushes `master` and starts the deployer. A failing check stops it before anything is sent.

On Windows it runs the script in `C:\Program Files\Git\bin\bash.exe`; set `GIT_BASH` when Git is installed elsewhere.

## Check it worked

Coolify → Deployer → **Runtime Logs** (not Deployment Logs):

| Log line | Meaning |
| --- | --- |
| `__APP_SLUG__-pc:<commit> is already on this server` | Working. |
| `No __APP_SLUG__-pc:<commit> on this server ...: building here.` | Someone pressed Redeploy for a commit no PC sent. It still works, built on the server. Use `php artisan deploy`. |
| `__APP_SLUG__-build:<id> was built here before: rolling it out again.` | A Redeploy of the deployer on the same commit: nothing is rebuilt. |
| `This deployer container already deployed build ...` | The deployer restarted (a reboot): it doesn't deploy again. Press Redeploy on the deployer to roll out again. |
| `SOURCE_COMMIT is empty` | Set Source commit to *Available during build* (step 5). |
| `Deploy succeeded: ...` | The rollout finished. |
| `Deploy FAILED: ...` | It stopped. The site keeps serving the old containers. Read the lines above it. |

## Rollback

The server keeps the 3 newest builds. In Coolify, roll the **deployer** back to an earlier deployment: it rolls that version out the same way. Migrations do not roll back; that is why they stay backward compatible.

## Troubleshooting on your PC

| Message | Fix |
| --- | --- |
| `Docker is not running` | Open Docker Desktop and wait for *Engine running*. |
| `No .env.deploy` / `... is not set in .env.deploy` | Step 4. |
| `Cannot run docker on ... over SSH` / `Permission denied (publickey)` | Step 2 and step 3. |
| `Uncommitted changes` | Commit first. The image is built from the commit. |
| `composer ci:check failed` / `The tests failed on PostgreSQL` | Fix the failing check; run `composer ci:check` locally. |
| `ASSET_URL is set but AWS_... is not` | The token lacks `read:sensitive`, or the app's `AWS_*` variables are empty. |
| `Coolify answered 401/403` | The token lacks **deploy**: make a new one. The image is on the server, so press Redeploy on the deployer meanwhile. |
| `Coolify answered 404` | `COOLIFY_DEPLOYER_UUID` is wrong. |
| `Another deploy is running` | Wait for it, or close the terminal that runs it. |

## Security

- The SSH key logs in as `root`. Keep it on your PC. If the PC is lost, delete its line from the server's `~/.ssh/authorized_keys`.
- The API token can read every secret of the app. Keep `.env.deploy` on your PC and revoke the token if the PC is lost.
- Nothing secret goes into the image. The app reads the server's `.env` at runtime.

## Files

| File | Role |
| --- | --- |
| `routes/console.php` | `php artisan deploy`: runs `scripts/publish.sh` in Git Bash. Refuses to run in production. |
| `scripts/publish.sh` | Your PC: build, send over SSH, `git push`, start the deployer. |
| `deploy/build-image.sh` | Checks, PostgreSQL tests, Vite files to the bucket, the image. Used by your PC and the server. |
| `deploy/coolify-deploy.sh` | The deployer: uses the PC's image, or builds, then the rollout. |
| `deploy/rolling-deploy.sh` | The zero-downtime rollout. |
| `deploy/Dockerfile.deployer` | The deployer image. |
| `scripts/migrate.sh` | Migrations, reference seeders and the Traefik route. |
| `.env.deploy` | Your PC's settings. Git-ignored. |
