# Backend patterns

How a module's server side is built: the shared CRUD base, the JSON contract, policies, jobs and the rules that keep long-lived workers safe.

- **Applies to:** every FBS Laravel app.
- **Sources:** People `app/Http/Controllers/v1/BaseController.php`, `app/Support/ListQuery.php`, `AppServiceProvider` (`crudModule`), `SYSTEM-RULES.md` rules 5, 33, 34, 43, 44, 51 and 53; Learn `docs/reusable-components-in-all-projects.md` sections 6, 7 and 10.5; Pay `docs/PLAN.md` section 4; MCP `CLAUDE.md`.
- **Last updated:** 2026-11-06

## How a request flows

1. **Page route** (`GET /<hub>/<page>`) returns the Inertia shell with only what the shell needs: the signed-in person, permissions, feature flags. No lists or records as props.
2. **The page calls JSON endpoints** on the same prefix through the one client layer ([frontend patterns](frontend-patterns.md)), with the session cookie and the XSRF token.
3. **The controller** authorizes with a policy, validates, calls an Action or the model, and answers through a Resource.

## The CRUD backbone

### Route macro: `Route::crudModule($prefix, Controller::class, $name)`

Defined once in `AppServiceProvider`. `{id}` is constrained to digits.

| Method | Path | Action |
|---|---|---|
| GET | `/` | `index` (the Inertia shell) |
| GET | `/pagination-search` | `paginationSearch` (the list) |
| GET | `/search-active` | `searchActive` (flat active rows for pickers) |
| GET | `/lookup` | `lookup` (paged options for async selects) |
| GET | `/{id}/in-use` | `inUse` (what blocks a delete) |
| POST | `/` | `store` |
| POST | `/{id}` | `update` (POST, not PUT, so file uploads work) |
| PATCH | `/{id}/archive` | `archive` |
| PATCH | `/{id}/restore` | `restore` |
| DELETE | `/{id}` | `destroy` |

Register any custom `POST` route (`/bulk-transfer`, `/{id}/approve`) **before** `crudModule`, or `POST /{id}` captures it.

### `BaseController` (`app/Http/Controllers/v1/BaseController.php`)

Configure a child with properties, not code:

| Property | Default | Purpose |
|---|---|---|
| `$model` | required | Eloquent model class |
| `$view` | required | Inertia page for `index` and `show` |
| `$searchable` | `[]` | columns the `search` term looks in |
| `$filterable` | `[]` | allow-list for `filters[col]` |
| `$exactFilters` | `['status']` | filters matched with `=` (status must be exact: `LIKE '%active%'` matches `inactive`) |
| `$sortable` | `['id']` | allow-list for `sort_by` |
| `$sortBy` / `$sortDir` | `name` / `asc` | default sort |
| `$resource` | `null` | `JsonResource` that shapes the output |
| `$activeColumns` | `['id', 'name']` | columns `searchActive` and `lookup` return |
| `$inUseRelations` | `[]` | relations that block a delete while not empty |
| `$requireInactiveBeforeDelete` | `true` | archive first, then delete |
| `$undeletableStatuses` | `['active']` | statuses that block a delete |
| `$fileFields`, `$fileFieldFolders`, `$fileUrlExpiry` | | uploads through `HandlesFileUploads`, served as presigned URLs |

Hooks to override: `storeRules()`, `updateRules($model)`, `validationMessages()`, `beforeSave($validated, $model)`, `afterCreate($model)`, `afterUpdate($model)`, `applySearch()`, `applySort()`, `newQuery()` (eager loads and counts go here).

Built in:

