# Database

How tables, columns, keys and migrations are named and written, so every app's schema reads the same and deploys without downtime.

- **Applies to:** every FBS Laravel app. PostgreSQL 17 in production, SQLite in memory for tests.
- **Sources:** People migrations and People `SYSTEM-RULES.md` rules 7, 21, 23 and 44; Learn `docs/reusable-components-in-all-projects.md` section 11; MCP `CLAUDE.md`; Pay `docs/PLAN.md` section 4.
- **Last updated:** 2026-11-07

## Table names: literal `app_`

Every table the app adds is named with a literal `app_` prefix in the code. Laravel's connection prefix stays empty.

| Do | Don't |
|---|---|
| `Schema::create('app_invoices', …)` | `Schema::create('invoices', …)` with `DB_PREFIX=app_` |
| `protected $table = 'app_invoices';` on every model | Leave a model to guess its table |
| `->constrained('app_companies')` | `->constrained()` with no table (Laravel guesses an unprefixed name) |
| `exists:app_companies,id`, `Rule::unique('app_invoices', 'number')` | `exists:companies,id` |
| `DB::table('app_invoices')`, raw SQL with the full name | Hard-code a bare name in `selectRaw` or a subquery |

Why literal names: the prefix shows in every query, migration and raw SQL; framework and package tables stay untouched; nothing depends on a global setting. **Pick literal names or `DB_PREFIX`, never both**, or Eloquent builds `app_app_x`.

