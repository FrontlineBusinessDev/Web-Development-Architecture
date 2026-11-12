# Tech stack

The versions, packages and tools every FBS web app uses, and the ones it must not add.

- **Applies to:** every FBS Laravel app (People, CRM, Connect, Pay, Learn, FCA-SMS, Stock, Work, FBS Suites MCP) and every new one.
- **Sources:** `composer.json`, `package.json`, `Dockerfile` and CI files of all nine repos; People `SYSTEM-RULES.md` rules 35 and 38; Learn `docs/reusable-components-in-all-projects.md` sections 2 and 12.
- **Last updated:** 2026-11-06

## Pinned versions

Pin the version in the file named in the last column. A version change is its own commit.

| Layer | Standard | Pinned in |
|---|---|---|
| PHP | 8.4 | `.php-version`, `composer.json` (`"php": "^8.4"`), the image tag |
| Node | 22 | `.nvmrc`, `package.json` `engines.node`, the build-stage image |
| Laravel | 13 (`laravel/framework ^13.17`) | `composer.json` |
| Inertia | 3 (`inertiajs/inertia-laravel ^3.0`, `@inertiajs/react ^3`) | `composer.json`, `package.json` |
| React | 19.2 | `package.json` |
| TypeScript | 5.7 or newer, strict | `package.json`, `tsconfig.json` |
| Tailwind CSS | 4 (tokens in `@theme`, no `tailwind.config`) | `package.json`, `resources/css/app.css` |
| Build | Vite 8, or vite-plus (`vp`) where the project uses it for lint and format | `package.json` |
| Server | FrankenPHP with Laravel Octane | `Dockerfile` `ARG FRANKENPHP_IMAGE` |
| Image tag | An exact tag, for example `dunglas/frankenphp:1.13.4-php8.4-bookworm`. Never `1-php8.4` or `latest` | `Dockerfile` |
| Composer image | `composer:2` (major pinned, never `latest`) | `Dockerfile` |
| Database | PostgreSQL 18 in production, SQLite in memory for tests | Coolify resource, `phpunit.xml` |
| Cache, session, queue | Redis 7 (phpredis client), persistence on | `docker-compose.yml` |
| Tests | Pest (with `pest-plugin-laravel`) | `composer.json` |
| Static analysis | Larastan, level 7 | `phpstan.neon` |
| PHP style | Pint, `laravel` preset | `pint.json` |

Bump a pinned version on purpose: read the release notes, run the full gate ([testing and CI](../quality/testing-and-ci.md)), then deploy.

## Approved packages by purpose

Use these before reaching for anything else. Each purpose has one package; a project does not install a second one for the same job.

### Backend

| Purpose | Package |
|---|---|
| Sign-in, 2FA, passkeys, password reset | `laravel/fortify` |
| API keys and same-origin SPA auth | `laravel/sanctum` |
| Google and Microsoft sign-in | `laravel/socialite` |
| OIDC provider (FBS ID) | `laravel/passport`, only in FBS Suites MCP |
| Roles and permissions | `spatie/laravel-permission` (teams on the tenant id) |
| Typed route helpers for the frontend | `laravel/wayfinder` |
| Long-lived workers | `laravel/octane` (FrankenPHP) |
| Markdown on the server | `league/commonmark` through `Str::markdown()` (ships with Laravel) |

### Frontend

| Purpose | Package |
|---|---|
| Pages and navigation | `@inertiajs/react` (no client router) |
| HTTP | `axios`, one instance ([frontend patterns](../structure/frontend-patterns.md)) |
| Server data | `@tanstack/react-query` |
| Data tables | `@tanstack/react-table` |
| Form validation | `zod` |
| Icons | `lucide-react` |
| Class names | `clsx` + `tailwind-merge` behind one `cn()` helper |
| Charts | `recharts` |
| Animation (optional) | `framer-motion` |
| UI kit | Kit A: shadcn (new-york) + Radix + `sonner`. Kit B: Learn's own kit + `boneyard-js`. One per project ([frontend patterns](../structure/frontend-patterns.md)) |
| Forms (kit A) | `@tanstack/react-form` (People) or `react-hook-form` (Pay); one per project |
| Text in photos and PDFs, read on the device (optional) | `tesseract.js` (OCR, English data from `@tesseract.js-data/eng`) and `pdfjs-dist` (a PDF's text layer). Load them only where a file is attached and serve their worker, engine and data from the app's own host, never a CDN. People uses them so Ask AI files leave the device with ID and bank numbers removed |

### Dev tools

| Purpose | Package |
|---|---|
| Tests | `pestphp/pest`, `pestphp/pest-plugin-laravel` |
| PHP types | `larastan/larastan` |
| PHP format | `laravel/pint` |
| JS lint and format | `vp check` (vite-plus, which wraps oxlint) or `oxlint` alone |
| JS types | `tsc --noEmit` (or `tsc -b` with project references) |
| JS unit tests | `vp test` / Vitest |
| Browser tests (optional) | Cypress 15 |
| AI guidelines (optional) | `laravel/boost` |

## Packages not to add

| Don't add | Use instead |
|---|---|
| `react-router-dom` or any client router | Inertia visits (a `router-compat` shim if old code needs the API) |
| A second HTTP client, or `fetch()` in components | The one axios instance |
| Redux, Zustand or another global store for server data | TanStack Query |
| Formik, Yup | zod + the project's one form library |
| A second toast library | The kit's toasts |
| A second icon set | `lucide-react` |
| jQuery, Bootstrap, Livewire | React + Tailwind |
| moment.js | `Intl`, native `Date`, or a small library the project already has |
| A package for a few lines of code | Write the few lines |

## Adding a dependency

1. Check this page and the project's `composer.json` / `package.json` first. Use what is already installed.
2. Raise a new dependency with the owner before adding it (People rule 35, CRM `.ai/rules`).
3. Pin it (`^x.y`), commit the lock file, and say why in the commit message.
4. Run `composer audit` and `npm audit --omit=dev`. The gate runs them too.
5. If it becomes standard, update this page in the Web-Development-Architecture repo.

## Known differences today

> Existing apps are left as they are (owner's decision, 2026-11-06). This list is a record, not a to-do: new applications follow the standard and must not repeat these. See [the new-project checklist](../docs-and-ai/new-project-checklist.md), section 11.

| App | Differs from the standard |
|---|---|
| CRM, Connect, Pay, Stock, Work, MCP | `composer.json` allows PHP `^8.3`; only People (`^8.4`), Learn and FCA-SMS (`^8.4.1`) match |
| Pay | PHP 8.5 on `serversideup/php` FPM + nginx, no Octane |
| MCP | FrankenPHP without Octane ("no Octane in v1") |
| People, CRM, Connect, Learn, FCA-SMS | Floating image tag `dunglas/frankenphp:1-php8.4` |
| Stock | Plain JavaScript (`.jsx`, `vite.config.js`), no TypeScript, no Larastan, PHPUnit instead of Pest |
| Work | PHPUnit instead of Pest, no Larastan; Node 24 in the build stage for Wayfinder |
| MCP | No Larastan installed, yet `composer types:check` calls `phpstan` |
| People, Learn, FCA-SMS, MCP | Pest 4; CRM, Connect, Pay on Pest 5 |
| CRM, Connect | `formik`, `zod`, `axios` and React Query installed but unused (a `fetch` wrapper is used); charts on nivo, not recharts |
| Learn, FCA-SMS | TypeScript `~6.0`, `oxlint` instead of `vp` |
| All but Learn and People | No `.php-version`; only Learn has `.nvmrc` |
| FCA-SMS CI | PostgreSQL 16 service, not 17 |
