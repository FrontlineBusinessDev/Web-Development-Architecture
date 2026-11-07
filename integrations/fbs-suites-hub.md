# FBS Suites hub: the event contract

How an FBS app tells the rest of the suite that something happened (a sign-up, a payment, a demo visitor), and how the FBS CRM receives it.

- **Applies to:** every FBS app that sends events (People, Pay, Learn, Connect, Work, Stock), the hub (`fbs-suites-mcp`) and the receiver (`laravel-fbs-crm`).
- **Sources:**
  - `fbs-suites-mcp`: `docs/superpowers/specs/2026-11-05-mcp-fbs-suites-design.md` (Webhook hub), `app/Hub/{Signature,Router,HookRefusal,HookCheck}.php`, `app/Http/Controllers/HookController.php`, `app/Jobs/DeliverEvent.php`, `app/Coolify/*` (Platform › Coolify), migrations `2026_11_10_000100_route_demo_opened_to_crm` and `2026_11_12_000100_route_subscription_paid_to_crm`.
  - The senders `app/Support/SuiteHub.php`, `app/Jobs/SendSuiteHubEvent.php` and `app/Http/Controllers/SuiteHubCheckController.php` in People, Stock, Work, Learn, Pay and Connect.
  - `laravel-fbs-crm`: `routes/api.php`, `app/Http/Requests/Api/SignupIntakeRequest.php`, `app/Support/Intake/*`.
- **Last updated:** 2026-11-07

Copy the sender from [`templates/hub/`](../templates/hub/). When you add or change an event, update this file in the same piece of work.

## 1. The flow

```text
FBS app                         FBS Suites hub                          FBS CRM
-------                         --------------                          -------
model event / payment / demo
  SuiteHub::send()
  -> queue SendSuiteHubEvent
     (after the DB commit)
  -> POST {FBS_HUB_URL}/hooks/{app} --> HookController
     signed with FBS_HUB_SECRET         verify signature + clock skew
                                        store once in app_events
                                        answer 202
                                        RouteEvent -> Router
                                          one app_deliveries row per
                                          active destination that
                                          lists the event
                                          DeliverEvent ------------------> POST /api/v1/intake/signups
                                          (Bearer token)                    SignupIntake::record()
                                                                            (idempotent on event id)
```

- The app never calls the CRM directly. The hub is the only pipe.
- Routes are data. Each destination (`app_destinations`) lists the `events` it receives and its intake `path` (default `/api/v1/intake/signups`). Staff edit them on Platform › Destinations.
- An event that no destination lists is marked `ignored`. **An ignored event is never replayed.** So a new event needs its route before the first app sends it (section 7).

## 2. Leg 1: app → hub

### Request

`POST {FBS_HUB_URL}/hooks/{app}` with the JSON body and these headers:

| Header | Value |
|---|---|
| `Content-Type` | `application/json` |
| `X-FBS-Event-Id` | The event's `id` (a UUID). It must equal `id` in the body. |
| `X-FBS-Timestamp` | Unix seconds when the request was signed. |
| `X-FBS-Signature` | `sha256=` + hex HMAC-SHA256 of `timestamp + "." + raw body`, keyed with the app's secret. |

Signature, in PHP:

```php
'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret)
```

Test vector (use it in a unit test in every app and in the hub):

- secret `test-secret`, timestamp `1700000000`, body `{"a":1}`
- gives `sha256=8cb2c3355fca388e9ac2caec004f4d5d7045d74937ab5faad61dc11682247a9f`

Rules:

- Encode the body **once** and sign that exact string. Never re-encode after signing.
- The hub refuses more than **300 seconds** of clock skew (`Signature::MAX_SKEW_SECONDS`) and compares with `hash_equals`.
- `{app}` is the app key (section 5). The secret is per app.

### Answers

