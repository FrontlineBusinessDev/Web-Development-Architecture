# FBS Suites malware scanner: the upload contract

How an FBS app has an upload checked for viruses before it stores it (rule 10): the app streams the file to FBS Suites, which runs it through one shared ClamAV engine and answers with a verdict. FBS Suites keeps no file.

- **Applies to:** every FBS app that accepts files (People, Pay, Connect, Work, Stock, the CRM; Learn last, after its testing ends) and FBS Suites (`fbs-suites-mcp`), which runs the scanner.
- **Sources:**
  - `fbs-suites-mcp`: `docs/superpowers/specs/2026-11-07-central-malware-scanning-design.md` (decided 2026-11-08), plan `docs/superpowers/plans/2026-11-07-central-malware-scanning.md`, `app/Scanner/*`, `app/Http/Controllers/ScanController.php`, `ScanJobController.php`, `app/Jobs/RunAsyncScan.php`, `SendScanResult.php`.
  - The client: People's `App\Support\VirusScan` (the reference, rules 6 and 17) with the `fbs` driver added.
- **Last updated:** 2026-11-08

Copy the client from [`templates/scan/`](../templates/scan/). When the API changes, update this file and the templates in the same piece of work.

## 1. The flow

```text
FBS app (upload request)                         FBS Suites                          clamd (own network)
------------------------                         ----------                          -------------------
validate + authorize the upload
VirusScan::clean($file, 'payment-proof', $tenant)
  POST {FBS_SCAN_URL}/scan/{app}  ------------>  verify signature (before reading)
  raw bytes, signed with FBS_SCAN_SECRET         per-app limits
                                                 stream body -> INSTREAM  -------->  scan in RAM (tmpfs)
                                                 recompute sha256                    verdict
                                                 app_scans row (no file, no name)
  <------------------------------------------   { verdict: clean | infected | error }
clean    -> store the file as today
infected -> refuse (422), nothing stored
can't check -> the purpose's policy (section 6)
```

- The scan runs **inside the upload request, before the file is stored**. It is not a queued hub event: the upload needs the answer first.
- Files over 25 MB go the async way (section 4): stored in a quarantine place first, released only after a clean verdict.
- No `FBS_SCAN_URL` or `FBS_SCAN_SECRET` in the app's env = **scanning off**: uploads work exactly as before. Set both to turn it on.

## 2. Inline scan: `POST {FBS_SCAN_URL}/scan/{app}`

The body is the raw file (no multipart, no file name). Headers:

| Header | Value |
|---|---|
| `Content-Type` | `application/octet-stream` |
| `Content-Length` | required; over 25 MB → `413 use_async` before the body is read |
| `X-FBS-Scan-Id` | a UUID the app makes: the idempotency key, unique per app |
| `X-FBS-Timestamp` | Unix seconds when signed (300 s skew allowed, like hooks) |
| `X-FBS-Content-SHA256` | lowercase hex sha256 of the body |
| `X-FBS-Tenant` | the app's tenant id (never a name); empty or absent when none |
| `X-FBS-Purpose` | a short label from the app's fixed list (`payment-proof`, `employee-document`, `avatar`, ...): `^[a-z0-9-]{1,40}$`, never personal data |
| `X-FBS-Signature` | `sha256=` + hex HMAC-SHA256 of the canonical string below, keyed with the app's **scan secret** |

Canonical string (fields joined with `.`; an absent tenant is an empty field):

```text
timestamp . scan_id . sha256 . content_length . tenant . purpose
```

```php
'sha256='.hash_hmac('sha256', "{$ts}.{$scanId}.{$sha256}.{$length}.{$tenant}.{$purpose}", $secret)
```

The signature covers the content hash, not the body, so FBS Suites checks it **before** reading the file; while it streams the body to clamd it recomputes the sha256 and throws the verdict away on a mismatch.

**Test vectors** (use them in a unit test in every app and in FBS Suites). Secret `test-secret`, timestamp `1700000000`, scan id `00000000-0000-4000-8000-000000000001`, body `hello` (sha256 `2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824`, length 5):

