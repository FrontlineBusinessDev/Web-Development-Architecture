# Testing and CI

The checks every change passes before it can deploy, the one command that runs them, and how tests are written.

- **Applies to:** every FBS Laravel app.
- **Sources:** People `SYSTEM-RULES.md` rules 22, 49 and 51 and its `composer ci:check`; CRM `composer ci:check` (audits, no baseline), `tests/Unit/PhpFileSizeTest.php` and `vite.config.ts` (`max-lines`); Learn `tests/Feature/MultiSchool/` (isolation suite, Octane multi-request test); FCA-SMS CI (PostgreSQL job).
- **Last updated:** 2026-11-06

Related: [rule 22 (definition of done)](../rules/system-rules.md#rule-22-definition-of-done), [rule 49 (checks gate the build)](../rules/system-rules.md#rule-49-static-checks-and-tests-gate-every-build-inside-the-docker-build), [coding-standards.md](../rules/coding-standards.md), [docker-image.md](../deployment/docker-image.md).

## Where the checks run

| Where | What runs | Gate? |
| --- | --- | --- |
| Your PC, before each commit | `composer ci:check` | Habit. A red run is not committed. |
| The Docker build (`checks` stage) | `composer ci:check` | **Yes.** A failure fails the image build; nothing deploys. |
| `deploy/build-image.sh`, after `checks` | the test suite on PostgreSQL 18 | **Yes.** A failure stops the deploy. |
| GitHub Actions | optional quick feedback on a push | **No.** The deploy never waits for it. If a workflow stays, it watches `master` and runs the same `composer ci:check`. |

The build runs on whichever machine runs `php artisan deploy` (or on the server when it builds), so the same code always meets the same checks.

**Test on your PC, not on the server.** Run `composer ci:check` before each commit, then `php artisan deploy`, which builds the image, and so runs the gate, on your PC. Don't use a server build (Coolify's Redeploy of the app) to find out whether the tests pass: it takes CPU and memory from the live apps on the same VPS, and Coolify puts the app's production variables into the build ([docker-image.md](../deployment/docker-image.md)). A server build still runs the gate as the fallback (rule 67), but it should only ever see code that already passed on a PC.

## `composer ci:check`

One command, the same everywhere. Order: fast and cheap first, tests last.

| # | Step | Command | Fails on |
| --- | --- | --- | --- |
| 1 | JS/TS format and lint | `npm run check` (`vp check`: `vp fmt --check` + `vp lint`, warnings are errors) | unformatted file, lint warning, file over 300 lines |
| 2 | TypeScript | `npm run types:check` (`tsc --noEmit`, strict) | any type error |
| 3 | JS unit tests, if the project has them | `npm run test --if-present` (`vp test run`) | a failing test |
| 4 | npm audit | `npm audit --omit=dev --audit-level=high` | a high or critical advisory in a runtime package |
| 5 | Composer audit | `composer audit --locked` | a known advisory |
| 6 | PHP style | `pint --parallel --test` | unformatted file |
| 7 | PHP static analysis | `phpstan analyse --memory-limit=1G` (Larastan level 7) | any new error |
| 8 | PHP tests | `php artisan test` (Pest, SQLite in memory) | a failing test, a PHP file over 300 lines |

`composer.json`:

```json
"scripts": {
    "lint": "pint --parallel",
    "types:check": "phpstan analyse --memory-limit=1G",
    "test": [
        "@php artisan config:clear --ansi",
        "pint --parallel --test",
        "@types:check",
        "@php artisan test"
    ],
    "ci:check": [
        "Composer\\Config::disableProcessTimeout",
        "npm run check",
        "npm run types:check",
        "npm run test --if-present",
        "npm audit --omit=dev --audit-level=high",
        "@composer audit --locked",
        "@test"
    ]
}
```

`package.json` scripts: `"check": "vp check"`, `"types:check": "tsc --noEmit"`, and `"test": "vp test run"` when there are JS tests. A script `ci:check` calls must exist: a missing npm script fails the build.

