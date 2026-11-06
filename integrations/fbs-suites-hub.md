# FBS Suites hub: the event contract

How an FBS app tells the rest of the suite that something happened (a sign-up, a payment, a demo visitor), and how the FBS CRM receives it.

- **Applies to:** every FBS app that sends events (People, Pay, Learn, Connect, Work, Stock), the hub (`fbs-suites-mcp`) and the receiver (`laravel-fbs-crm`).
- **Sources:**
  - `fbs-suites-mcp`: `docs/superpowers/specs/2026-11-05-mcp-fbs-suites-design.md` (Webhook hub), `app/Hub/{Signature,Router}.php`, `app/Http/Controllers/HookController.php`, `app/Jobs/DeliverEvent.php`, migrations `2026_11_10_000100_route_demo_opened_to_crm` and `2026_11_12_000100_route_subscription_paid_to_crm`.
  - The senders `app/Support/SuiteHub.php` and `app/Jobs/SendSuiteHubEvent.php` in People, Stock, Work, Learn, Pay and Connect.
  - `laravel-fbs-crm`: `routes/api.php`, `app/Http/Requests/Api/SignupIntakeRequest.php`, `app/Support/Intake/*`.
- **Last updated:** 2026-11-06

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
| 401 | `{"success":false,"message":"Unauthorized"}` | App disabled, app has no secret, bad timestamp or bad signature. |
| 404 | | No app with that key on Platform › Apps. |
| 422 | `{"success":false,"message":"Invalid event"}` | Body is not a JSON object, `id` is not a UUID or does not match the header, or `event` is missing or over 60 characters. |

The hub audits every refusal with the app and the reason only. It never audits the body.

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
- **Sent by:** People (`Ledger::settle`), Stock (admin approval of a payment), Work (Stripe webhook, card payment, staff marking an invoice paid).
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
- **Sent by:** People, Connect, Learn, Stock, Work. Pay has it on an unmerged branch.
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

Optional, from Learn: keep the last result on the tenant (`hub_sent_at`, `hub_error` in plain words) and give staff a **Send to CRM** button plus an Artisan command for tenants made before the hub existed.

**Known drift to fix:** Pay's `SendSuiteHubEvent` follows redirects and accepts any 2xx without checking `success: true`. Bring it in line with the template.

### Environment

| Key | Value |
|---|---|
| `FBS_HUB_URL` | The FBS Suites base address: `https://`, no path, no trailing slash. |
| `FBS_HUB_SECRET` | This app's webhook secret from FBS Suites › Platform › Apps. Shown once on create; Rotate makes a new one. |

Both are secrets in Coolify. Never commit them, log them or put them in an audit row.

## 7. Leg 2: hub → destinations

- `Router::route()` makes one `app_deliveries` row per **active** destination whose `events` list has the event. It is idempotent (`firstOrCreate` on event + destination).
- `DeliverEvent` POSTs the stored payload to `{destination url}{path}` with `Authorization: Bearer <destination token>` and `X-FBS-Event-Id`.
- **2xx** = `sent`.
- **408, 429, 5xx, connection error** = retry on the same backoff `[60, 300, 1800, 7200, 43200]`, then `dead`.
- **Any other 4xx** = `failed` at once. Retrying won't fix it.
- Staff replay any event from Platform › Webhooks. An event that was never routed (no deliveries, not `ignored`) is routed on replay.
- Destination tokens use the `encrypted` cast, are shown once, and never appear in JSON, logs or audit rows.

## 8. Leg 3: the CRM intake

- `POST /api/v1/intake/signups` (and `GET /api/v1/intake/ping`).
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

## 10. FBS ID (single sign-on)

- FBS Suites is the OpenID Connect provider for every FBS app (Laravel Passport, `App\FbsId\*`). Setup: `fbs-suites-mcp/docs/fbs-id-setup.md`.
- Google and Microsoft sign-in go through it. Staff need two-step login or a passkey.
- `tenant.signed_up` gives the sign-up's contact an FBS ID.
- The apps' side ("Sign in with FBS", `/api/fbs/*`) is Phase 2 and not built in any app yet. See [`structure/tenancy-and-auth.md`](../structure/tenancy-and-auth.md).
