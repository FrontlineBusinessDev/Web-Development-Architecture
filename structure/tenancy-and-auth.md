# Tenancy and auth

How an app keeps each customer's data apart, how people sign in, and how roles, platform staff and API keys work.

- **Applies to:** every FBS app. The tenancy half applies to multi-tenant products (People, Pay, Learn, Connect, Work, Stock); single-organisation apps (CRM, FCA-SMS) skip it.
- **Sources:** People `app/Models/Company.php`, `Models/Scopes/CompanyScope.php`, `Models/Concerns/BelongsToCompany.php`, `Http/Middleware/ResolveCompany.php`, `config/fortify.php`, `config/permission.php`, `SYSTEM-RULES.md` rules 5, 7, 29, 30, 31 and 32; Pay `docs/PLAN.md` section 4; Learn and MCP `CLAUDE.md`.
- **Last updated:** 2026-11-06

## Tenancy

### The pattern

One database, one tenant id column on every tenant-owned table, one global scope that **fails closed**.

| Piece | People (reference) | Job |
|---|---|---|
| Tenant model | `Company` | the customer organisation |
| Current tenant | `Company::currentId()`, `enter()`, `run($id, fn)`, `constrain($query)` | reads and writes a **hidden Laravel `Context`** key (`company_id`) |
| Model trait | `BelongsToCompany` | adds the scope; stamps `company_id` on create, or throws when no tenant is current |
| Global scope | `CompanyScope` | `Company::constrain($builder)`: no current tenant means **no rows**, never all rows |
| Narrower scope | `BranchScope` | limits to the person's branches |
| Middleware | `ResolveCompany` | sets the tenant per request from the signed-in user (or the host), and **always writes the outcome** so an Octane worker never carries one request's tenant into the next |
| Jobs | `Context::hydrated` hook | queued jobs inherit the tenant through Context; the hook re-applies Spatie's team id for every job |
| Plan gates | `feature:<module>` middleware (`EnsureFeature`) | modules a plan leaves out are blocked on the server, not only hidden |

Other apps use the same pattern under their own names:

| App | Tenant | Trait / scope |
|---|---|---|
| Pay | `Company` | `App\Concerns\BelongsToCompany` (`whereRaw('1 = 0')` without a company), `CurrentCompany::run()` / `acrossCompanies()` |
| Learn | `School` | `BelongsToSchool`, `School::currentId()` / `run()` / `enter()`, `ResolveSchool` |
| Connect | `Business` | `BusinessScope` (matches nothing without a current business) |
| Work | `Workspace` (inside an Account) | `BelongsToWorkspace`, `ScopedThroughParent` for child rows |

### Rules

