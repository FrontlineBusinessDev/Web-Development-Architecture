# FBS app-to-app connectors: the shared convention

How one FBS app hands records to another (FBS People to FBS Pay, FBS Learn to FBS People, and the next ones). Rules 7, 12, 14, 18, 20, 28, 32, 33, 55, 63 and 68.

- **Applies to:** every direct link between two FBS apps that carries records. Built: People → Pay ([fbs-people-pay-connector.md](fbs-people-pay-connector.md)). Being built: Learn → People (contract `fbs-suites-mcp/docs/contracts/learn-people-staff.v1.json`).
- **Sources:** `fbs-suites-mcp/docs/superpowers/plans/2026-11-17-learn-pay-people-integration.md` (design rules, gaps G1, G14, G15), its audit `docs/superpowers/audits/2026-11-17-integration-audit.md`, and the People → Pay connector (People `app/FbsPay/*`, Pay `app/Connector/*`).
- **Last updated:** 2026-11-17

## 1. Connectors carry data, hub events carry ids

- Records (people, time, money items) go **directly** from the sending app to the receiving app's `connector/*` API. The hub never carries them.
- Hub events ([fbs-suites-hub.md](fbs-suites-hub.md)) between apps carry **ids, the tenant and a version only**, never personal data. A receiver that hears an event fetches the record through its connector. (The CRM intake events, which carry a sign-up's contact on purpose, are the exception and are listed in that file.)
- There are no server-side cross-app joins. The suite assistant joins answers itself, with the keys in section 5 (FBS Suites' `fbs__guide` tool explains them).

## 2. Connecting: one code

- The **receiver** makes the code; an admin of the sender pastes it. Format: `fbs{app}{version}.` + base64url JSON `{"u": <receiver base URL>, "k": <connector key>, "v": <version>}`, for example `fbspay1.…` (made by Pay) and `fbspeople1.…` (made by People). The app is the receiver's app key.
- `u` must be `https://` outside `local` / `testing`. The sender refuses anything else.
- A new code revokes the old key. The key is shown once in the code and never again.
- Both sides keep keys and webhook secrets encrypted, never in `.env`, and show only the last 4 characters (rule 12).
- One connection belongs to one tenant on each side (a company, or a company + entity; a school). The receiver names its tenant in `GET connector/company`, and the sender shows it before anything is sent.

## 3. Calls

- **Auth:** `Authorization: Bearer <connector key>`. The key belongs to a system user of the receiving tenant that holds only the connector's abilities, which no other key type can hold, so it can open nothing else.
- **Writes** carry an `Idempotency-Key` header (up to 120 characters). The receiver keeps the first answer per tenant and key; a repeat answers it again and writes nothing (`Idempotent-Replayed: true`). The same key with a different body is a 409. Rule 33.
- **Upserts** go by a stable external id the sender owns (`people:{company}:{employee}`, `learn:{school}:{kind}:{id}`), never by row id. Re-sending changes nothing.
- **Answers** use `{ success, message, data }`. A batch answers per row: `accepted`, `unchanged`, `refused` (with a reason), and `held` when the receiver waits for its HR to approve a first-time person. A per-row problem is never a 422 for the whole batch.
- **Limits:** 60 calls a minute per connection (429), batches of up to 500 rows, 10 s timeouts, three tries on 429 and 5xx with backoff, then the job's own retries.
- **Errors:** 401 = the key is bad or revoked: the sender marks the connection broken, tells its owners once and stops. 403 = switched off or not on the plan: nothing is written.
- **Webhooks back** (receiver to sender, such as `payroll.finalized`) are signed like the hub (HMAC-SHA256 over `timestamp.body`, at most 300 s skew), and the receiver keeps each delivery id, so a retry is a no-op. Rule 32.

## 4. Switches and failure

- **Global flag, off by default**, in the sender's config (`services.<receiver>.connector`, for example `FBS_PAY_CONNECTOR`, `services.fbs_learn.connector`). Nothing runs until it is set.
- **Connector pass for the FBS Sample tenants:** a tenant with `connector_pilot = true` (People `companies`, Learn `schools`) may use the connector while the global flag is off. Only the hub's Platform › Sample org switch sets the column (`fbs-suites-hub.md` section 2.2). It is never fillable, and no real tenant gets it. This lets the team test in production before the lawyer check opens the connectors to everyone.
- **Per-tenant switch** on the connection: connected, paused or disconnected. Pausing is the rollback: the data stays. A disconnect keeps the receiver's rows and unlocks the fields the sender owned.
- **Plan gate:** each side names the `Feature` (and add-on) that unlocks it. A locked connector stays readable and refuses writes (rule 63).
- **Never silently dropped:** every call is logged on the sender with counts only, never personal data (`app_fbs_pay_syncs` is the pattern); changes queue a send a minute later (unique per connection), and a nightly run sends what is left (rule 28).
- Demo, Test Drive and sandbox tenants never send (rule 14). Deleting in one app never deletes in the other.

## 5. Ids and data

- **The shared person id** is FBS People's `external_id`, `people:{company}:{employee}`. People is the HR system of record. Pay keys employees on it; Learn stores it on a staff record as `people_external_id`. There is no hub registry of people.
- `employee_number` is each app's own and unique per tenant only. Never join on it alone.
- Names go as parts (`first`, `middle`, `last`), never one box (rule 68).
- Each contract lists its fields. Free text (remarks, reasons) is not sent, or is sent as data with a length cap, so it cannot steer an AI prompt (rule 20).
- Government ids and bank details may cross a connector only where the contract says so (People → Pay, pending the lawyer check) and are masked in every AI and MCP answer (rule 18).

## 6. The contract file

- Each connector has a versioned JSON schema in `fbs-suites-mcp/docs/contracts/<sender>-<receiver>-<what>.v<N>.json` (for example `learn-people-staff.v1.json`). The code's version (`fbspeople1.`) matches it.
- Both sides copy it into their tests (`tests/Fixtures/contracts/`) and validate their requests or answers against it, so a change that breaks the other side fails a test first.
- A breaking change is a new version: a new file, a new code prefix, and the receiver answers both until every sender moved.
- Change both sides, the contract file and this doc in the same piece of work (rule 55). Each phase of the integration plan ends with an audit section against its checklist.
