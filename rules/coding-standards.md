# Coding standards

How code is named, sized, formatted and written in every FBS project, backend and frontend.

- **Applies to:** every FBS Laravel + Inertia + React project, and new code in older ones (rule 57: tidy what you touch, don't rewrite what you don't).
- **Sources:** `laravel-fbs-people` (reference), `laravel-fbs-crm` (`HANDOFF.md`, `.ai/rules/`, the enforced line limit), `laravel-fbs-learn` and `laravel-fca-sms` (`CLAUDE.md`, `reusable-components-in-all-projects.md`), `fbs-suites-mcp` (`CLAUDE.md` global constraints), the `.editorconfig`, `pint.json`, `tsconfig.json` and `vite.config.ts` files of each repo.
- **Last updated:** 2026-11-06

The numbered rules in [system-rules.md](system-rules.md) win when this file and a rule disagree. Patterns with code (CRUD, client layer) are in [../structure/backend-patterns.md](../structure/backend-patterns.md) and [../structure/frontend-patterns.md](../structure/frontend-patterns.md).

## 1. Formatting

Formatting is never argued about in review: the tools decide, and the build checks them (rule 49).

| What | Tool and setting | Evidence |
|---|---|---|
| Every file | `.editorconfig`: UTF-8, LF, 4 spaces, final newline, trim trailing spaces (not in `.md`), 2 spaces for YAML | identical in People, Learn, CRM, FBS Suites |
| PHP | Pint, `pint.json` = `{ "preset": "laravel" }`; `composer lint` fixes, `pint --test` checks | People, CRM |
| TS / TSX / CSS | the Vite+ formatter (`vp fmt`): print width 80, 4 spaces, single quotes, semicolons, Tailwind classes sorted | People and CRM `vite.config.ts` `fmt:` |
| JS lint | `vp lint` (oxlint inside Vite+) with warnings treated as errors; projects not on Vite+ use `oxlint` directly | CRM (0 warnings), Learn (`oxlint`) |

- Run the formatter on a whole repo once, in its own commit, before turning on the format check. FBS CRM's `vp check` fails on every file because that step was skipped.
- Generated folders (`resources/js/actions`, `resources/js/routes`, `resources/js/wayfinder`, `components/ui` from shadcn) are left out of the formatter, as People does.

## 2. Naming

### PHP

| Thing | Convention | Example |
|---|---|---|
| Classes, enums, traits | `PascalCase`, singular nouns for models | `Room`, `InvoiceLine`, `BelongsToCompany` |
| Controllers | `<Model>Controller`; one per resource; nested by area folder | `v1/Academics/RoomController` |
| Actions | `app/Actions/<Module>/<Verb><Noun>` | `CreateCompany`, `CreateSchool` |
| Jobs | verb first | `SendSuiteHubEvent`, `DeliverWebhook` |
| Policies, resources, requests | `<Model>Policy`, `<Model>Resource`, `<Verb><Noun>Request` or `<Model>Request` | `RoomPolicy`, `RoomResource`, `AttachCompanyDepartmentRequest`, `BranchRequest` |
| Methods, variables | `camelCase` | `paginationSearch()`, `$inUseRelations` |
| Tables | literal `app_` + snake_case plural; framework and Spatie tables stay as Laravel names them | `app_rooms`, `app_audit_logs` ([../structure/database.md](../structure/database.md)) |
| Columns | `snake_case`; foreign keys `<model>_id` | `company_id`, `first_name` |
| Config keys, env keys | `snake_case` config, `UPPER_SNAKE` env | `services.fbs_hub.url`, `FBS_HUB_URL` |
| Route names | dot-separated, lower case | `settings.rooms` |
| URLs | lower-case words with hyphens, hub then tab (rule 52) | `/time/attendance/roster` |

- **Every model sets `protected $table`**, and foreign keys pass the table: `->constrained('app_organizations')` (FBS Suites `CLAUDE.md`).
- **Fields never fillable:** tenant ids (`company_id`, `school_id`, `org_id`), roles and `status`. They are set by the scope, the action or a dedicated method.
- **People's names** are stored in parts (`first_name`, `last_name`, optional `middle_name`); `name` is a read-only accessor, and tables of people show the parts in separate columns (FBS Suites, FBS Learn).

### TypeScript and React

File names follow the project's UI kit (rule 6). Pick one and keep it for the whole project:

| | Kit A (shadcn + Radix: People, Pay, FBS Suites) | Kit B (FBS Learn kit: Learn, FCA-SMS) |
|---|---|---|
| Component files | `kebab-case.tsx`: `components/ui/button.tsx`, `confirm-dialog.tsx` | `PascalCase.tsx`: `components/Button.tsx`, `ConfirmDialog.tsx` |
| Page files | `kebab-case.tsx` in a folder per hub: `pages/people/org.tsx` | `PascalCase` + `Page`: `pages/settings/academic/RoomPage.tsx` |
| Hooks | `use-thing.ts` | `useThing.ts` |
| API service | `api-service/<module>/<name>.ts` | same |