- Every tenant-owned table has the tenant id, first in its indexes and unique keys ([database](database.md)).
- The tenant id is **never fillable** and never taken from request input. Resolve it from the session, the host or the API key.
- Another tenant's record is a **404**, for read, update and delete.
- `User` and Spatie `Role` have **no** global scope (sign-in must find any email; a role scope poisons Spatie's permission cache). Filter them with the tenant's `constrain($query)` helper, never `where('company_id', $id)` with an id that may be null.
- Bulk inserts and raw `DB::table()` writes skip the trait: set the tenant id yourself.
- Turning a scope off (`withoutGlobalScope`) needs a comment saying why. Guest and public routes find the record across tenants on a globally unique key, then run the rest inside `Tenant::run($record->tenant_id, …)`.
- Cache keys, file paths (`{AWS_BUCKET_FOLDER}/…/companies/{id}/…`), queues and rate limits carry the tenant id.
- Every module has an isolation test: a second tenant cannot list, read, change or delete the first one's records.
- Plans decide what a tenant can **use**, never what it **keeps**: a downgrade or failed payment never deletes or hides data.

### Platform staff

- Platform staff are separate users with a `platform_role` (People: owner, finance, support, developer; Learn: developer, system_admin) and **no tenant id**. Never a tenant user with a flag.
- They work in the platform console (`/console` in People, `/platform` in Learn and Connect), guarded by `EnsurePlatformStaff`. Console routes run with **no current tenant**, so cross-tenant queries lift the scope explicitly.
- Entering a tenant needs a reason, is time-limited, and shows in that tenant's audit log.
- `platform_role`, tenant role and `status` columns are never fillable.

## Sign-in

### Standard

| Need | Package | Rule |
|---|---|---|
| Web sign-in | Laravel Fortify (session cookie, same origin) | Throttled per account and IP; "Wrong email or password", never "No such user" |
| Two-step sign-in | Fortify two-factor (authenticator app) | **Required** for owners, admins and platform staff before admin pages |
| Passkeys | Fortify passkeys | Offered to everyone |
| Google, Microsoft | Laravel Socialite | Signs in **existing** accounts only, unless the product's sign-up flow says otherwise; a tenant may limit it to its own Workspace domain or Entra tenant |
| Password reset, invites | Fortify reset links | Expire and work once. An invite is the user row plus a reset link |
| Sessions | Redis | Ended on password change, role removal or deactivation; `SESSION_SECURE_COOKIE=true` |

- Changing email, password or two-step settings asks for the password again and emails the person.
- Sign-ins, failures, lockouts and security changes go to the audit log.

### Sign-up

- **Self-serve products** (People, Learn, Pay …): one sign-up flow creates the tenant through one action (`CreateSchool`, a company provisioner), assigns the owner role, and sends `tenant.signed_up` to the FBS Suites hub after commit ([hub contract](../integrations/fbs-suites-hub.md)). Rate-limit it.
- **Internal tools** (FBS Suites MCP, CRM): no public registration. Staff create the first account from the console (`staff:create`), admins invite members.

### FBS ID (single sign-in across the suite)

- FBS Suites MCP is the **OpenID Connect provider** (Laravel Passport, `App\FbsId\*`), with branded login per app and Google / Microsoft behind it.
- Accounts are made from sign-up events (`OrgProvisioner`).
- Old passwords move over lazily: the provider asks the app's `POST /api/fbs/verify-password` (Basic auth with the app's OIDC client id and secret) until a cut-off date.
- The apps' "Sign in with FBS" side is phase 2 and not built yet. Until then each app keeps its own Fortify sign-in. Setup: MCP `docs/fbs-id-setup.md`.

## Roles and permissions

- `spatie/laravel-permission` with **teams on the tenant id** (`'teams' => true`).
- Code checks **permissions** (`people.view`, `payroll.run`), never role names, except the guard that keeps the last owner.
- Default roles live in one class (`App\Support\DefaultRoles`) and are created per tenant by a seeder. Tenants copy and edit them into custom roles.
- Relationship rules (my record, my team, my branch) live in policies.
- Least privilege: new people get the lowest role; only an owner grants admin roles; nobody grants more than they hold.
- Every grant and removal is audited.

## API keys and machine access

| Caller | Auth | Limits |
|---|---|---|
| The app's own pages | Session cookie + XSRF token | `throttle:120,1` on CRUD controllers |
| Public API (`/api/v1/…`) | Bearer token: scoped, hashed at rest, expiring, revocable | per-key rate limit |
| MCP endpoint (`POST /api/mcp`) | Sanctum key from Settings › API keys, never a browser session | `auth:sanctum`, `throttle:60,1` |
| FBS Suites gateway keys | Sanctum, prefix `fbsm_`, shown once | 60 MCP requests a minute per key; staff keys never hold `write` |
| Hub webhooks in | HMAC signature (`X-FBS-Signature`) | 300 s clock skew, stored once per event id |

- Tokens never go in `localStorage`.
- Secrets (webhook secrets, app keys, gateway keys) use the `encrypted` cast, are shown once, and never appear in JSON, logs or audit arguments.

## Audit

One append-only audit log per app: who (person, staff member, or AI agent for a person), what (`employee.updated`), the record, when (UTC), from where, the reason. Field **names**, never sensitive values. Owners read and export their tenant's log; platform staff have their own.

## Known differences today

| App | Differs from the standard |
|---|---|
| CRM | No tenancy (visibility by department); Sanctum cookie login via `POST /api/login`; custom `EnsureRoleHasPermission` instead of Spatie; no 2FA |
| Connect | Sanctum + `pragmarx/google2fa` instead of Fortify; no Spatie |
| Stock | No global scope (`store_id` filtered by hand in each controller); custom `AuthController`, google2fa |
| Work | Hand-rolled Google OAuth and TOTP (`App\Support\Totp`); custom auth controller |
| Pay | Capabilities in `App\Support\Capabilities`; `super_admin` sees no company rows |
| People | Also SAML SSO and SCIM (Enterprise plan); the "sign out everywhere else" device list is not built yet |
| All apps | "Sign in with FBS" (FBS ID client side) not built yet |
