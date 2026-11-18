# FBS app-to-app connectors: the shared convention

How one FBS app hands records to another (FBS People to FBS Pay, FBS Learn to FBS People, and the next ones). Rules 7, 12, 14, 18, 20, 28, 32, 33, 55, 63 and 68.

- **Applies to:** every direct link between two FBS apps that carries records. Built: People → Pay ([fbs-people-pay-connector.md](fbs-people-pay-connector.md)). Built: Learn → People staff (contract `fbs-suites-mcp/docs/contracts/learn-people-staff.v1.json`) and Learn → People time and leave (`learn-people-time.v1.json`, spec `docs/superpowers/specs/2026-11-18-phase-6-learn-time.md`).
- **Sources:** `fbs-suites-mcp/docs/superpowers/plans/2026-11-17-learn-pay-people-integration.md` (design rules, gaps G1, G14, G15), its audit `docs/superpowers/audits/2026-11-17-integration-audit.md`, and the People → Pay connector (People `app/FbsPay/*`, Pay `app/Connector/*`).
- **Last updated:** 2026-11-18 (time source, `locked`; section 7 planned: every app connected both ways)

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
- **Answers** use `{ success, message, data }`. A batch answers per row: `accepted`, `unchanged`, `refused` (with a reason), `held` when the receiver waits for its HR to approve a first-time person, and `locked` when the row falls in a period the receiver has closed (a locked payroll cutoff): nothing changes and the sender stops sending that row and shows it locked. A per-row problem is never a 422 for the whole batch.
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

- **One source of time per person.** When an app sends a person's time (attendance, leave) to another, the receiver has a per-connection switch with a start date ("time from FBS Learn"); while it is on, that person's time is read-only in the receiver and only the connector writes it. The receiver tells the sender in `GET connector/company` (`data.time`: `source`, `from`, `locked_through`), so the sender sends only from that day and shows days up to `locked_through` as locked. The receiver keeps the balances (leave), and the sender's codes map to the receiver's types on the connection (a leave-type map).

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

## 7. Every app connected, both ways (owner 2026-11-18; `person` built)

Spec `mcp-fbs-suites/docs/superpowers/specs/2026-11-18-multi-tenant-advanced-tools-webhooks.md` §0, §1.7, §1.8, §10 and §11, with the audit `docs/superpowers/audits/2026-11-18-multi-tenant-spec-audit.md`. Sections 1–6 stay the rules of the connectors built before it; new connector work follows this section.

**Built so far (2026-11-18):** the `person` type in all six tenant apps, on the shared-records contract `contracts/fbs-record.v1.md` (`PUT /api/fbs/connector/changes`, hub connector tokens, FBS People as the center), from `templates/connector/`:
- People (center), Pay (with salary `compensation:{date}`, both apps lock a date inside a finalized FBS Pay period), Learn (staff);
- Work, Stock and Connect, whose people are login accounts: names and status sync, but an email another app changes waits for that app's admin (owner option 2; see the template's README "Login apps").
- Every app names the apps a record is shared with before a change (rule 72).
What the rest of this section plans and is **not built yet**: the other types, the manifest and `outbound` pull, Settings › FBS apps pages, holds pages, and the health report and tally (plan Task 6). The built push is `PUT connector/changes`, not `PUT connector/{type}`. The CRM stays out: it is FBS's own, not a client's.

**One framework, every pair.** Every tenant app (People, Pay, Learn, Connect, Work, Stock) implements one framework once, from `templates/connector/`, instead of one connector per pair.

- **Record types,** one contract each:

  | Type | What it is |
  |---|---|
  | `person` | someone who works for the tenant |
  | `time` | attendance days, leave, hours worked |
  | `customer` | a client company or contact, or a store's credit customer |
  | `product` | an item or service sold |
  | `work_item` | a project or task, with billable hours |
  | `pay_item` | an earning or deduction for a person (hours, output, commission sales) |
  | `money_item` | an invoice or sale total for a customer |

- **Details belong to the app a record started in.** Other apps lock those fields ("From FBS {App}", 422) and keep their own app-only fields. Every row carries `origin: {app, external_id}`, and nothing is sent back to its origin, so there is no loop.
- **One center per type,** so no record arrives twice through two paths:

  | Type | Center |
  |---|---|
  | `person`, `time` | People |
  | `customer`, `money_item` | Connect |
  | `product` | Stock |

  Without the center app, the pair connects directly. When the org adds the center later, the flow moves to it by itself. Pay is never the center for people.
- **Manifest, push and pull:**
  - `GET connector/manifest`: the tenant, `gives` / `takes` per type with versions, switches, and the time source;
  - `PUT connector/{type}`: batch upsert, idempotent, answered per row;
  - `GET connector/{type}/outbound?since=`.

  The origin pushes on change and nightly. The other side pulls nightly and within a minute of the thin hub event `fbs.record.changed`.
- **No stored keys between apps.** An app asks the hub for a 5-minute RS256 connector token (`aud` = the other app, tenant, scope, `jti`, checked with FBS ID's JWKS; contract `fbs-connector-token.v1.md`). The hub signs it only for a linked pair with both switches on and "Real tenants" allowing it. The `fbs{app}1.` codes of section 2 stay only for tenants not on FBS Suites.
- **Zero setup:**
  - pairs link themselves;
  - staff flow automatically and HR only excludes;
  - Learn's Faculty or Non-Faculty comes from a seeded map;
  - clear matches auto-link (same origin id, or same verified email and same first and last name), and only true conflicts are held;
  - a person with an FBS ID gets a login while seats last;
  - a "time from" switch is on by itself when that app is the person's only time source.

  Every automatic step is shown on the app's Settings › FBS apps page and audited.
- **Money:** a `pay_item` or `money_item` arrives **approved** when someone other than the filer approved it in the source app (it names who). Otherwise it arrives **pending** in the receiver's approval engine, with "Approve all" (rules 46, 47). Once paid, the source locks the entry (`locked`).
- **Pages:** the hub's Integrations › Links grid (pairs that never moved data are hidden until they do) and one Settings › FBS apps page per app.
- **Health:** each app reports after every push (`POST /api/fbs-id/connector-report`, counts and times only); Platform › Connectors shows every pair for FBS staff, a direction with a change waiting 24 h emails staff once, and `fbs__connections` gives an org's AI the same, never values. Each app also answers `{app}__sync_status` and `{app}__holds` (Learn built 2026-11-18).
- **Holds page (built in People, Pay and Learn, 2026-11-18):** each app lists the changes another app sent for someone it could not match; an admin **Links** one to the person here (the app keeps that app's id and applies the change as if it just arrived, `ApplyChanges::retry`) or **Ignores** it (`status` `ignored`, nothing written). Both ask in the app's modal, lock the row and are audited. People adds no "Add as new" from a held change (a held change carries a few fields, not a whole person). AI: `{app}__holds` lists them by name and field names, `propose_hold_decision` (People, Learn) files a decision a person confirms.
- **Holds:** a weekly reminder for holds older than 30 days.
- **Versions:** a type links only on a version both apps speak.
- **Learn admission inquiries into Connect** are off per school until its admin turns them on (contact only, no student data).
- **The pair-by-pair list** of what flows each way is the spec's §10.2. Build order is by value: People ↔ Pay, Stock and Work → Pay, Connect ↔ Work, Connect ↔ Stock, Stock ↔ Learn, then the rest.
- **The lawyer / NPC check** still gates every new personal-data crossing for real tenants. FBS Sample tests everything first.