| Tenant | Purpose | Signature |
|---|---|---|
| `42` | `payment-proof` | `sha256=6eadb9a7359366eb7683ca919ba7e442570afea2c2433716b3483e9de17cc21a` |
| (none) | `avatar` | `sha256=6f79116c81211652dad3b6eb2c0d12c711df5293155ea8286ef7bd9467b2d9b8` |

### Answers (`{ success, message, data }`)

| Status | `data` | The app does |
|---|---|---|
| 200 | `{ scan_id, verdict: "clean", signature: null, reason: null, engine, signatures_version, signatures_at, duration_ms }` | store the file |
| 200 | same, `verdict: "infected"`, `signature: "Eicar-Test-Signature"` | refuse; never show the signature name to the person (staff only) |
| 200 | same, `verdict: "error"`, `reason: "encrypted" \| "too_complex" \| "too_large"` | refuse with the matching message (never treat as clean) |
| 400 | `{ reason: "hash_mismatch" \| "bad_headers" }` | the policy (a bug or a broken stream: report it) |
| 401 | `{ reason: "app_disabled" \| "no_secret" \| "bad_timestamp" \| "stale_timestamp" \| "bad_signature", fingerprint }` | the policy; report it (wrong `FBS_SCAN_SECRET` or clock) |
| 409 | the stored result, same shape as 200 | use it: the same `scan_id` was already scanned |
| 413 | `{ reason: "use_async", limit_bytes }` | async (section 4), or refuse as too large |
| 429 | `{ reason: "rate_limited" }` + `Retry-After` | the policy |
| 503 | `{ verdict: "error", reason: "scanner_unavailable" \| "timeout" }` + `Retry-After` | the policy |

Connection errors, timeouts and any other answer also mean "couldn't check": the policy applies.

**Client timeouts:** connect 2 s, total 30 s, no redirects followed. FBS Suites answers a scan within 25 s (clamd PING 1 s, scan 20 s).

## 3. Limits (per app, never per IP)

Every app on the VPS reaches FBS Suites from the same Docker gateway address, so limits are keyed by app.

| Limit | Value |
|---|---|
| Inline size | 25 MB |
| Async size | 100 MB; larger is refused by the app before upload |
| Inline scans | 120 a minute and 4 in progress at once per app; 8 at once for clamd overall |
| Async jobs | 30 a minute per app |

## 4. Async scan (25 MB to 100 MB)

1. The app stores the upload where no one can open it (a `quarantine/` prefix, or its file row with `scan_status = pending`).
2. It makes a presigned GET URL for the object (15 minutes) and sends `POST {FBS_SCAN_URL}/scan/{app}/jobs` with JSON, signed **exactly like a hub event** (`X-FBS-Event-Id` = the scan id, `X-FBS-Timestamp`, `X-FBS-Signature` = `sha256=` + HMAC of `timestamp + "." + raw body`, keyed with the scan secret):

   ```json
   { "scan_id": "uuid", "url": "https://<bucket>.<region>.digitaloceanspaces.com/...?X-Amz-Signature=...",
     "size": 52428800, "sha256": "...", "tenant": "42", "purpose": "submission", "callback": true }
   ```

   Answer `202 { scan_id, status: "queued" }` (a repeated `scan_id` answers its current state).
3. FBS Suites streams the object straight into clamd (nothing at rest). It refuses: anything but `https`; a host not on the app's allow-list (Platform › Apps, `scan_storage_hosts`); an address that resolves private, loopback or reserved; redirects; a `Content-Length` other than `size` or over 100 MB. The URL is never stored or logged.
4. The result comes back as a signed `POST {app base_url}/api/fbs-scan/result` (hub-style headers, keyed with the scan secret; retried after 60, 300 and 1800 s) with `{ scan_id, verdict, signature, reason, ... }`, and the app can also poll `GET {FBS_SCAN_URL}/scan/{app}/jobs/{scan_id}` (headers `X-FBS-Timestamp` and `X-FBS-Signature` = `sha256=` + HMAC of `timestamp + "." + scan_id`; test vector with the values above: `sha256=93d08b67ae3d768f016eaa5959d8e1499556b68b93642ba2057567432b18022d`).
5. `clean` releases the file; `infected` deletes it and tells the uploader; `error` follows the purpose's policy.

