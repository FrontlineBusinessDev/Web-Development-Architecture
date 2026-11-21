# Tenancy and auth

How an app keeps each customer's data apart, how people sign in, and how roles, platform staff and API keys work.

- **Applies to:** every FBS app. The tenancy half applies to multi-tenant products (People, Pay, Learn, Connect, Work, Stock); single-organisation apps (CRM, FCA-SMS) skip it.
- **Sources:** People `app/Models/Company.php`, `Models/Scopes/CompanyScope.php`, `Models/Concerns/BelongsToCompany.php`, `Http/Middleware/ResolveCompany.php`, `config/fortify.php`, `config/permission.php`, `SYSTEM-RULES.md` rules 5, 7, 29, 30, 31 and 32; Pay `docs/PLAN.md` section 4; Learn and MCP `CLAUDE.md`.
- **Last updated:** 2026-11-18 (planned: hub-signed connector tokens, two-value integration switches, several tenants per org). Before: 2026-11-16

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
- **Spatie on Octane:** set `permission.register_octane_reset_listener` to `true`. The permission registrar outlives a request under Octane and otherwise keeps the role → permission list it first loaded, so a role made later (a new tenant, a reset sample tenant, a custom role) has no permissions on the workers that loaded before it, and its people get 403 on some requests only (FBS People QA 2026-11-19; People `9ea0cc38`, Learn `c7ef9b7b`). The cache store must be shared across web containers (database or Redis).
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
| Google, Microsoft | Through FBS ID: one Google client and one Microsoft app registration, in FBS Suites only. The app's buttons send `idp_hint` (below) | Signs in **existing** accounts only, unless the product's sign-up flow says otherwise; a tenant may limit it to its own Workspace domain or Entra tenant. Before an app has its FBS ID side, its own Socialite client |
| Password reset, invites | Fortify reset links | Expire and work once. An invite is the user row plus a reset link |
| Sessions | Redis | Ended on password change, role removal or deactivation; `SESSION_SECURE_COOKIE=true` |

- Changing email, password or two-step settings asks for the password again and emails the person.
- Sign-ins, failures, lockouts and security changes go to the audit log.

### Sign-up

- **Self-serve products** (People, Learn, Pay …): one sign-up flow creates the tenant through one action (`CreateSchool`, a company provisioner), assigns the owner role, and sends `tenant.signed_up` to the FBS Suites hub after commit ([hub contract](../integrations/fbs-suites-hub.md)). Rate-limit it.
- **Internal tools** (FBS Suites MCP, CRM): no public registration. Staff create the first account from the console (`staff:create`), admins invite members.

### FBS ID (single sign-in across the suite)