| Status | Body | Meaning |
|---|---|---|
| 202 | `{"success":true,"message":"Accepted","data":{"id":…,"duplicate":false}}` | Stored and queued for routing. |
| 202 | same, with `"duplicate":true` | Already stored under the same `(app, event_id)`. Treat it as done. |
| 200 | `{"success":true,"message":"Signature OK: …","data":{"ping":true,"app":…}}` | A verified `hub.ping` (section 2.1). Not stored, not routed. |
| 401 | `{"success":false,"message":"<the reason in words>","data":{"reason":…}}` | `reason` is `app_disabled`, `no_secret`, `bad_timestamp`, `stale_timestamp` (more than 300 s off) or `bad_signature`. |
| 404 | | No app with that key on Platform › Apps. |
| 422 | same shape | `reason` is `not_json` (not a JSON object), `id_mismatch` (`id` not a UUID or not the header's) or `no_event` (missing or over 60 characters). |

- The `message` says what to fix (`App\Hub\HookRefusal`), so a sender can put it in its own error: the job's log then reads "the app's FBS_HUB_SECRET is not the secret on Platform › Apps", not a bare 401.
- The hub audits every refusal with the app and the reason only, and keeps the last one on the app (`hook_refused_at`, `hook_refusal`; the last accepted hook in `hook_ok_at`), shown on Platform › Apps. It never audits the body.

### 2.1 Checking the connection

Platform › Apps › **Test connection** (`App\Hub\HookCheck`) proves both settings of one app in one round trip, from the hub's screen:

1. The hub POSTs `{"id":<uuid>,"event":"hub.check","version":1,"app":<key>,"hub_url":<hub address>}` to `{app base_url}/api/fbs-hub/check`, signed with that app's webhook secret (same headers and signature as section 2).
2. The app verifies it with its `FBS_HUB_SECRET`. A mismatch answers 401 with `data.reason` and `data.fingerprint` (the first 8 hex characters of `sha256(secret)`, never the secret), so the hub can show both fingerprints.
3. On a match the app sends a signed `hub.ping` to its `FBS_HUB_URL` exactly as its queued events go, and answers 200 with `data.hub_url` and `data.hub_reply` (`status`, `success`, `message`: what the hub said).

The result is kept on the app (`hook_test_*`). No session, API key or database is involved on the app's side. Locally the hub must serve more than one request at a time, because the app calls back during the test.

## 3. The envelope

Every event has these top-level keys, then its own blocks:

```json
{
  "id": "<uuid>",
  "event": "tenant.signed_up",
  "version": 1,
  "occurred_at": "<ISO 8601>",
  "app": "people"
}
```

- `event` is `noun.verb_past`, lowercase.
- `version` starts at 1. Bump it only for a breaking change, and keep the old version working until every sender moved.
- Receivers ignore unknown fields. Missing optional fields are null.

## 4. Event catalogue

### `tenant.signed_up` (v1)

A new tenant (company, school, business, workspace or store) was created.

```json
{"id":"<uuid>","event":"tenant.signed_up","version":1,"occurred_at":"<ISO 8601>","app":"pay",
 "tenant":{"ref":"42","name":"Acme Trading Inc.","type":"company","email":null,"phone":null,"country":"PH","website":null},
 "contact":{"first_name":"Maria","last_name":"Reyes","email":"maria@example.com","phone":null,"job_title":"Owner"},
 "plan":{"slug":"free","name":"Free","trial_ends_at":null},
 "signup":{"method":"web"}}
```

- **Fired from** the tenant model's `created` event, not the sign-up form, so every path is caught (web form, Google sign-up, staff-created tenant).
- `signup.method` is `web`, `google` or `staff`.
- **Skipped:** demo and sandbox tenants (`SuiteHub::quietly()`), tenants made from the console or seeders (`runningInConsole()` and not unit tests).
- **Sent by:** all six apps.
- **Routed to:** the `crm` destination. It also gives the contact an FBS ID (`OrgProvisioner`), after the deliveries are queued and inside a catch, so it can never keep a lead from the CRM.

### `subscription.paid` (v1)

A tenant's payment was settled or approved: first payment, upgrade, renewal or one-off. The CRM wins the app's lead and marks the client as paying. A repeat changes nothing there.

```json
{"id":"<uuid>","event":"subscription.paid","version":1,"occurred_at":"<ISO 8601>","app":"people",
 "tenant":{"ref":"42","name":"Acme Trading Inc.","type":"company","email":null,"phone":null,"country":"PH","website":null},
 "contact":{"first_name":"Maria","last_name":"Reyes","email":"maria@example.com","phone":null,"job_title":"Owner"},
 "plan":{"slug":"pro","name":"Pro"},
 "payment":{"amount":1499.00,"currency":"PHP","provider":"paymongo","reference":"INV-0012","paid_at":"<ISO 8601>"}}
```

- `payment.amount` is in **major units** (pesos, not centavos), 2 decimals. Convert cents before sending.
- `payment.currency` is 3 letters, uppercase. `PHP` when the app has one currency.
- `payment.provider` is `stripe`, `paymongo` or `manual` (staff approved a proof of payment).
- `payment.reference` is at most 120 characters.
- **No console skip:** a payment is real wherever it is settled. Demo sandboxes are still skipped.
- **Never let the hub fail the payment.** Send after the commit (the job does this), and wrap any lookup in the sender in `try` / `report()` (Work does).
- **Sent by:** People (`Ledger::settle`), Stock (admin approval of a payment), Work (Stripe webhook, card payment, staff marking an invoice paid), Pay (`OnboardCompany`, and plan changes approved in `PlatformController`).
- **Routed to:** `crm` (migration `2026_11_12_000100_route_subscription_paid_to_crm`).
- The hub spec does not list this event yet. This section is the contract.

### `demo.opened` (v1)

A live-demo visitor left their details and agreed to be contacted. There is no tenant yet.

```json
{"id":"<uuid>","event":"demo.opened","version":1,"occurred_at":"<ISO 8601>","app":"learn",
 "tenant":{"ref":null,"name":"Sample School","type":"school","email":null,"phone":null,"country":null,"website":null},
 "contact":{"first_name":"Ana","last_name":"Cruz","email":"ana@example.com","phone":null,"job_title":null},
 "demo":{"visits":1,"consented_at":"<ISO 8601>"}}
```

- `contact.email` and `demo.consented_at` are required by the CRM. Send only visitors who ticked the consent box.
- `demo.visits` is at least 1.
- Send once per lead. Later page views or beacons send nothing.
- Does not make an FBS ID.
- **Sent by:** People, Connect, Learn, Stock, Work and Pay (`DemoController`).
- **Routed to:** `crm` (migration `2026_11_10_000100_route_demo_opened_to_crm`).

### Planned, not built

`tenant.plan_changed`, `tenant.trial_ending`, `tenant.cancelled`, and tenant-level `customer.*` sync. Do not send them until they are added here and routed.

## 5. App keys and tenant types

| App | Key (`services.fbs_hub.app`) | `tenant.type` | Tenant model |
|---|---|---|---|
| FBS People | `people` | `company` | `Company` |
| FBS Pay | `pay` | `company` | `Company` |
| FBS Learn | `learn` | `school` | `School` |
| FBS Connect | `connect` | `business` | `Business` |
| FBS Work | `work` | `workspace` | `Workspace` (the account's first) |
| FBS Stock | `stock` | `store` | `Store` |

- The key is hard-coded in `config/services.php`, not read from the environment, so the URL is `{FBS_HUB_URL}/hooks/{key}`.
- The same keys are listed in the hub (`SuiteApp::KEYS`) and the CRM (`SignupIntake::APPS`). A new app needs all three.

## 6. Sender rules (every app)

Use the template in [`templates/hub/`](../templates/hub/). It already does all of this:

1. **Off without settings.** No `FBS_HUB_URL` or no `FBS_HUB_SECRET` means nothing is sent.
2. **After commit.** `SendSuiteHubEvent::dispatch(...)->afterCommit()`, so a rolled-back sign-up never reaches the CRM.
3. **Quiet mode.** `SuiteHub::quietly(fn () => ...)` sends nothing inside the callback. It uses hidden `Context`, so it is per request and per job (Octane-safe). Demo seeding and sandboxes run inside it.
4. **Contact read in the job.** The event carries a reference (for example `['company_id' => 42]`). The job reads the contact when it runs, once the records exist. Null (tenant gone, or a sandbox) skips the event.
5. **No redirects followed.** `Http::withoutRedirecting()`. A redirect (an `http://` address, a wrong host) would turn the POST into a GET and look like a success. A redirect is an error with a clear message.
6. **Only the hub's own success counts.** A 2xx answer with JSON `success: true`. Anything else throws, so the queue retries.
7. **Retries.** `$tries = 6`, `$timeout = 20`, `backoff() = [60, 300, 1800, 7200, 43200]` (1 min, 5 min, 30 min, 2 h, 12 h).
8. **The queue can hold a job for 12 hours.** Redis runs with `appendonly yes`, and the queue's `retry_after` is longer than the job timeout.
9. **Never break the user's action.** The hub is a side effect. A sign-up or a payment succeeds even if the hub is down.
10. **Say why it failed.** A refusal's error carries the hub's `message` (the template does this).
11. **Answer the hub's check.** Copy `SuiteHubCheckController` from the template and route it outside the `api` / `web` group, with a throttle, because the signature is the credential (no session, key or CSRF):

    ```php
    // routes/api.php (or routes/web.php as 'api/fbs-hub/check' with ->withoutMiddleware('web'))
    Route::post('fbs-hub/check', SuiteHubCheckController::class)->withoutMiddleware('api')->middleware('throttle:30,1')->name('api.fbs-hub.check');
    ```

Optional, from Learn: keep the last result on the tenant (`hub_sent_at`, `hub_error` in plain words) and give staff a **Send to CRM** button plus an Artisan command for tenants made before the hub existed.

Every sender (People, Pay, Learn, Connect, Work, Stock) now does both checks; new apps copy the template.

### Environment

| Key | Value |
|---|---|
| `FBS_HUB_URL` | The FBS Suites base address: `https://`, no path, no trailing slash. |
| `FBS_HUB_SECRET` | This app's webhook secret from FBS Suites › Platform › Apps. Shown once on create; Rotate makes a new one. |

Both are secrets in Coolify. Never commit them, log them or put them in an audit row.

**Set them from FBS Suites:** Platform › Coolify reads the server (address and an API token with `read`, `read:sensitive`, `write` and `deploy`, saved encrypted in the hub's database and tested before it is saved, rule 12). It reads each application's `APP_URL` from its env, because the apps route their domains in their own compose files and Coolify often knows none (`read:sensitive` is what lets the token see env values), and matches each app to its resource by that host, a Coolify domain, or the app key in the resource or project name. **Connect** writes `FBS_HUB_URL` and a new `FBS_HUB_SECRET` into that resource's env (Coolify first, so a refused write changes nothing), then sets the app's Base URL (its `APP_URL`) and MCP path in the hub. Keep each app's `APP_URL` its public `https://` address: the hub uses it as the app's Base URL. The apps load their env with `env_file: .env`, which Coolify writes when it deploys the resource, so **Restart** (asked first: Coolify's restart is not the rolling deploy, a short downtime) applies the values, and the page runs Test connection until the app is back.

## 7. Leg 2: hub → destinations

- `Router::route()` makes one `app_deliveries` row per **active** destination whose `events` list has the event. It is idempotent (`firstOrCreate` on event + destination).
- `DeliverEvent` POSTs the stored payload to `{destination url}{path}` with `Authorization: Bearer <destination token>` and `X-FBS-Event-Id`.
- **Lead department.** A destination may name the department each app's leads are filed under (`app_destinations.departments`: a default plus per-app choices, `Destination::departmentFor($app)`). `DeliverEvent` sends it as `X-FBS-Department: <department key>`. With no choice it sends no header. The body is never changed: the department is the hub's routing, not the sender's data.
- **Test connection** (Platform › Destinations, `POST /platform/destinations/{id}/test`) is `GET {url}/api/v1/intake/ping` with the token. It audits `destination.tested` and keeps the departments the ping lists (`offered_departments`), which fill the department choices. The answer says where each app's leads go, and names any chosen department the CRM no longer lists.
- **2xx** = `sent`.
- **408, 429, 5xx, connection error** = retry on the same backoff `[60, 300, 1800, 7200, 43200]`, then `dead`.
- **Any other 4xx** = `failed` at once. Retrying won't fix it.
- Staff replay any event from Platform › Webhooks. An event that was never routed (no deliveries, not `ignored`) is routed on replay.
- Destination tokens use the `encrypted` cast, are shown once, and never appear in JSON, logs or audit rows.

## 8. Leg 3: the CRM intake

- `POST /api/v1/intake/signups` (and `GET /api/v1/intake/ping`).
- `ping` answers `{"success":true,"message":"pong","data":{"departments":[{"key":"WebSolutions","name":"Web Solutions"}]}}`. Those are the departments the hub may file leads under: active ones with a Leads board (`IntakeDepartments::offered()`).
- Department of a new lead: the `X-FBS-Department` header when it names one of those, else `INTAKE_DEAL_DEPARTMENT`, else none. Any other key is ignored. An open lead with no department takes the hub's department on its next event. `data.department` in the answer says which department was used.
- A lead with no client and no department shows on every department's Leads board, and Admin and Developer see every department's leads (`isUnassignedProspect` in the CRM's `resources/js/lib/prospect.ts`).
- Middleware: `auth:sanctum`, `token-only` (a browser session is refused), `active`, `abilities:intake.signups`, `throttle:120,1`.
- The token is made in CRM › Developer › Integrations, stored hashed and shown once. Paste it into the hub's `crm` destination.
- Validation (`SignupIntakeRequest`): `version` must be 1; `event` must be in `SignupIntake::EVENTS`; `app` must be in `SignupIntake::APPS`; `tenant.type` is `company|school|business|workspace|store`; `signup.method` is `web|google|staff`; `payment.provider` is `stripe|paymongo|manual`.
- Idempotent on the event id (`deals.source_event_id`, unique).
- Matching: a contact with the same email (then its company); else a company with the same email or website domain (free-mail domains never match); else a new lead. One open deal per company and app, so a resend adds nothing twice.

## 9. Adding a new event

1. Name it `noun.verb_past`, version 1. Write its payload in section 4 of this file first.
2. **Sender:** add a method to the app's `SuiteHub` that calls `self::send()`. Decide the console and demo skips. Add a feature test that fakes `Http` and asserts the URL, headers and body.
3. **Receiver:** add it to the CRM's `SignupIntake::EVENTS` and its fields to `SignupIntakeRequest`, with a test.
4. **Route:** add it to the destination's `events` with an add-only, idempotent data migration in the hub (like `2026_11_12_000100_route_subscription_paid_to_crm`), with a test. Deploy the hub and the CRM **before** any app sends it.
5. Update this file and, if needed, [`templates/hub/`](../templates/hub/).

`hub.check` and `hub.ping` are connection checks, not events: the hub never stores or routes them, and a sender never sends them from its own code.

## 10. FBS ID (single sign-on)

- FBS Suites is the OpenID Connect provider for every FBS app (Laravel Passport, `App\FbsId\*`). Setup: `fbs-suites-mcp/docs/fbs-id-setup.md`.
- Google and Microsoft sign-in go through it. Staff need two-step login or a passkey.
- `tenant.signed_up` gives the sign-up's contact an FBS ID.
- The apps' side ("Sign in with FBS", `/api/fbs/*`) is Phase 2 and not built in any app yet. See [`structure/tenancy-and-auth.md`](../structure/tenancy-and-auth.md).

## 11. AI keys for the MCP gateway

FBS Suites is also the MCP gateway: Claude (or another MCP client) calls `POST /mcp` with one `fbsm_` key, and the gateway calls each FBS app's own MCP endpoint with a key the person pasted on **App links**. Only FBS staff sign in to FBS Suites, so App links hold staff keys; customer keys stay for organizations that still have FBS Suites logins. Spec: `fbs-suites-mcp/docs/superpowers/specs/2026-11-05-mcp-fbs-suites-design.md` (Per-app contract); plan for Pay, People and Connect: `fbs-suites-mcp/docs/superpowers/plans/2026-11-07-staff-ai-keys-pay-people-connect.md`.

**Every app's MCP endpoint:**

- Answers `initialize`, `tools/list` and `tools/call` (JSON-RPC 2.0, plain JSON, no sessions) with a bearer key, never a browser session.
- Always lists and answers `whoami`: `{kind: 'tenant', tenant_ref: <tenant id>, tenant_name, user_name}` for a customer key, `{kind: 'staff', tenant_ref: null, …}` for a staff key. The gateway refuses a staff key pasted by a customer and the other way round.
- Marks each tool with `annotations.readOnlyHint` (a tool without `true` is a write).
- Refuses one call inside MCP (a JSON-RPC error in a 200: -32001 not allowed, -32602 bad arguments). An HTTP 401 or 403 makes the gateway mark the link failed, so keep those for a key that no longer works; a plan without AI access answers inside MCP too (empty tool list, -32001 on calls).
- Writes every tool call to the app's own audit trail (rule 31).

**A staff key:**

- Is the staff member's own platform account's key, made on a page in the app's platform console (and `php artisan mcp:staff-key {email} --days=` for developers): read only, abilities `mcp.read` + `mcp.staff` (People: its own staff token), always expires (1, 7 or 30 days), shown once, listed with last used, revocable.
- Offers `list_tenants` (no arguments needed; `search`, `page`), and every other tool takes `tenant` (the account id, sent as a string; the gateway adds it to each tool's schema).
- Runs each call inside that tenant under the app's own staff rule, read tools only, and audits it in that tenant.

| App | MCP path | Customer key | Staff key | Staff rule |
|---|---|---|---|---|
| Work | `/api/mcp` | Settings › API keys | Platform › View as a user › Staff AI keys | `tenant` + `reason`, as View as |
| Stock | `/api/mcp` | none (staff only) | Account Settings › Staff AI keys | `tenant` + `reason` |
| People | `/mcp` | Me › Settings › Connected assistants | Console › My settings › AI keys for FBS Suites (roles that may enter companies) | `tenant` + `reason`, as Console › Tenants › Enter; never a Test Drive sandbox |
| Pay | `/api/v1/mcp` | Integrations › API keys ("AI assistants (MCP)") | Platform › Security › Staff AI keys (super admins, two-factor on) | a live support access to that company, covering the tool's module; masked |
| Connect | `/api/integrations/mcp` | Settings › API keys | Platform › Security › Staff AI keys (two-factor on) | as Platform › Enter (the business's Developer role); the key reaches only MCP |
| Learn | `/api/mcp` | Settings › School › API Keys | Platform › Settings › Staff AI keys | as Enter school |

The hub shows where to make the key in the Paste key dialog (`SuiteApp::KEY_PAGES` in `fbs-suites-mcp`): keep that list in step when a key page moves.