- Components, types and interfaces are `PascalCase`; functions and variables `camelCase`; constants that never change `UPPER_SNAKE`.
- A component is named for what it is, not where it is used: `DataTable`, not `EmployeesTable` (rule 51).
- Record types end in `Record`, input types in `Input`: `RoomRecord`, `RoomInput`.

## 3. Size: 300 code lines per file

- **Limit:** every source file (TS, TSX, PHP, CSS, Blade) stays at 300 code lines or fewer; blank lines and comments don't count (rule 51).
- **Enforced in the build:**
    - JS and TS: `max-lines` at 300 in the linter config, with `skipBlankLines` and `skipComments`, plus `max-lines-baseline.json` for files already over.
    - PHP: a Pest test (`tests/Unit/PhpFileSizeTest.php`) over `app`, `routes` and `database`, plus `php-max-lines-baseline.json`.
    - Both baselines hold each listed file's size: it may not grow, and its entry is deleted once the file is split. New projects start with both empty. Copy FBS CRM's files as the template.
- **Split by purpose:** the data hook, the schema, the columns, each dialog and each tab are their own files in the page's folder. Controllers stay thin; queries go to `Support/*Query` classes, rules and calculations to actions or services.
- **Never** turn the rule off for a file (FBS Pay's lint `overrides` do this today and must move to a baseline).

## 4. PHP

- **Laravel conventions first.** Use what the framework gives (Form Requests, policies, resources, `Http::`, `Str::`, queues, `Context`) before writing a helper.
- **Validation:**
    - CRUD controllers on the shared `BaseController` return rules from `storeRules()` / `updateRules($model)` ([../structure/backend-patterns.md](../structure/backend-patterns.md)).
    - Every other write takes a Form Request (`app/Http/Requests`): FBS CRM has 95, Connect 90, Pay 20.
    - Allow-list searchable, filterable and sortable columns; never put a client value into SQL as a column or raw expression (rule 5).
- **Authorization:** every action calls a policy (`$this->authorize()`), and code checks permissions, never role names, except the owner guard (rule 30).
- **Responses:** JSON endpoints answer `{ success, message, data }`; errors `{ success: false, message, errors }` with the right status (rule 5). Output goes through a `JsonResource` that names its fields; never return a model's `toArray()` from a new endpoint.
- **Static analysis:** Larastan level 7. No `@phpstan-ignore` comments: fix the type at its cause (FBS CRM `HANDOFF.md`). A baseline may only shrink (rule 49).
- **`declare(strict_types=1)`:** no FBS repo uses it today. Don't add it file by file.
- **Money** is `decimal(12,2)` columns or minor units, never a float; times are stored in UTC (rule 21).
- **Raw SQL** names the full table (`app_…`), qualifies columns, and stays inside the current tenant (`School::constrain()`, the global scope).

## 5. TypeScript and React

- **Strict TypeScript:** `"strict": true` and `"noImplicitAny": true` (People, CRM, Work). No `any` at the API boundary; use `unknown` and narrow it.
- **Imports** use the `@/` alias for `resources/js/*`, never long relative paths (`../../../`). Import from the specific file; no barrel files except the kit's one `components/ui/index.ts`.
- **Server data** lives in TanStack Query, never copied into `useState` or a global store; no component calls `fetch` or `axios` directly (rule 38).
- **Navigation** is Inertia: links and visits go through Inertia or the project's `router-compat` shim (Learn, FCA-SMS), never a second client router.
- **Shared helpers are imported, never redefined:** kit B has `apiErrorMessage` (`@/lib/apiErrorMessage`) and `downloadCsv` (`@/lib/downloadCsv`); kit A has `cn` (`clsx` + `tailwind-merge`, in `@/lib/utils`).
- **Browser-only code** (`window`, `localStorage`, `matchMedia`) runs after mount, so server rendering never breaks (rule 1). Wrap every `localStorage` read and write in `try`/`catch`.
- **Inertia page props** never use the shared names (`auth`, `flash`, and each app's own, such as `school` or `platform`): a page prop overrides the shared one.
- **No browser dialogs:** never `window.alert`, `confirm`, `prompt` or a `beforeunload` prompt. Ask in the app's own modal (kit A `askConfirm` / `ConfirmDialog`, kit B `useConfirm` + `ConfirmDialog`), and tell with a toast (rule 60).

## 6. Comments

- **Comment the why, never the what.** One line where the code can't say it: a business reason, a workaround, a limit.
- **Known shortcuts** get a marker the team can search for: `// shortcut: <the limit>, <when to upgrade>`. The same shortcut is listed in the module's "as built" note (rule 36).
- **Rule references:** when a rule drives an odd-looking choice, cite it: `// SYSTEM-RULES rule 51: …` (FBS CRM's `PhpFileSizeTest.php`).
- **Generated or exempt files** say so on their first line.
- No commented-out code; delete it (git keeps it).

## 7. Errors and logs

- **People see plain words** and what to do next; production shows no stack trace or SQL (rule 28). Field errors land on their field (rule 37).
- **Frontend:** turn API failures into `ApiError` in the client layer, and show them in a toast or inline through one helper (kit B: `apiErrorMessage(err, fallback)`).
- **Never swallow an exception** to make a job "succeed". Catch only where the work must go on, and `report()` what was caught (FBS Work wraps its hub sends this way; FBS Learn uses `rescue()` + `defer()` so a mail failure never undoes a sign-up).
- **Outside calls** (hub, gateways, AI) have short timeouts and run in queued jobs, never in the request that made the change (rule 32).
- **Never log** passwords, tokens, keys, webhook secrets or personal data (rule 18). Secrets use the `encrypted` cast, are shown once, show only their last 4 characters afterwards, and never appear in JSON, logs or audit arguments (FBS Suites, FBS Learn's `PlatformSetting`).

## 8. Octane-safe code

Every app runs (or will run) under Octane on FrankenPHP: one PHP worker serves many requests.

- No per-request state in `static` properties or singletons. Put the current tenant or user in Laravel `Context`, and always overwrite long-lived singletons (Spatie's team id) on every request.
- Caches hold arrays, never models (`cache.serializable_classes = false` in FBS Learn). Rebuild the model from the array.
- Build URLs from `config('app.url')`, never from the request's Host header: every proxy header is trusted.
- Add a test that serves several requests on one app instance when you touch tenant resolution.

Details: [../structure/backend-patterns.md](../structure/backend-patterns.md) and [../deployment/architecture.md](../deployment/architecture.md).

## 9. Dependencies

- **Ask the owner before adding, removing or upgrading a major dependency** (FBS CRM `HANDOFF.md` and `.ai/rules/index.md`). Say what it replaces and why the framework or an installed package can't do it.
- Commit `composer.lock` and `package-lock.json`; install from them (`composer install`, `npm ci`). Pin Docker images and tools to exact versions, never `latest` (rule 35).
- `composer audit` and `npm audit --omit=dev --audit-level=high` run in the build (rule 49).
- Remove packages nothing imports. FBS CRM and Connect have `formik`, `zod` and `axios` installed but unused.

## 10. Do / Don't summary

| Do | Don't |
|---|---|
| Let Pint and `vp fmt` format | Hand-format or argue style in review |
| Keep files under 300 code lines | Grow a file that is near or over the limit |
| `protected $table = 'app_…'` on every model | Rely on Laravel guessing the table, or set `DB_PREFIX` |
| Policies and Form Requests (or `storeRules()`) on every write | Trust a hidden button or a client-sent tenant id |
| `@/` imports and strict types | `any`, deep relative imports, barrels |
| `// shortcut: <limit>, <when to upgrade>` | Silent shortcuts or `TODO` with no owner |
| The app's own confirm modal and toasts | `alert`, `confirm`, `prompt`, `beforeunload` |
| `config('app.url')`, `Context`, arrays in cache | Host header URLs, statics, models in cache |
| Ask before adding a dependency | Add a package for a few lines of code |

## Known differences today

- **FBS Stock** is plain JavaScript (`.jsx`, `vite.config.js`), with PascalCase pages, relative imports and a single-file UI kit (`components/ui.jsx`). New code there still follows sections 4, 6–9; moving to TypeScript is a planned change, not a side task.
- **FBS Learn and FCA-SMS** have no `"strict"` in `tsconfig.app.json`. FCA-SMS's `npm run types:check` runs `tsc --noEmit` on a root `tsconfig.json` with no files, so it checks nothing; use `npx tsc -p tsconfig.app.json --noEmit` there (Learn's runs `tsc -b`).
- **FBS CRM and Connect** use a custom `fetch` wrapper (`lib/api/client.ts`) instead of axios; FBS People uses a `fetch` wrapper in `lib/api.ts` (rule 38 lists the move).
- **FBS Pay's `resources/js/ops/**`** uses its own style (no semicolons, 2 spaces) and is excluded from the formatter.
- **FBS Learn, FCA-SMS, Stock and Work** have no Form Request classes; validation sits in controllers (Learn and FCA-SMS through `BaseController` hooks, which is allowed).