Run the formatter over the whole repo once, in its own commit, before turning the format check on ([coding standards](../rules/coding-standards.md#1-formatting)).

## Baselines that only shrink

- A **new project** starts with no baseline file of any kind.
- An **existing project** may keep, for errors that existed when the check was added:
  - `phpstan-baseline.neon` (with `reportUnmatchedIgnoredErrors: true`, so a fixed entry must be deleted),
  - `max-lines-baseline.json` (JS/TS files over 300 lines, each capped at its size then),
  - `php-max-lines-baseline.json` (PHP files over 300 code lines, same).
- A baseline never grows. A new error fails the build; a file that drops under its limit loses its entry in the same commit (the tests check stale entries).
- No `@phpstan-ignore` comments and no `eslint-disable`/`oxlint-disable` for a size rule. Fix the cause.
- How the 300-line limit is enforced: [coding-standards.md](../rules/coding-standards.md#3-size-300-code-lines-per-file).

## Writing tests (Pest)

**Layout:** `tests/Feature` (HTTP and jobs, one file per module), `tests/Unit` (pure rules and calculations, the file-size test), `tests/Support` (fakes such as `FakeUpstream`, `FakeClaude`), `tests/Pest.php` (shared setup: roles, the current tenant).

**Every module has feature tests for:**

1. The happy path of each endpoint: list, lookup, create, update, archive, restore, delete.
2. Validation: required fields, uniqueness, `exists:` on related ids.
3. Permissions: a role without the permission gets 403; a guest is redirected or gets 401.
4. **Tenant isolation** (multi-tenant apps): a record of tenant B is invisible (404) to a user of tenant A, in lists, lookups, show, update and delete. Keep one isolation suite (`tests/Feature/<Tenant>Isolation/`) and add every new tenant-owned table to it.
5. Archive before delete: an active record cannot be deleted; an in-use record returns 422 with `errors.in_use`.

**Also:**

- **Unit tests** for money, dates, time zones and any calculation (rule 21).
- **An Octane test:** several requests on one application instance (two users, two tenants, one after the other) must not leak state between them. Pattern: Learn `tests/Feature/MultiSchool/ResolveSchoolTest.php`.
- **Jobs:** assert they are queued (`Queue::fake()`), and test `handle()` directly, including a second run (rule 33).
- **Outside services** are faked (`Http::fake()`, project fakes in `tests/Support`). Tests never call a real API; paid AI evals run only on request (their own group).
- **Health:** a test that `/up` returns 500 when `storage/framework/draining` exists.
- Use factories, not shared fixtures. Each test arranges what it needs.

## The test database

| Run | Database |
| --- | --- |
| Local and in the `checks` stage | SQLite in memory (`phpunit.xml`: `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`), array cache, session and mail, sync queue. Fast. |
| Before every deploy | The same suite on PostgreSQL 18 (below). |

### The PostgreSQL run

Production is PostgreSQL, and SQLite accepts queries PostgreSQL refuses (aliases in `HAVING`, loose types, case-insensitive `LIKE`). So `deploy/build-image.sh` runs the whole suite on PostgreSQL before the image is built:

1. builds the `checks` stage (already passed `composer ci:check`);
2. starts `postgres:18-alpine` on a private Docker network;
3. runs `php artisan test` from the `checks` image with `DB_CONNECTION=pgsql` and the test database's settings (they override `phpunit.xml`, whose `<env>` lines do not use `force`);
4. removes the database and the network.

To run it on your PC (Docker Desktop):

```bash
docker run -d --rm --name pgtest -p 55432:5432 -e POSTGRES_DB=testing -e POSTGRES_USER=testing -e POSTGRES_PASSWORD=testing postgres:18-alpine
```

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55432 DB_DATABASE=testing DB_USERNAME=testing DB_PASSWORD=testing php artisan test
```

```bash
docker stop pgtest
```

`SKIP_PGSQL_TESTS=1` skips it for one emergency deploy only ([publish-and-rollout.md](../deployment/publish-and-rollout.md#troubleshooting)).

## Browser tests (optional)

- Cypress or Pest browser tests for the top tasks (rule 8) are welcome. They are not part of `ci:check` yet: they need a running app.
- Select elements by role, label or a purposeful `data-cy` name. Never generated ids like `button-button-1`.
- Before a release, a person runs a smoke test of the top tasks in a real browser (rule 22, item 5).

## Definition of done

A change is done when every item of [rule 22](../rules/system-rules.md#rule-22-definition-of-done) is ticked: designed, built to the rules, feature and unit tests, a browser smoke test, accessibility and privacy checked, within the performance budget, documented, and shipped with `php artisan deploy` through the rolling deploy. "It works on my machine" is not done.

## Known differences today

> Existing apps are left as they are (owner's decision, 2026-11-06). This list is a record, not a to-do: new applications follow the standard and must not repeat these. See [the new-project checklist](../docs-and-ai/new-project-checklist.md), section 11.

| App | Differs |
| --- | --- |
| People | `ci:check` has no audits; the checks are not in the image build; 3,489-error PHPStan baseline (shrinks only); the 300-line rule is not enforced; GitHub workflows on `main` while it deploys from `master`. |
| CRM | Closest to the standard (audits, no PHPStan baseline, both 300-line checks), but `vp check` fails repo-wide, so it runs `vp lint` only; checks run in GitHub Actions, not the build. |
| Connect | No audits in `ci:check`; no 300-line check; GitHub Actions only. |
| Pay | 300-line rule turned **off** for files over it; no PHP size test; `resources/js/ops/**` outside the formatter. |
| Learn, FCA-SMS | `ci:check` calls npm scripts that do not exist (`lint:check`, `format:check`); oxlint, not `vp`; FCA-SMS `types:check` checks nothing (`tsc --noEmit` on an empty root config). Learn's build runs only its demo checks. FCA-SMS is the only app testing on PostgreSQL today (GitHub Actions). |
| FBS Suites MCP | `ci:check` calls PHPStan, which is not installed; workflow on `main`, deploys from `master`. |
| Stock, Work | PHPUnit, not Pest; no `ci:check`, no Larastan, no JS linter (Work has `tsc`). Stock is JavaScript. |
| All | Coverage is not measured (`coverage: none`). |