- Default middleware `auth` and `throttle:120,1`; `authorize()` runs the policy on `show`, `store`, `update`, `archive`, `restore` and `destroy`.
- `update` locks the row and compares the `version` the form sent (the record's `updated_at`); a stale save answers **409** with the current record (rule 43).
- `destroy` runs in a transaction with a row lock, applies archive-first, then `$inUseRelations`, and answers 422 with `errors.in_use: [{label, count}]`.
- Lists hide archived rows unless `filters[status]` asks for them (rule 44).

`index()` and `paginationSearch()` have no `authorize()` on purpose: route middleware guards them, and pickers on other modules' pages read those lists.

## The JSON contract

Every endpoint, every app (People rule 5):

| Case | Shape |
|---|---|
| Success | `{ "success": true, "message": "…", "data": … }` |
| List | `data` is `{ "data": [...], "meta": { "current_page", "last_page", "per_page", "total", "from", "to" }, "links", "filters", "search", "sort_by", "sort_dir" }` |
| Validation error | 422 `{ "message", "errors": { "field": ["…"] } }` |
| Other errors | `{ "success": false, "message", "errors" }` with 401, 403, 404, 409, 419 or 429 |

List query parameters, the same everywhere and mirrored in the page URL (rule 34):

| Parameter | Meaning |
|---|---|
| `search` | free text over `$searchable` |
| `filters[field]=a,b` | allow-listed field; several values mean "a or b" (rule 53); different filters mean "and" |
| `sort_by`, `sort_dir` | allow-listed column, `asc` or `desc` |
| `page`, `per_page` | `per_page` default **25**, at most **100** (`ListQuery::PER_PAGE = [10, 25, 50, 100]`) |

Rules:

- Output goes through a Resource that lists its fields. Never `toArray()` or `SELECT *` to the client. Dates ISO 8601; money as decimal strings or minor units with a currency.
- Never use a client value as a column name or raw SQL. Unknown filters and sort keys are ignored.
- Another tenant's id is a 404, never a 403.
- Production errors carry no stack trace or SQL.
- The public API is versioned (`/api/v1/…`) and uses bearer tokens; the web app uses the session cookie. Tokens never go in `localStorage`.

## Validation, policies, resources, actions

| Piece | Rule |
|---|---|
| Validation | `storeRules()` / `updateRules()` in a base-controller module; a FormRequest (`Store<X>Request`, `Update<X>Request`) for anything else |
| Policy | One per model (`app/Policies/<X>Policy.php`), checking **permissions**, not role names. Relationship rules (my team, my branch) live here |
| Resource | One per model (`app/Http/Resources/<X>Resource.php`). Add `can: {…}` flags when the UI needs to know what the person may do |
| Action | A workflow that touches several models (approve, finalize, import) is `app/Actions/<Module>/<Verb><Noun>.php` with one `handle()`, shared by controllers, jobs, commands and tests |
| Audit | Every write records one audit entry: who, what, when, the record, which fields changed (never the values of secrets or pay) |
| Fillable | Tenant ids, roles and status flags are never fillable |

## Jobs and schedules

- **Carry the tenant:** pass ids, not models, and enter the tenant before work. Tenant context set through Laravel `Context` is restored in the worker automatically.
- **Safe to run twice:** check state first, use idempotency keys for outside calls, mark work done in the same transaction.
- **Dispatch after commit** (`->afterCommit()`), so a rolled-back save sends nothing.
- **Limits:** set `$tries`, `backoff()` and `$timeout` on every job. The queue's `retry_after` (90 s) is longer than any job timeout; workers run with a `--timeout` above every job's own timeout.
- **Webhook-style retries:** backoff `[60, 300, 1800, 7200, 43200]` seconds, then mark the delivery dead.
- **Separate queues** for slow work (AI, exports, files) so it never holds up notifications and webhooks.
- **Schedules:** `withoutOverlapping()`, and `onOneServer()` when more than one container runs the scheduler.
- Never swallow an exception so a job "succeeds".

## Octane safety

Workers live for many requests. Write every class as if it does.

- No per-request state in `static` properties or singletons.
- Put the current tenant and user-scoped state in Laravel `Context` and **always write the outcome** in middleware, so one request's tenant never leaks into the next.
- Re-apply long-lived package state per job or request (Spatie's permission team in a `Context::hydrated` hook).
- Caches hold arrays of raw attributes, never models (`cache.serializable_classes = false`); rebuild models from them.
- Build URLs from `config('app.url')`, never from the request Host header.
- Commit `config/octane.php`.
- Add a test that serves several requests, as different tenants, on one app instance.

## A new CRUD module

Order: **migration → model → policy → resource → controller → route → client resource → page → navigation**, then tests.

```php
class InvoiceController extends BaseController
{
    protected string $model = Invoice::class;
    protected string $view = 'billing/invoices';
    protected array $searchable = ['number', 'customer_name'];
    protected array $filterable = ['status', 'branch_id'];
    protected array $exactFilters = ['status', 'branch_id'];
    protected array $sortable = ['id', 'number', 'issued_at', 'status'];
    protected string $sortBy = 'issued_at';
    protected ?string $resource = InvoiceResource::class;

    protected function storeRules(): array
    {
        return [
            'number' => ['required', 'string', 'max:40', Rule::unique('app_invoices')->where('company_id', Company::currentId())],
            'issued_at' => ['required', 'date'],
        ];
    }
}

// routes/web.php, inside the permission group
Route::post('billing/invoices/{id}/send', [InvoiceController::class, 'send']); // custom POSTs first
Route::crudModule('billing/invoices', InvoiceController::class, 'billing.invoices');
```

The table, model and tenant rules are in [database](database.md); the page side is in [frontend patterns](frontend-patterns.md); the test pattern is in [testing and CI](../quality/testing-and-ci.md).

## Allowed variant: module routes + actions (Pay)

A project may skip the base controller when its modules are mostly workflows:

- One route file per module, `routes/<area>/<module>.php`, prefix `api/<module>`, names `<module>.<action>`.
- A FormRequest per write, an Action per workflow, `JsonResource` only.
- It still follows the JSON contract, the list parameters, policies, the tenant scope and audit above.

Pick one style per project and write it in the project's `CLAUDE.md`.

## Known differences today

> Existing apps are left as they are (owner's decision, 2026-11-06). This list is a record, not a to-do: new applications follow the standard and must not repeat these. See [the new-project checklist](../docs-and-ai/new-project-checklist.md), section 11.

| App | Differs from the standard |
|---|---|
| People, Learn | `store` / `update` return the raw model, not the Resource (Learn keeps it on purpose: pages read `total_fee` as `'250.00'`); new modules pass the Resource |
| Learn, FCA-SMS | `per_page` default 10, clamped to 1..1000 |
| CRM, Connect | No base CRUD controller: `Controller::listResponse()`, `App\Support\Pagination` (default 10, cap 2,000), data endpoints under `/api/*` |
| Pay | Laravel `{data, links, meta}` lists with `sort` / `direction` and camelCase keys |
| Work | Has a `BaseController` that no controller extends |
| Stock | Empty base controller, tenant filtered by hand in each controller |
| People | `ListQuery` still accepts the old `filter[…]` and `sort=-col` names for one release |
