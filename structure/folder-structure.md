# Folder structure

Where every kind of file lives in an FBS Laravel app, so any developer or AI assistant finds it in the same place in every repo.

- **Applies to:** every FBS Laravel app. New projects copy it as written; existing ones move toward it as they touch each area.
- **Sources:** FBS People (the reference project) repo layout and `.gitignore`; Learn, FCA-SMS, Pay, MCP and Work layouts; Learn `docs/reusable-components-in-all-projects.md`.
- **Last updated:** 2026-11-06

## The whole repo

```text
<project>/
├── app/                      PHP application code (below)
├── bootstrap/                Laravel bootstrap; bootstrap/ssr only if SSR is on
├── config/                   one file per concern; commit config/octane.php
├── database/
│   ├── factories/
│   ├── migrations/           YYYY_MM_DD_HHMMSS_<verb>_<table>.php, app_ tables
│   └── seeders/              reference data seeders run by scripts/migrate.sh
├── deploy/                   the Coolify deployer: Dockerfile.deployer, build-image.sh,
│                             coolify-deploy.sh, rolling-deploy.sh
├── docker/                   files the image copies: php/uploads.ini, maintenance.php
├── docs/                     project docs (below)
├── public/                   web root; public/build is generated, never committed
├── resources/
│   ├── css/app.css           Tailwind 4 tokens in @theme
│   ├── js/                   the React app (below)
│   └── views/                app.blade.php, emails/, pdf/
├── routes/                   web.php, settings.php, console.php, api.php (public API only)
├── scripts/                  deploy.sh, migrate.sh, worker.sh, wait-for-db.sh, publish.sh
├── storage/                  runtime files; contents never committed
├── tests/                    Feature/, Unit/, Support/, Pest.php, TestCase.php
├── .dockerignore  .editorconfig  .env.example  .env.deploy.example
├── .gitignore  .nvmrc  .php-version
├── CLAUDE.md                 AI and human guide; points to the architecture repo
├── DEPLOY.md                 how this app deploys (template in this repo)
├── README.md                 what the app is, setup in five lines
├── SYSTEM-RULES.md           pointer to (or copy of) the shared rules
├── Dockerfile  docker-compose.yml
├── composer.json  composer.lock  package.json  package-lock.json
├── phpstan.neon  pint.json  phpunit.xml
└── tsconfig.json  vite.config.ts  components.json (kit A only)
```

## `app/`

```text
app/
├── Actions/<Module>/<Verb><Noun>.php   one workflow that touches several models (one handle())
├── Console/Commands/                   artisan commands
├── Exceptions/                         typed exceptions (PlanLimitReached, ConflictException)
├── Http/
│   ├── Controllers/
│   │   ├── Controller.php
│   │   └── v1/
│   │       ├── BaseController.php      the shared CRUD base
│   │       └── <Module>/<Name>Controller.php
│   ├── Middleware/                     ResolveCompany, EnsureFeature, EnsurePlatformStaff
│   ├── Requests/<Module>/              Store<X>Request, Update<X>Request
│   ├── Resources/<X>Resource.php       the only way a model leaves as JSON
│   └── Responses/                      Inertia and login responses
├── Jobs/                               queued work, safe to retry
├── Listeners/  Mail/  Notifications/  Observers/
├── Models/
│   ├── Concerns/                       BelongsToCompany and other model traits
│   └── Scopes/                         CompanyScope (tenant scope, fails closed)
├── Policies/<X>Policy.php              one per model
├── Providers/                          AppServiceProvider (crudModule macro), FortifyServiceProvider
├── Support/                            small focused classes: ListQuery, Statuses, Permissions,
│                                       SuiteHub, Audit
└── Traits/                             HandlesFileUploads and other controller traits
```

Rules:

- A domain with many classes may get its own top-level folder (`app/Payroll/`, `app/Time/`, `app/Hub/`, `app/Mcp/`), as People, Pay and MCP do. Keep pure calculation engines there, with no database access.
- Controllers stay thin. Rules and calculations go in Actions, Support classes or the domain folder; validation in Requests; output shape in Resources.
- Do not create both `app/Services` and `app/Support` for the same kind of class. New code uses `app/Support` (or a domain folder).

## `resources/js/`

