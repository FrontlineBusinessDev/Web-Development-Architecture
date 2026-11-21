# The demo hop: from one FBS app's demo to another's (contract `fbs-demo-pass.v1`, app side)

A visitor in app A's demo opens the launcher, clicks **Try the demo** on app B, and lands in B's demo already known: name, email and company filled in, B's own consent asked as one tick, and a new FBS CRM lead for B.

- **Applies to:** FBS People, FBS Pay, FBS Learn, FBS Connect, FBS Work and FBS Stock, each as source (A) and as target (B).
- **Plan:** `fbs-suites-mcp` `docs/superpowers/plans/2026-11-21-demo-hop-between-apps.md`, section 7 (fixed 2026-11-21). **Hub contract:** `fbs-suites-mcp` `docs/contracts/fbs-demo-pass.v1.md` (the hub's request, answers and refusal reasons).
- **Owner ask:** 2026-11-21. Choices the session made are marked there; the owner may change them.
- **Rules:** 7 (each app writes only its own records), 14 (the demo's one real call), 18 (no personal data through the hub), 20 (the visitor clicks; nothing on its own), 31 (audit), 33 (safe twice: one-use pass, `jti` seen once), 66 (this doc in the same work).
- **Launcher:** [fbs-app-launcher.md](fbs-app-launcher.md), "In a demo".

## The hop in short

1. A's launcher button posts `POST /demo/hop {app: B}` to **A's server**.
2. A reads the visitor from its own verified lead, keeps them under a one-use `pass` for 300 s, and asks the hub for a signed pass (`POST {FBS_HUB_URL}/api/fbs-hub/demo-pass`, body `{app, pass, target}`: no name, no email).
3. The hub answers a 5-minute RS256 token signed with FBS ID's key (`iss`, `typ: "fbs-demo-pass"`, `aud` = B, `src` = A, `src_url` = A's Base URL, `pass`, `jti`, `iat`, `exp`) and `url` = B's Base URL + `/demo/hop`.
4. The browser goes to `{url}?fbs_demo_pass={token}`. B verifies the token with FBS ID's JWKS and fetches the visitor **from A, server to server**, once.
5. B opens its demo start prefilled as a Continue card, the visitor ticks B's consent, and B sends its own `demo.opened` with `demo.from_app = A`.

## 1. Source app: `POST /demo/hop`

- Web route, CSRF, throttled 10 a minute per visitor. Body `{app}`: one of `people, pay, learn, connect, work, stock`, not this app.
- Reads the visitor **only from its own verified demo lead** (its existing lead cookie or session), never from the request body. No verified lead with consent: 409 `{success: false, message: "no_lead"}`.
- Makes `pass` = 40 random url-safe characters and keeps `demo-pass:{pass}` in its cache for 300 s as `{target, first_name, last_name, email, phone, company, verified_at, consented_at}`. `company` is its own company, school or store name field; `phone` is null when it has none.
- Asks the hub, signed exactly like a hook to the hub ([fbs-suites-hub.md](fbs-suites-hub.md) section 2: `X-FBS-Signature: sha256=` HMAC of `timestamp.body` with `FBS_HUB_SECRET`, `X-FBS-Timestamp`, `X-FBS-Event-Id`). Body `{app, pass, target}` only; any other field is refused 422 `personal_data`.
- Answers `{success: true, data: {url}}` with `url` = the hub's `url` + `?fbs_demo_pass=` + the hub's `token`. The launcher navigates there in the same tab.
- This is the Test Drive's one real call (rule 14): a sample-only demo still sends it to the app's own server.

## 2. Hub: `POST /api/fbs-hub/demo-pass`

Built in the hub (`DemoPassController`). Target must be an active app with a Base URL, not the source; 60 a minute per app; audited `demo_pass.issued` `{src, target}` only. Answers `{success: true, message: "", data: {token, url, expires_at}}`. Refusals carry `data.reason` (`bad_signature`, `stale_timestamp`, `personal_data`, `same_app`, `unknown_target`, ...): the full list is in the hub contract. On any refusal the source answers its visitor with its normal message and opens nothing.

## 3. Source app: `POST /api/fbs/demo-pass/redeem`

- No session, throttled. `Authorization: Bearer {token}`.
- Verifies the token with FBS ID's JWKS: signature, `iss`, `typ`, `exp`, and `src` = this app.
- Takes `demo-pass:{pass}` and deletes it at once (one use), then checks `aud` = the stored `target`.
- Answers `{success: true, data: {first_name, last_name, email, phone, company, verified_at, consented_at}}`. Anything else: 404 `{success: false}`.
- Logged without the person: `demo_pass.redeemed` with the target only.

## 4. Target app: `GET /demo/hop?fbs_demo_pass={token}`

- Registered before any `/demo/{path}` catch-all.
- Verifies the token: JWKS, `iss`, `typ`, `exp`, `aud` = this app. Refuses a `jti` already seen (cache 600 s).
- Calls `POST {src_url}/api/fbs/demo-pass/redeem` (5 s timeout, no redirects) and keeps the visitor in the session as the pending hop.
- Opens its own demo start **prefilled as a Continue card**: name, email and company editable; its own consent tick, unticked; its own role, school, industry or plan pickers as today.
- Any failure (bad, expired or reused token, source unreachable, FBS ID not set up here) goes to the normal demo start, empty. Never an error page.

## 5. Target app: finishing the hop

- On submit with its own consent ticked: if the pending hop's `verified_at` is within 30 days and the email was not changed, the email counts as proved (no code). Else the app's normal code step.
- Saves its own lead row as today and sends `demo.opened` as today, with one more optional field `demo.from_app` = the source key ([fbs-suites-hub.md](fbs-suites-hub.md) section 4). Its own dedupe stays as it is.

## 6. Launcher in every demo

- Every other FBS app shows **Try the demo** (first button), whatever the sample says it owns. **Subscribe and pay** stays where the app sells itself.
- Hidden when FBS ID is not set up in this app (it cannot verify a pass back).
- Production **Open** links are not shown in a demo.

## Verifying the token

- Keys: FBS ID's JWKS (`{FBS ID}/oauth/jwks`), the `kid` in the token header; the same keys as id_tokens and `/api/fbs/token` assertions, so an app reuses its existing JWKS client.
- `iss` = FBS ID's issuer (its address, no trailing slash). `aud` is a single string (the app key); accept a one-item list too.
- Allow a small clock leeway (at most 60 s) on `exp` and `iat`.

## FBS CRM

`SignupIntakeRequest` accepts the optional `demo.from_app` (in `SignupIntake::APPS`, not `app`); the deal for that app gets the note "Came from the FBS {App} demo". One lead per email per app, as before.
