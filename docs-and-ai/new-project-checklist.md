# New project and new developer checklists

Step by step from an empty repo to the first deploy, and what a new developer does in their first days.

- **Applies to:** every new FBS app, and every person joining a project.
- **Sources:** People `SYSTEM-RULES.md` rules 6, 22 and 55; section 15 of the old `reusable-components-in-all-projects.md`; how MCP was started (Laravel React starter kit, registration removed); the deploy and hub setup in People, Learn, Stock, Work and MCP.
- **Last updated:** 2026-11-07

Tick each box in the project's first plan, or name why it does not apply.

## Part A: a new project

### 1. Decide before any code

- [ ] Name, one-line purpose, and who uses it.
- [ ] **Hub app key** (lowercase, e.g. `stock`) and **tenant type** (`company`, `school`, `business`, `workspace` or `store`). See [integrations/fbs-suites-hub.md](../integrations/fbs-suites-hub.md).
- [ ] **Tenant model** (single tenant or multi-tenant). See [structure/tenancy-and-auth.md](../structure/tenancy-and-auth.md).
- [ ] **UI kit:** kit A (shadcn + Radix) or kit B (the Learn kit). One only, for good. See [structure/frontend-patterns.md](../structure/frontend-patterns.md).
- [ ] **Brand values** (primary, accent, font) behind the shared token names. See [structure/design-system.md](../structure/design-system.md).
- [ ] One sample dataset that every screen will use (rule 6).
- [ ] A spec in `docs/superpowers/specs/` from [templates/spec-template.md](../templates/spec-template.md), approved by the owner.

### 2. Create the repo

- [ ] A repo under `FrontlineBusinessDev`, default branch **`master`**. See [rules/git-workflow.md](../rules/git-workflow.md).
- [ ] Start from the Laravel 13 React starter kit (Inertia v3, React 19, TypeScript, Tailwind v4, Fortify, Pest). Remove public registration unless the product has its own sign-up.
- [ ] Copy patterns, not data, from **FBS People**, the reference project (rule 6): the base controller, the client layer, the design-system layer, the dialogs.
- [ ] Folders as in [structure/folder-structure.md](../structure/folder-structure.md).

### 3. Pin the environment

- [ ] `.php-version` (`8.4`), `.nvmrc` (`22`), `engines.node` in `package.json`.
- [ ] `ext-*` entries in `composer.json` for every extension the app uses; `composer check-platform-reqs` passes.
- [ ] `.editorconfig` (UTF-8, LF, 4 spaces; YAML 2).
- [ ] Details: [stack/tech-stack.md](../stack/tech-stack.md), [stack/environment-setup.md](../stack/environment-setup.md).

### 4. Set up the checks

- [ ] Pint (`laravel` preset), Larastan level 7 with **no baseline**, the linter and formatter, `max-lines` 300 for JS/TS and a PHP file-size test.
- [ ] One `composer ci:check` script that runs all of them plus the tests and the dependency audits.
- [ ] The Docker build runs the gate, so a failing build never deploys.
- [ ] Details: [quality/testing-and-ci.md](../quality/testing-and-ci.md).

### 5. Data and access

- [ ] `config/database.php` keeps `'prefix' => ''`. Every app table is a literal `app_` name; every model sets `protected $table`; foreign keys pass the table. See [structure/database.md](../structure/database.md).
- [ ] Tenant-owned tables carry the tenant id, an index led by it, the fail-closed scope, and an isolation test.
- [ ] Roles and permissions, staff two-step login, sign-in rules. See [structure/tenancy-and-auth.md](../structure/tenancy-and-auth.md).
- [ ] The CRUD base (`BaseController`, `Route::crudModule`, `createCrudResource`) in place, with one reference module and its tests. See [structure/backend-patterns.md](../structure/backend-patterns.md).

### 6. Write the project docs