```text
resources/js/
├── app.tsx                 Inertia entry
├── ssr.tsx                 only when SSR is on
├── lib/
│   ├── api/                the one client layer: client.ts (axios + ApiError), resource.ts
│   │                       (createCrudResource), form-data.ts, query.ts
│   ├── utils.ts            cn() and small helpers
│   └── ...
├── components/
│   ├── ui/                 the kit (kit A: shadcn primitives; kit B: the barrel index.ts)
│   └── <area>/             shared components grouped by purpose
├── hooks/                  shared hooks (use-list, use-can, use-mobile)
├── layouts/                app shell, auth layout, settings layout
├── pages/                  Inertia pages, folder per hub: pages/<hub>/<page>.tsx
├── types/                  shared TypeScript types (index.d.ts for shared props)
└── actions/ routes/ wayfinder/   generated by Wayfinder, git-ignored
```

Rules:

- A page folder holds the page and its own parts: the data hook, the zod schema, the columns, each dialog and each tab in its own file ([rule 51](../rules/system-rules.md)).
- Generated code (`actions/`, `routes/`, `wayfinder/`, `bones/` in kit B) is never edited by hand.
- File and folder names follow [coding standards](../rules/coding-standards.md).
- The client layer folder is `lib/api/` (People). Learn, FCA-SMS and MCP call it `api-service/`; an existing project keeps its name, but only one client layer per project ([frontend patterns](frontend-patterns.md)).

## `docs/`

```text
docs/
├── deployment/             coolify-single-vps.md and other runbooks
├── design-system/          the app's design profile and screenshots
├── superpowers/
│   ├── specs/              YYYY-MM-DD-<topic>-design.md
│   └── plans/              YYYY-MM-DD-<topic>.md
└── <topic>.md              kebab-case, one subject per file
```

Naming, templates and what goes where: [docs and specs](../docs-and-ai/docs-and-specs.md).

## `tests/`

```text
tests/
├── Feature/<Module>/<Name>Test.php    HTTP-level tests, one file per module or behaviour
├── Unit/                              pure classes, file-size test
├── Support/                           fakes (FakeUpstream, FakeClaude) and world builders
├── Pest.php                           shared setup (current tenant, roles)
└── TestCase.php
```

See [testing and CI](../quality/testing-and-ci.md).

## What git ignores

Always ignored:

- `.env`, `.env.*` (except `.env.example` and `.env.deploy.example`), `.env*.deploy`
- `vendor/`, `node_modules/`, `public/build/`, `public/hot`, `public/storage`, `bootstrap/ssr`
- `storage/*.key`, `storage/` runtime contents, `.phpunit.cache`, `.phpunit.result.cache`
- generated Wayfinder folders (`resources/js/actions`, `routes`, `wayfinder`)
- editor and agent folders (`.idea`, `.vscode`, `.claude`, `.cursor` …)
- `*.sqlite` and database dumps or backups
- local output folders (`Claude outputs/`, `graphify-out/` unless the project commits it on purpose)

Never commit a database file, a dump, a real `.env` or a server address.

## Known differences today

| App | Differs from the standard |
|---|---|
| People, CRM, Connect, Pay | `CLAUDE.md` is git-ignored (People commits an empty `CLAUDE.md.txt`); the standard commits it |
| Stock | `database_backup.sqlite` is committed; pages are PascalCase `.jsx`, relative imports, one-file kit `components/ui.jsx` |
| Work | `database-backup/*.sqlite` committed; both `app/Services` and `app/Support` (`Notifier` in each); no `layouts/` (shell in `components/shell.tsx`) |
| CRM | `Claude outputs/` is tracked although its HANDOFF says never commit it |
| CRM, Connect | Data endpoints under `app/Http/Controllers/Api/*`, web controllers only return shells |
| Pay | Second Inertia app in `resources/js/ops/` with `features/<module>/hooks`; one route file per module in `routes/ops/` |
| Learn, FCA-SMS, MCP | Client layer in `api-service/`; Learn and FCA-SMS pages are PascalCase (`RoomPage.tsx`) |
| Learn, FCA-SMS | `docs/implementation/` folders with spaces in names (`import data/`, `excel-file-templte/` in FCA-SMS) |
