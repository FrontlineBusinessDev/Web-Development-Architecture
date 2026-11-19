# Shared records, v1

How FBS apps keep shared records the same (owner 2026-11-18: any connected app may edit a shared field, and the change reaches every other connected app). Plan: `mcp-fbs-suites/docs/superpowers/plans/2026-11-18-every-app-two-way-sync.md`. Template: `templates/connector/`. Tokens: `mcp-fbs-suites/docs/contracts/fbs-connector-token.v1.md`. Rules 7, 18, 21, 33, 46, 47.

Built for `person`, 2026-11-18: FBS People (the center), FBS Pay (with salary), FBS Learn (staff), FBS Work (workspace members), FBS Stock (store owners) and FBS Connect (staff accounts, never the client portal). The other types follow the plan.

## Send changes

`PUT {app base_url}/api/fbs/connector/changes`, `Authorization: Bearer <connector token>` (aud = the receiving app, `tenant` = its tenant, `scope` = `connector.{type}.write`, single-use `jti`).

```json
{"changes": [{
  "change_id": "01JD…", "type": "person", "external_id": "people:12:345",
  "origin": {"app": "people", "external_id": "people:12:345"},
  "changed_by": {"app": "pay", "first_name": "Pia", "last_name": "Lim"},
  "changed_at": "2026-11-18T06:12:30.123456Z",
  "fields": {"mobile": "0917…", "department": "Finance"},
  "match": {"email": "ana@acme.test", "first_name": "Ana", "last_name": "Cruz"}
}]}
```

- At most 500 changes. Only the fields that changed. Money as 2-decimal strings in major units.
- Answer `{success: true, message: "", data: {answers: {change_id: answer}}}`. The answer is one of:
  - `accepted`;
  - `unchanged`: that change id was taken before;
  - `superseded`: an equal or later change of each field is already here;
  - `held`: no such record here; it waits for a person to link it;
  - `refused`: a bad shape, or a type this app does not take;
  - `locked`: inside a finalized pay period, or a suggestion already decided in the app (`work_item`).
- Refusals of the whole call: 401 `bad_token` / `replayed`, 403 `scope`, 404 `no_tenant`, 422 `bad_request`.

## Rules every app follows