- [ ] `CLAUDE.md` from [templates/CLAUDE.md](../templates/CLAUDE.md), with the shared-architecture pointer block. **Committed**, not git-ignored.
- [ ] `README.md` (setup and run), `.env.example` (every key the config reads), `.env.deploy.example`.
- [ ] `.gitignore` covers `.env*` (except the examples), `.env.deploy`, `*.sqlite`, database dumps, `Claude outputs/`, `graphify-out/`.
- [ ] Layout and naming as in [docs-and-specs.md](docs-and-specs.md).

### 7. Connect to the FBS Suites hub

- [ ] If the app key is new: add it to the hub (`SuiteApp::KEYS`, `NAMES`) and the CRM (`SignupIntake::APPS`), and deploy both first.
- [ ] On FBS Suites › Platform › Apps: create the app. Copy the webhook secret (shown once) straight into Coolify as `FBS_HUB_SECRET`. Set `FBS_HUB_URL` (https, no path).
- [ ] Copy [templates/hub/](../templates/hub/) into `app/Support` and `app/Jobs`, replace every `TODO(app)`, and add `fbs_hub` to `config/services.php`.
- [ ] Call `SuiteHub::signedUp()` from the tenant model's `created` event. Seed demo tenants inside `SuiteHub::quietly()`.
- [ ] Tests: the signature test vector, and a faked `Http` call that asserts the URL, headers and body.

### 8. Set up the deploy

- [ ] Copy [templates/deploy-kit/](../templates/deploy-kit/) and fill in the names. Rules: [deployment/docker-image.md](../deployment/docker-image.md) (ship only what runs, exact FrankenPHP tag, non-root, no dev packages).
- [ ] Coolify: the app as a Docker Compose resource on **manual** deploys with the Domains fields empty; a PostgreSQL resource; the deployer resource; the private bucket and its root folder. See [deployment/architecture.md](../deployment/architecture.md).
- [ ] Redis in the stack with `appendonly yes`, a password, and a unique service name.
- [ ] On your PC: SSH key, `.env.deploy`, Coolify API token. See [deployment/publish-and-rollout.md](../deployment/publish-and-rollout.md).
- [ ] `DEPLOY.md` with the standard headings ([docs-and-specs.md](docs-and-specs.md), section 5).

### 9. First deploy

- [ ] `composer ci:check` is green on `master`.
- [ ] `php artisan deploy`.
- [ ] Check: `/up` passes; pages load with their assets; sign-in works; the queue worker and scheduler run; migrations and seeders ran; an upload lands in the bucket under the project's root folder.
- [ ] A test sign-up reaches the CRM as a lead (FBS Suites › Platform › Webhooks shows it `sent`).
- [ ] Rollback tried once: redeploy the previous image.

### 10. Every feature after that

- [ ] A plan from [templates/plan-template.md](../templates/plan-template.md) that names its rules (rule 55).
- [ ] Tidy first, in its own commit (rule 57). Frontend first, then its backend (rule 58).
- [ ] Rule 22's definition of done: designed, built, feature and unit tests, browser check, accessibility and privacy, performance, docs and the "as built" note, shipped through the pipeline.

### 11. Do not repeat the existing apps' problems