One account for every FBS app, like a Google account across Gmail and Drive (owner's decision 2026-11-16: each app keeps its own sign-in page, and Google / Microsoft are centralized).

- FBS Suites MCP is the **OpenID Connect provider** (Laravel Passport, `App\FbsId\*`). Accounts are made from sign-up events (`OrgProvisioner`) or when an app vouches for its user.
- **Each app keeps its own sign-in page** (email + password, Google, Microsoft). There is no "Sign in with FBS" button and `/login` is not redirected.
- **Password, server to server** (owner 2026-11-17: the browser never visits FBS ID): the app posts the email, the password and its own verdict on its local copy to `POST {FBS Suites}/api/fbs-id/password` (HTTP Basic with the app's FBS ID client id and secret) and gets a one-use ticket (10 minutes). It redeems the ticket at `POST {FBS Suites}/api/fbs-id/sign-in {ticket, code?, nonce?}`, which answers `{id_token}` (amr `pwd`, or `pwd` + `otp`). On the id_token the app signs the person in, links `users.fbs_sub` and keeps the password as its fallback copy. FBS ID unreachable = the app's own copy. Old app passwords become the FBS ID password once, until a cut-off date (`FBS_ID_VERIFY_PASSWORD_UNTIL`).
- **Google / Microsoft:** the app's buttons go to `/oauth/authorize?idp_hint=google|microsoft`; the person sees only Google or Microsoft. Discovery lists the providers set up (`fbs_idp_hints_supported`). A refusal comes back to the app's callback as `error=access_denied` with the reason.
- **Two-step, on the app's own page:** a person with FBS ID's code on gets `409 two_factor_required` from `/sign-in`; the app shows its own code page and posts the same ticket again with `code` (a current code or an unused recovery code). A wrong code is 422 and keeps the ticket; 5 misses end it. One code serves every app; apps set it up through `POST /api/fbs-id/two-factor/{status,setup,confirm,disable,recovery-codes,verify,reset}`.
- **Silent sign-in on any visit (owner 2026-11-21, replaces "no silent sign-in" of 2026-11-17; FBS ID side built 2026-11-21 (session ticket, `/fbs-id/session`, sign-out ends the FBS ID browser session); FBS Pay's side built 2026-11-21 (laravel-fbs-pay `0cc34d96`: `App\FbsId\SilentSignIn`, `FbsIdSilentSignInTest`; also never silent after the app's idle timeout, FBS ID's back-channel sign-out, `?no_sso=1` or with a flashed message to show); FBS People's side built 2026-11-21 (laravel-fbs-people `fa35a308`, `de505966`: `App\FbsId\SilentSignIn`, `App\Http\Responses\SignedInResponse` for Fortify's login and two-factor responses, `FbsIdSilentSignInTest`; the same never-silent cases, plus a `?company=` with SAML SSO; the hop also after People's own two-step code while the 60 s ticket lasts, never on a company's own address); FBS Work's side built 2026-11-21 (laravel-fbs-work `47eb8ab`: `App\FbsId\SilentSignIn`, hop in `AuthController::signIn` so also after Work's own two-step code while the 60 s ticket lasts, `FbsIdSilentSignInTest`; the same never-silent cases, plus after leaving the demo, "sign out of all devices", the shared demo workspace and a demo sandbox; Work has no idle timeout, tenant hosts or SAML); FBS Stock's side built 2026-11-21 (laravel-fbs-stock `2c80806`: `App\FbsId\SilentSignIn`, hop in `Landing::after` so also after Stock's own two-step code while the 60 s ticket lasts, `FbsIdSilentSignInTest`; the same never-silent cases; the `/demo` sample never reaches `/login`; Stock has no idle timeout, tenant hosts or SAML); FBS CRM's side built 2026-11-21 (laravel-fbs-crm `399b4ed`: `App\FbsId\SilentSignIn`, `FbsIdSilentSignInTest`; the CRM is a Sanctum SPA, so the silent trip is a 409 location from the SPA's Inertia visit to `/login`, and the hop is the login JSON's `redirect` (after the form, FBS ID's code step or the CRM's own code step while the 60 s ticket lasts) back to the CRM's home, which opens the page first asked for; the same never-silent cases, plus a code step waiting and the local demo accounts; staff and client portal alike keep the CRM's own two-step code, and a person with FBS ID's code on is signed in silently only with `otp`; the CRM has no idle timeout, tenant hosts or SAML); the other apps' side not built yet: `fbs-suites-mcp/docs/superpowers/plans/2026-11-21-silent-sign-in.md`):** a sign-in in any app also starts an FBS ID browser session (one hop through `{FBS ID}/fbs-id/session` with a one-use `session_ticket` from `/sign-in`), and an app's `/login` asks FBS ID once per browser session with `prompt=none`, so someone signed in to one app is signed in to the next. Only existing app users; a two-step person only when their FBS ID session proved `otp`; never on a tenant host, a SAML-required company, the Test Drive or right after the app's own sign-out. Until an app's side is built, that app has no `prompt=none` check and no `/oauth/authorize` hop on `/login`. FBS ID never creates an app account.
- **Signed out everywhere:** the app's sign-out is local, then a best-effort `POST {FBS Suites}/api/fbs-id/sign-out {sub}` (Basic auth); FBS ID's back-channel logout (`POST {app}/auth/fbs/backchannel-logout`) ends that person's sessions in the other apps. There is no `/oauth/logout` redirect.
- **id_token claims** (RS256, 5 minutes, checked with the JWKS from discovery): `sub`, `email`, `email_verified`, `given_name`, `family_name`, `name`, `org`, `tenants` (the asking app's only), `fbs_staff`, `two_factor`, `amr`, `auth_time`, `nonce`, and `hd` when present.
  - `two_factor` (bool): FBS ID's code is set up and confirmed. When it says `false`, the app turns its own `users.fbs_two_factor` off and signs the person in; otherwise an app that still remembers the code as on would refuse them (no `otp`).
  - `hd` (string, optional): Google's Workspace domain, lower case, only on a Google sign-in (amr `fed`) and only when Google sent one. Never on a Microsoft or password sign-in. Learn matches a school's Workspace domain with it.
- **Resetting the code** (the person lost their authenticator and recovery codes): an app admin's "Reset 2FA" calls `POST {FBS Suites}/api/fbs-id/two-factor/reset {sub}` (Basic auth, 10 a minute per app + person; answers `{success, message, data: {enabled: false}}`, 403 for FBS staff, 404 for an unknown `sub`), then clears its own copy. FBS staff are reset only from the terminal, `php artisan fbsid:reset-two-factor {email*} {--all}` in FBS Suites, and set the code up again at their next sign-in. Both are audited `fbsid.two_factor.reset`, never with a code.
- **What FBS ID asks the app** (Basic auth with the app's client id + secret, yes or no only): `POST {app}/api/fbs/verify-password`, `POST {app}/api/fbs/lookup`.
- **Reference: FBS Pay** (`App\FbsId\*`: `FbsId`, `PasswordSignIn`, `FbsAccounts`, `Jwt`, `SignedOut`; `FbsLoginController`, `FbsApiController`). Its address, client ID and secret are set on the app's platform / developer settings page, encrypted, no restart (rule 12; Pay: Platform › Keys & secrets › FBS ID); `.env` `FBS_ID_*` only as a fallback. None set = the app signs in on its own. Setup: MCP `docs/fbs-id-setup.md`; design: MCP spec `2026-11-05-single-login-and-toggle-mcp-design.md`, "Revision 2026-11-16".
- **Built in every app** (2026-11-16/17): Pay, Connect, Stock, Work, People, Learn and the CRM (staff and client-portal users; a portal user's `tenant_ref` is the client company id). Each app's settings page is listed in MCP `docs/fbs-id-setup.md`.
- **Tenant hosts** (a school host, a company's own domain): cookies there don't reach the callback, so the callback stays on `APP_URL`. On a tenant host the password check, the two-step code and FBS sign-out still go through FBS ID server to server; Google / Microsoft come back through the app's own cross-host hand-off (Learn `/auth/google/start` + `/auth/google/complete`).
- **Never through FBS ID:** demo / test-drive / sandbox accounts, and users of a company that requires SAML SSO (People) — refused with the app's existing SSO message.

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
| The FBS connector (tenants in Claude) | FBS ID OAuth on the gateway's `/mcp` (PKCE, dynamic client registration, consent once), scope `mcp` | as gateway keys |
| FBS Suites → app MCP (no pasted key) | `POST /api/fbs/token`: the app's FBS ID client id + secret (Basic) and a 60 s RS256 assertion checked with FBS ID's JWKS (`aud` = app key, single-use `jti`) → a 15-minute MCP token of the app's own type, for a user in that tenant, or `service` (aggregate tools only). Contract `mcp-fbs-suites/docs/contracts/fbs-id-token.v1.md` | the app's plan gate and the tenant's **AI access** switch (default on) |
| Hub webhooks in | HMAC signature (`X-FBS-Signature`) | 300 s clock skew, stored once per event id |
| App → app connector (planned, spec `2026-11-18-multi-tenant-advanced-tools-webhooks.md` §10.1 #7) | A 5-minute RS256 connector token the hub signs (`aud` = the receiving app, `tenant`, `scope` such as `connector.person.write`, single-use `jti`), checked with FBS ID's JWKS; no stored key | only for a linked pair with both switches on; stored `fbs{app}1.` keys stay for tenants not on FBS Suites |

- **Integration switches (planned, owner 2026-11-18, same spec §2):**
  - Every switch that lets FBS Suites act for a tenant (AI access, Linked by FBS Suites, Share events) defaults to **on** and has **two values**: the app's own (`admin_on`, set by its admin) and the hub's (`suite_on`, set by the org admin in FBS Suites).
  - The effective state is both on. Neither side writes the other's value, so one side turning back on never needs the other.
  - The hub enforces its value by not signing tokens. The app keeps a copy only to show "Off in FBS Suites by …".
- **Several tenants per org (planned, same spec §1):** one person may reach several tenants of one app. Every token stays for one person in one tenant, and the app refuses a person with no account there (`403 not_a_member`).
- Tokens never go in `localStorage`.
- Secrets (webhook secrets, app keys, gateway keys) use the `encrypted` cast, are shown once, and never appear in JSON, logs or audit arguments.

## Audit

One append-only audit log per app: who (person, staff member, or AI agent for a person), what (`employee.updated`), the record, when (UTC), from where, the reason. Field **names**, never sensitive values. Owners read and export their tenant's log; platform staff have their own.

## Known differences today

> Existing apps are left as they are (owner's decision, 2026-11-06). This list is a record, not a to-do: new applications follow the standard and must not repeat these. See [the new-project checklist](../docs-and-ai/new-project-checklist.md), section 11.

| App | Differs from the standard |
|---|---|
| CRM | No tenancy (visibility by department); Sanctum cookie login via `POST /api/login`; custom `EnsureRoleHasPermission` instead of Spatie; no 2FA |
| Connect | Sanctum + `pragmarx/google2fa` instead of Fortify; no Spatie |
| Stock | No global scope (`store_id` filtered by hand in each controller); custom `AuthController`, google2fa |
| Work | Hand-rolled Google OAuth and TOTP (`App\Support\Totp`); custom auth controller |
| Pay | Capabilities in `App\Support\Capabilities`; `super_admin` sees no company rows |
| People | Also SAML SSO and SCIM (Enterprise plan); the "sign out everywhere else" device list is not built yet |