- **The later change wins, per field.** Each app keeps when each shared field of a record was last set (`changed_at`, `change_id`). A change is taken for a field only when it is later. A tie goes to the type's center.
- **Loop guard.** An app sends only changes its own people or jobs made, never one it took. Wrap every save another connector makes from another app's data as "received".
- **Centers.** People for `person` and `time`, Connect for `customer` and `money_item`, Stock for `product`, Work for `work_item`. The center passes a change it took on to every other linked app, under its own id, never back to the app that made it. A non-center app sends to the center when the org has it linked, else to every linked app that takes the type.
- **Ids.** Once the center has a record, its id is the shared id (`people:{company}:{employee}`). An app finds a record by that id, or by the id it sent the record under.
- **Auto-link (spec §0).** Every `person` change carries `match`: who the person is. A receiver that does not know the id links it to the one person it has with the same email and the same first and last name (case aside), and keeps that id. People keeps any app's id (`app_fbs_links`); the other apps link only People's ids (`people:…`). Two matches, or a different name, stay held.
- **Login emails (owner 2026-11-18 option 2).** In an app whose people are sign-in accounts (Work, Stock, Connect), an `email` another app changed is never applied at once: it waits for that app's admin (the change answers `held`). Confirm applies and audits it; Reject keeps the email and marks the change `superseded` there. Names and the other fields are taken as usual. A `status` from another app never switches off the last active admin or owner.
- **One approval (rule 73).** A change approved in the app that made it arrives approved: the receiver writes it as approved and never asks again. Only a change nobody approved yet (`approved` false, such as FBS Work's hours) gets its one approval in the receiver.
- **Never merged, never deleted.** A record an app lacks, with no clear match, is held for its people. A delete is sent as `status: inactive`.
- **Linked apps.** Ask `GET {FBS Suites}/api/fbs-id/connector-peers?tenant=` before each run. A change waits until every app it goes to has answered; the next run sends it again.

## Shared fields

| Type | Fields |
|---|---|
| `person` | `first_name`, `middle_name`, `last_name`, `email`, `mobile`, `employee_number`, `department`, `position`, `employment_type`, `date_hired`, `status` (`active` / `inactive`) |
| `person`, salary (People and Pay only) | `compensation:{Y-m-d}` => `{basic_pay, pay_type}`, one field per effective date, so each dated pay row wins on its own (rule 46). Never sent to any other app. A date on or before the person's last finalized FBS Pay period is `locked`: Pay checks its runs, People the payslips Pay sent it, and both refuse such a date when edited in the app too. |
| `time` (built 2026-11-18: People's approved leave) | one record per person per day, `people:{company}:time:{employee}:{date}`: `person` (People's id), `date`, `status` (`leave`, or `none` once cancelled), `leave_code`, `half`. People sends it to Learn (an approved staff leave "From FBS People") and Work (a day away, "On leave today"); never to Pay (cutoff connector). Rule 74 (2026-11-19): also for someone whose time comes from Learn when the leave was filed or changed in People (never a leave Learn's connector wrote), and `none` for a day of a leave Learn filed archives that leave in Learn. Also People → Learn for the people Learn times (2026-11-19): a day People itself changed (a punch made in People, or a time request), the same id, `person`, `date`, `attendance` (Learn's word: `Present` / `Late` / `Absent`; it marks the record for Learn only), `time_in` (`H:i`), `minutes_worked`; Learn writes that day's staff attendance and stamps it as taken by People, so its own connector never sends it back. Also from Work (built 2026-11-18): logged hours per person per day, `work:{workspace}:time:{user}:{date}`, `person`, `date`, `minutes_worked` (integer), `status` `worked` / `none`; after the first send only a changed `minutes_worked` / `status` goes, always with `person` and `date`; to People only, which answers `refused` (final) for someone whose time does not come from Work. People takes it only for people of a company whose "Time from FBS Work" start date is set (`work_time_from`; never someone Learn times; else `refused`, nothing written), as in / out punches `fbs_work`, read-only there; a locked cutoff is `locked`; People never forwards it to Pay (Work sends `pay_item` itself). Also People → Pay (built 2026-11-19): one day record per person, the same `people:{company}:time:{employee}:{date}` id, `person`, `date`, `minutes_worked` (always present: it marks a day record, against the leave-only record that goes to Learn and Work), `status` (`present` / `late` / `undertime` / `absent` / `leave` / `none`), `late_minutes`, `undertime_minutes`, `overtime_minutes` (approved in People only, rule 47), `leave_code`, `leave_type`, `leave_paid`, `half`, `time_in`, `time_out` (`H:i`), `work_type` (`onsite` / `wfh`); only for active, non-contingent people Pay pays and People times (not Learn or Work), last 35 days, today once it is over. Pay writes them into the cutoff connector's own rows (attendance per person and date, overtime `ot:{employee}:{date}`, one-day leaves the cutoff's leave replaces), so nothing counts twice; a day in an approved or finalized run is `locked`; Pay never sends `time`. |
| `pay_item` (built 2026-11-18: Work's hours) | one record per person per day, `work:{workspace}:hours:{user}:{date}`: `person`, `date`, `kind` `hours`, `hours` ("7.50"), `approved` (true only when someone other than the person approved it in the source; Work has no time approval, so false). Work sends it to Pay only; Pay files one hourly work log, pending in Approvals, for hourly-paid employees (else `refused`), updated while pending, `locked` once decided; 0 hours withdraws it. |
| `customer` (built 2026-11-18: Connect → Work, Stock) | Connect is the center. `connect:{business}:company:{id}`: `kind` (`company` / `contact`), `name` (a contact: `first_name` + `last_name`), `email`, `mobile`, `address`, `status`. Only customers **in use** go (a paying client or a won deal; never a lead); after the first send only the changed fields; lost or deleted = `status: inactive`. Work keeps them as its clients (a project names one); Stock as its customers, offered for a credit sale (a first change links to the one unlinked customer of the same name). A first change without a name is `held`. Stock never sends its own customers (owner: a store's walk-in buyers stay out of a business's CRM). |
| `product` (built 2026-11-18: Stock → Connect) | Stock is the center. `stock:{store}:product:{id}`: `sku` (barcode), `name`, `unit`, `price` (selling price), `status`. Stock levels never go (read through MCP), so a sale sends nothing; after the first send only the changed fields; a delete = `status: inactive`. Connect keeps them read-only in a Products list of their own, apart from its service catalog; a nameless first change is `held`. Learn takes none yet. |
| `work_item` (built 2026-11-18: Connect → Work, projects only) | Work is the center. A deal that reaches Won in Connect: `connect:{business}:deal:{id}` with `kind` `project`, `name`, `customer` (the `customer` id), `customer_name`, `status` `proposed`. Work only **suggests** it (rule 20): nothing is created until someone there creates the project (the normal form, prefilled) or dismisses it. While it waits, a later `name` renames it and `status: withdrawn` (the deal left Won, was archived or deleted) takes it off; a later `proposed` brings it back. Once decided in Work every further change is `locked`. `kind` `task` is `refused` for now; a nameless first change is `held`. |
| `money_item` (built 2026-11-18: Stock store days → Connect) | Connect is the center. Only `kind` `store_day` so far (owner 2026-11-18): one total per store per day, `stock:{store}:sales:{date}` with `date`, `store` (its name), `sales_count`, `sales_total` (paid sales), `credit_given` (credit sales), `credit_collected` (credit payments), `status`. Never a single sale or a customer's name. Sent again when that day changes; a change not yet sent is updated in place. Final on arrival (a sale already happened): Connect keeps it in a read-only Store sales list, never as an invoice and never to Xero. `kind` `customer_balance` (owner 2026-11-18): a client's credit balance at a store, `stock:{store}:balance:{customer}` with `customer` (the `customer` id Connect sent), `store`, `owed`, `paid`, `balance`, `due`, the whole state each time; only for customers that came from Connect, never a walk-in buyer; shown read-only on the client, never an invoice; a `customer` the receiver does not know (or of another tenant) is `held`. Store days count on the store's own day (Stock's `STORE_TIMEZONE`, Philippine time by default). Another `kind` is `refused`; an undated first store day is `held`. |

## Tally (nightly check, Task 6)

The type's center (People for `person`) checks every night that the linked apps hold the same shared fields, without any value crossing (rule 18).

`POST {peer base_url}/api/fbs/connector/tally`, a hub connector token with scope `connector.person.read` (same checks as `changes`: aud, tenant, single-use jti), body `{"type": "person", "salt": "<32 to 64 hex, new each run>"}`. 422 for another type or a bad salt, 403 for another scope.

```json
{"success": true, "message": "", "data": {"records": {"people:12:345": {"first_name": "9c1f…16 hex", "last_name": "…", "status": "…"}}, "truncated": false}}
```

- One entry per record another app knows (Learn: every staff member under `people_external_id`, else its own id; Pay: employees with an `external_id`; Work, Stock, Connect: their synced accounts), at most 5000.
- Per field it holds of `first_name`, `middle_name`, `last_name`, `email`, `mobile`, `employee_number`, `department`, `position`, `date_hired`, `status`: the first 16 hex of HMAC-SHA256 of the value with the salt, after lower case, trimming and single spaces; `status` is `active` or `inactive`; null is the empty string. `employment_type` is left out (each app words it its own way).
- The asker finds each id with its own `PersonType::find`, hashes its own fields the same way, and keeps only the ids and field names that differ, plus how many ids it could not find (People: `app_fbs_tallies`, `sync_status`). It reports the counts to the hub with its sync health (`connector-report` `peers.*.tally`), never the ids' values. Nothing is changed by a tally.

## Settings › FBS apps (built 2026-11-18: Work, Stock, Connect)

Each app has one admin page listing its linked apps (`connector-peers`; when the hub does not answer, the apps it sent to in the last 30 days), the types each way, the last answered send, changes waiting, the last failed send (kept 30 days in the app's cache, cleared by the next answered send) and the Waiting for you holds. Counts, times and type names only, never field values (rule 18).

AI hold decisions: every app with holds offers `propose_hold_decision` (a proposal only, key ability `mcp.propose`; an admin confirms in the app). Pay grants `mcp.propose` per fbs-id-token.v1 step 5 (2026-11-19).