## 5. Privacy

- **No file at rest in FBS Suites**, no file name anywhere (the API has no field for one), no presigned URL or secret in a database, log, audit row or answer.
- FBS Suites keeps one `app_scans` row per scan (app, tenant id, purpose, scan id, size, sha256, verdict, signature or reason, times) for 180 days, staff only. A threat also writes a `scan.infected` audit row.
- Apps never send personal data in headers: the tenant is an id, the purpose a fixed label.

## 6. Policies when the scanner can't answer

`infected` is always refused, under every policy. When the scanner is down, slow, refuses the request or answers `error` with a reason other than the three named ones, the purpose's policy decides:

| Policy | The upload | Use for |
|---|---|---|
| `refuse` (default) | refused; nothing stored | everything others open or download, and anything anonymous |
| `hold` | stored, marked pending, openable by no one else; a job re-scans pending files every 5 minutes until clean (usable) or infected (deleted, uploader told) | Learn student document submissions, People leave attachments |
| `open` | stored as usual, logged as unchecked | People kiosk and clock-in selfies only |

Each app lists its purposes and policies in `config/scan.php` (`policies`). A purpose not listed is `refuse`.

**What the person sees** (with the scan id's first 8 characters as the error code, rule 28):

- Infected: "This file can't be uploaded: the virus check found a threat in it. If you think this is wrong, contact support with code {code}."
- Couldn't check (refuse): "We couldn't check this file for viruses just now, so it wasn't saved. Try again in a minute."
- Hold: "Uploaded. We're checking it for viruses: it opens once that's done."
- Encrypted: "This file is password-protected, so it can't be checked for viruses. Upload it without a password."
- Too complex or too big: "This file is too large or too deeply packed to check for viruses (limit 100 MB)."

## 7. Environment (each app)

```dotenv
FBS_SCAN_URL=https://<FBS Suites address>    # no path, no trailing slash; the same for every app
FBS_SCAN_SECRET=<this app's scan secret>      # Platform > Apps > Rotate scan secret (shown once)
```

- Both are written for you by FBS Suites › Platform › Coolify › Connect, next to `FBS_HUB_URL` / `FBS_HUB_SECRET`. The scan secret is **not** the hub secret (least privilege).
- Use the public `https://` address, never the internal Docker network (the shared `coolify` network carries every app; HMAC signs but does not encrypt).
- Known gap: like `FBS_HUB_SECRET`, the scan secret lives in env, not a console keys page (rule 12). It moves when each app has its keys page.
- `SCAN_DRIVER=clamav` with `CLAMAV_HOST` still talks to a local clamd directly (local development only).

## 8. Adding scanning to an app

1. Copy `templates/scan/VirusScan.php` to `app/Support/`, `Scanned.php` to `app/Rules/`, `config-scan.php` to `config/scan.php` (set `app` and the `policies`), and, only if the app has async uploads, `SuiteScanResultController.php` with its route `POST /api/fbs-scan/result` (outside the session and CSRF, like `/api/fbs-hub/check`).
2. Call `VirusScan::clean($file, $purpose, $tenantRef)` in the app's shared store method **before** the file is stored (or put `new Scanned($purpose)` on the field's rules where there is no shared method). A `'held'` answer means: store it as pending.
3. Tests (Pest): `VirusScan::using(fn () => ['verdict' => 'clean'])`; infected is refused and nothing reaches the disk; each policy when the scanner is unavailable; scanning off with empty env; the test vectors above.
4. Platform › Apps in FBS Suites: Rotate scan secret, then Platform › Coolify › Connect (or paste both env values), restart the app.
