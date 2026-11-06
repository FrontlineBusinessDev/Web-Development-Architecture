<!--
CLAUDE.md template. Copy to the project root as CLAUDE.md, fill in every <...>, delete these comments, and commit it.
Keep it short (about 150 lines). Guide: Web-Development-Architecture/docs-and-ai/claude-md-template.md
Never put secrets, passwords, seeded logins, server addresses or model names in this file.
-->

# <App name>

<One sentence: what the app does and for whom.> Laravel 13 + Inertia v3 + React 19 (TypeScript) + Tailwind v4, Fortify login, Pest.

## Shared architecture (read first)

This project follows the FBS shared architecture:
https://github.com/FrontlineBusinessDev/Web-Development-Architecture
It is the source of truth for the rules, tech stack, folder structure, deployment and the FBS Suites hub contract.

- Keep a clone next to this project (`../Web-Development-Architecture`) and `git pull` it before you rely on it.
- Before you add a CRUD module, a shared component, a migration, a Docker or deploy change, a PHP or npm dependency, or a hub event, read the matching file there:
  - Rules: `rules/system-rules.md`, `rules/coding-standards.md`, `rules/git-workflow.md`
  - Code: `structure/backend-patterns.md`, `structure/frontend-patterns.md`, `structure/database.md`, `structure/tenancy-and-auth.md`, `structure/design-system.md`
  - Stack: `stack/tech-stack.md`, `stack/environment-setup.md`
  - Deploy: `deployment/architecture.md`, `deployment/docker-image.md`, `deployment/publish-and-rollout.md`
  - Checks: `quality/testing-and-ci.md`
  - Hub events: `integrations/fbs-suites-hub.md`
- Every plan, spec and review names the rule numbers it follows (rule 55).
- When this project changes a shared convention, update Web-Development-Architecture in the same piece of work (on its `master` branch). Never change a shared rule in one project only.
- This project's own exceptions are under "Project rules" below. Everything else follows the shared docs.

## Project docs

- Design: `docs/superpowers/specs/<YYYY-MM-DD-topic-design.md>`
- Current plan: `docs/superpowers/plans/<YYYY-MM-DD-topic.md>`
- Deploy: [DEPLOY.md](DEPLOY.md)
- Where the work stands: [HANDOFF.md](HANDOFF.md) <if any>
- <Codemaps or module guides in docs/>

## Project rules

- **Tenant:** `<Model>` (`<tenant>_id`), scope `<Scope / trait>`, current tenant from `<helper>`. With no current tenant, queries return nothing and creates throw.
- **Hub app key:** `<people | pay | learn | connect | work | stock | new key>`, tenant type `<company | school | business | workspace | store>`.
- **UI kit:** <kit A: shadcn + Radix | kit B: the Learn kit>. Never mix the two.
- **Brand:** primary `<#hex>`, accent `<#hex>`, font `<Font>`, set behind the shared token names in `resources/css/app.css`.
- **Roles:** <role list and where permissions are defined>.
- **Plans and gates:** <how a feature is gated, if the product is a subscription>.
- <Any exception to the shared docs, with the reason.>

## Project status

<What is real (database-backed) and what is still sample data. The quick test to tell them apart, e.g. "a controller route is real, a bare Inertia closure is a shell".>

## Architecture

- **Routing:** <Inertia pages from routes/web.php; JSON on the same prefix | /api/*>.
- **Layouts:** <how a page picks its shell>.
- **Auth:** <Fortify, 2FA, social sign-in, FBS ID>.
- **Permissions:** <Spatie roles / permission middleware>.

| File | Why it matters |
| --- | --- |
| `routes/web.php` | <...> |
| `app/Http/Controllers/v1/BaseController.php` | Shared CRUD, list, archive and delete logic |
| `resources/js/api-service/http.ts` | `createCrudResource()`, the frontend mirror of `crudModule` |
| <...> | <...> |

## CRUD module pattern

Follow `structure/backend-patterns.md` in the shared repo. Reference pair in this project: `<app/Http/Controllers/v1/.../XController.php>` and `<resources/js/pages/.../XPage.tsx>`.

## Commands

```bash
composer run dev          # server + queue + vite
php artisan test          # Pest
composer ci:check         # the full gate (format, lint, types, tests, audits)
npm run types:check
php artisan migrate:fresh --seed
php artisan deploy        # from your PC, see DEPLOY.md
```

## Working in this repo

- Loading regions show skeletons, never an empty state while data loads.
- Questions and confirmations use the app's modal, never `alert`, `confirm` or `prompt`.
- <Exports, prints, theme, anything an assistant gets wrong without being told.>