The existing apps are left as they are (owner's decision, 2026-11-06). A new application gets each of these right from the first commit.

- [ ] **No data in git:** no `*.sqlite`, SQL dumps or backup folders, ever. `.gitignore` has them before the first commit. *(Stock and Work track SQLite copies.)*
- [ ] **No real addresses or secrets in example files:** only placeholders in `.env.example` and `.env.deploy.example` (named exactly that). *(Stock's example names its server.)*
- [ ] **No tool output in git:** `Claude outputs/` and `graphify-out/` are git-ignored from the start. *(Tracked in several apps.)*
- [ ] **`composer ci:check` really runs:** every npm script and tool it calls exists (PHPStan installed with a `phpstan.neon`). Run it once from a fresh clone. *(MCP calls PHPStan without it; Learn and FCA-SMS call missing npm scripts; Stock and Work have no gate.)*
- [ ] **One branch name everywhere:** `master` in `publish.sh`, `DEPLOY.md`, Coolify and any workflow file. *(People and MCP test `main` but deploy `master`.)*
- [ ] **One PHP version everywhere:** `composer.json`, `.php-version`, the Dockerfile and any CI agree. *(Apps mix 8.3, 8.4 and 8.5.)*
- [ ] **Exact image tags:** FrankenPHP pinned to an exact tag, `composer:2`, never `latest` or `1-php8.4`.
- [ ] **Literal `app_` tables only:** no `DB_PREFIX`, and no app table without the prefix. *(CRM and Connect use `DB_PREFIX`; Stock has unprefixed tables.)*
- [ ] **Tenant scope is global and fails closed:** never filter the tenant id by hand in controllers. *(Stock.)*
- [ ] **Migrations run once per deploy** in the one-off `migrate` service, never on every container start. *(CRM and FCA-SMS.)*
- [ ] **The image ships only what runs:** no `COPY . .` into the final stage. *(Stock.)*
- [ ] **Compose has caps, Redis and health checks** for every service. *(FCA-SMS has none.)*
- [ ] **TypeScript only**, no `.jsx`. *(Stock.)*
- [ ] **`CLAUDE.md` committed**, with the pointer to this repo. *(CRM, Connect, Pay and People ignore theirs.)*
- [ ] **Hub sender copied from the template:** no redirects followed; only a JSON 2xx with `success: true` counts as sent. *(Pay.)*
- [ ] **The 300-line rule is never switched off for a file.** *(Pay.)*

## Part B: a new developer

### Day one

- [ ] Access: the GitHub organisation, the project repo, and Web-Development-Architecture. Coolify and server access only if you will deploy.
- [ ] Install PHP 8.4 and Node 22 (the versions in `.php-version` and `.nvmrc`), Composer 2, Git (Git Bash on Windows).
- [ ] Enable the PHP extensions in [stack/environment-setup.md](../stack/environment-setup.md); run `php -m` and `composer check-platform-reqs`.
- [ ] Clone the project and Web-Development-Architecture side by side.
- [ ] `composer install`, `npm install`, `cp .env.example .env`, `php artisan key:generate`, `php artisan migrate --seed`.
- [ ] `composer run dev` (not Octane) and open the app.
- [ ] `composer ci:check` and `php artisan test` are green before your first commit.

### Read, in this order

- [ ] The project's `README.md` and `CLAUDE.md`.
- [ ] [rules/system-rules.md](../rules/system-rules.md): the rules at a glance, then the full text of the ones your first task names.
- [ ] [rules/coding-standards.md](../rules/coding-standards.md) and [rules/git-workflow.md](../rules/git-workflow.md).
- [ ] The project's design profile and its reference CRUD module.

### Habits to have from the first commit

- [ ] Commit to `master` in the format of [rules/git-workflow.md](../rules/git-workflow.md).
- [ ] New table: `app_` name, `$table` on the model, full names in `exists:` / `unique:` and raw SQL.
- [ ] A person's name: `first_name` + `last_name` columns, `name` only as an accessor (rule 68).
- [ ] New CRUD screen: follow the CRUD pattern; never write a list page from scratch.
- [ ] Loading data: skeletons. Questions: the app's modal, never `alert` or `window.confirm`.
- [ ] Octane-safe code: no per-request state in statics or singletons; cache arrays, not models.
- [ ] Migrations stay backward compatible with the release that is still running.
- [ ] No file over 300 lines.
- [ ] No secrets in code, logs, commits or docs.

### Before your first deploy

- [ ] The one-time setup in the project's `DEPLOY.md`: SSH key, `.env.deploy` (never committed).
- [ ] Watch one deploy with someone who has done it, then do one yourself.