**Unprefixed (framework and package tables):** `users`, `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `migrations`, `personal_access_tokens`, the Spatie tables (`roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`) and Passport's `oauth_*`. Do not rename `users`.

### Checklist for a new table

1. Migration: `Schema::create('app_<plural>', …)`.
2. Model: `protected $table = 'app_<plural>';`.
3. Foreign keys: `->constrained('app_other')`, with `restrictOnDelete()` for tenant and parent keys.
4. Pivots: name them `app_<a>_<b>` and pass the table and key names to `belongsToMany`.
5. Validation, raw SQL, seeders and tests: the full table name.
6. Long index or constraint names: give them a short explicit name. PostgreSQL truncates identifiers at 63 characters, MySQL refuses over 64.
7. Polymorphic types: add a `morphMap` before any class is renamed.

## Columns

| Kind | Standard |
|---|---|
| Primary key | `$table->id()` (bigint). Records shown in URLs that leave the app also get a public id (ULID or UUID). |
| Tenant | `foreignId('<tenant>_id')->constrained('app_<tenants>')->restrictOnDelete()`; never fillable |
| Status | `string('status', 20)->default('active')` with values from one `Statuses` class: `active`, `inactive`, `pending` |
| Money | `decimal(12, 2)`, always with its currency known. Never float columns. |
| Dates | `date` for calendar days; `timestamp` stored in UTC for moments. Shown in the tenant's time zone. |
| Timestamps | `$table->timestamps()` on every table. Append-only logs have no `updated_at`. |
| Booleans | `boolean(...)->default(false)`, named as a fact (`is_demo`, `requires_review`) |
| Enums | short `string` columns validated against a PHP enum or constant list; no database enum types |
| JSON | `json` for loose settings only; never for data you filter or join on |
| Names of people | `first_name`, `last_name` (required, 80 chars), optional `middle_name`; a person inside another record gets prefixed parts (`contact_first_name`). The full name is an accessor, never a column, and never stored next to the parts ([rule 68](../rules/system-rules.md)) |

## Keys and indexes

- **PostgreSQL does not index foreign keys.** Add an index for every foreign key and every column a list filters or sorts on.
- **Tenant first.** On a tenant-owned table, the tenant id leads every index and every unique key: `unique(['company_id', 'code'])`, `index(['company_id', 'created_at'])`. A test checks every tenant table has an index led by the tenant id (Learn `ScaleTest`).
- Uniques are per tenant unless the value is global by nature (emails of sign-in accounts, slugs used in hosts).
- A `TEXT` column with a unique key needs a length on MySQL; prefer `string(n)` for anything unique.

## Record lifecycle: archive before delete

- Every user-managed table has a `status` column. Archive sets it to `inactive`; restore sets it back to `active`.
- Delete is allowed only for archived records with nothing linked to them (the base controller's `$requireInactiveBeforeDelete` and `$inUseRelations`, see [backend patterns](backend-patterns.md)).
- No soft deletes by default. Use `softDeletes()` only when a module needs restore after a real delete, and say why in the migration.
- Lists hide archived rows unless the Archived filter asks for them.

## Migrations

**Every migration must work with the release that is still running.** The old containers keep serving while `migrate` runs ([publish and rollout](../deployment/publish-and-rollout.md)).

| Change | Do it in two deploys |
|---|---|
| Add a column | Add it nullable or with a default. Code that requires it ships in the same or a later deploy. |
| Rename a column | Deploy 1: add the new column, write both, backfill. Deploy 2: read the new one, drop the old. |
| Drop a column or table | Deploy 1: stop using it. Deploy 2: drop it. |
| Make a column required | Deploy 1: backfill and write it everywhere. Deploy 2: add `NOT NULL`. |
| Change a type | Add a new column, move the data, switch, then drop. |

Rules:

- One migration per change. File name `YYYY_MM_DD_HHMMSS_<verb>_<table>.php`, with a one-line comment saying why.
- Write a working `down()`.
- Data migrations (moving or fixing rows) are their own migration, written with the query builder, not models, so later model changes don't break them.
- Never edit a migration that has run in production. Add a new one.
- Large backfills run in chunks or as a queued command, not inside one migration transaction.
- Seeders that production needs (roles, plans, reference data) are idempotent (`updateOrCreate`) and run from `scripts/migrate.sh`. Demo data never runs in production unless the app is a demo site.

## Multi-tenant tables

A tenant-owned table:

1. has the tenant id column, constrained and never fillable;
2. has the tenant id first in every index and unique;
3. uses the model trait that adds the fail-closed global scope ([tenancy and auth](tenancy-and-auth.md));
4. is covered by an isolation test (another tenant's id returns 404).

Bulk inserts and `DB::table()` writes skip model events, so they must set the tenant id themselves. Platform-wide tables (plans, apps, platform settings) have no tenant id and say so in the migration comment.

## PostgreSQL gotchas

- `havingRaw` on a `withSum()` alias works on SQLite and MySQL; PostgreSQL rejects output aliases in `HAVING`. Repeat the expression.
- `LIKE` is case-sensitive in PostgreSQL. Use `whereLike` / `ILIKE` for search.
- A `where('col', null)` with a variable that may be null becomes `IS NULL` and matches the wrong rows; guard it.
- Tests run on SQLite, so run one suite against PostgreSQL before relying on raw SQL ([testing and CI](../quality/testing-and-ci.md)).

## Known differences today

> Existing apps are left as they are (owner's decision, 2026-11-06). This list is a record, not a to-do: new applications follow the standard and must not repeat these. See [the new-project checklist](../docs-and-ai/new-project-checklist.md), section 11.

| App | Differs from the standard |
|---|---|
| CRM, Connect | `'prefix' => env('DB_PREFIX', 'app_')` on every connection; migrations use bare names |
| Pay | `app_` prefix only on the SQLite connection, so production tables have no prefix |
| Work | Renames the users table to `app_users`; not the standard. Users keep a single `name` column (rule 68); `staff_name` snapshots in audit tables are allowed |
| People | Rule 68: follows it since Nov 7 (`User::name`, `emergency_name`, `contact_name` and `dpo_name` are read-only accessors over the parts). The old `users.name`, `app_employees.emergency_name`, `app_agencies.contact_name` and `app_companies.dpo_name` columns are nullable, unused and dropped in the next release (rule 23) |
| Pay | Rule 68: `employees` has a single `name` column |
| Learn | Rule 68: `full_name` (`applicant_name` on applications) kept next to the parts on students, guardians, applications and staff; single-name `drivers.name`, `sponsors.contact_person`, `requested_documents.requester_name` |
| CRM, Connect | Rule 68: `campaign_contacts.name`; CRM also `activity_email_recipients.name` |
| Stock | Rule 68: `app_suppliers.contact_person` |
| Stock | `demo_leads`, `push_subscriptions` without the prefix; tenant id filtered by hand in controllers, no global scope |
| Learn, FCA-SMS | Plain table names (`students`, `rooms`), no prefix; do not retrofit without a decision (a rename touches about 50 tables and every raw query) |
| Learn | Scopes by `school_id` with its own `BelongsToSchool` trait (same pattern, different name) |
