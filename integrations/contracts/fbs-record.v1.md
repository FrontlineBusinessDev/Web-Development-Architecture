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
  - `locked`: inside a finalized pay period.
- Refusals of the whole call: 401 `bad_token` / `replayed`, 403 `scope`, 404 `no_tenant`, 422 `bad_request`.

## Rules every app follows

- **The later change wins, per field.** Each app keeps when each shared field of a record was last set (`changed_at`, `change_id`). A change is taken for a field only when it is later. A tie goes to the type's center.
- **Loop guard.** An app sends only changes its own people or jobs made, never one it took. Wrap every save another connector makes from another app's data as "received".
- **Centers.** People for `person` and `time`, Connect for `customer` and `money_item`, Stock for `product`, Work for `work_item`. The center passes a change it took on to every other linked app, under its own id, never back to the app that made it. A non-center app sends to the center when the org has it linked, else to every linked app that takes the type.
- **Ids.** Once the center has a record, its id is the shared id (`people:{company}:{employee}`). An app finds a record by that id, or by the id it sent the record under.
- **Auto-link (spec §0).** Every `person` change carries `match`: who the person is. A receiver that does not know the id links it to the one person it has with the same email and the same first and last name (case aside), and keeps that id. People keeps any app's id (`app_fbs_links`); the other apps link only People's ids (`people:…`). Two matches, or a different name, stay held.
- **Login emails (owner 2026-11-18 option 2).** In an app whose people are sign-in accounts (Work, Stock, Connect), an `email` another app changed is never applied at once: it waits for that app's admin (the change answers `held`). Confirm applies and audits it; Reject keeps the email and marks the change `superseded` there. Names and the other fields are taken as usual. A `status` from another app never switches off the last active admin or owner.
- **Never merged, never deleted.** A record an app lacks, with no clear match, is held for its people. A delete is sent as `status: inactive`.
- **Linked apps.** Ask `GET {FBS Suites}/api/fbs-id/connector-peers?tenant=` before each run. A change waits until every app it goes to has answered; the next run sends it again.

## Shared fields

| Type | Fields |
|---|---|
| `person` | `first_name`, `middle_name`, `last_name`, `email`, `mobile`, `employee_number`, `department`, `position`, `employment_type`, `date_hired`, `status` (`active` / `inactive`) |
| `person`, salary (People and Pay only) | `compensation:{Y-m-d}` => `{basic_pay, pay_type}`, one field per effective date, so each dated pay row wins on its own (rule 46). Never sent to any other app. A date on or before the person's last finalized FBS Pay period is `locked`: Pay checks its runs, People the payslips Pay sent it, and both refuse such a date when edited in the app too. |
| `time` (built 2026-11-18: People's approved leave) | one record per person per day, `people:{company}:time:{employee}:{date}`: `person` (People's id), `date`, `status` (`leave`, or `none` once cancelled), `leave_code`, `half`. People sends it to Learn (an approved staff leave "From FBS People") and Work (a day away, "On leave today"); never to Pay (cutoff connector) and never for someone whose time comes from Learn. |
| `pay_item` (built 2026-11-18: Work's hours) | one record per person per day, `work:{workspace}:hours:{user}:{date}`: `person`, `date`, `kind` `hours`, `hours` ("7.50"), `approved` (true only when someone other than the person approved it in the source; Work has no time approval, so false). Work sends it to Pay only; Pay files one hourly work log, pending in Approvals, for hourly-paid employees (else `refused`), updated while pending, `locked` once decided; 0 hours withdraws it. |
| `customer`, `product`, `work_item`, `money_item` | as the plan's Global Constraints; not built yet |

## Tally (nightly check, Task 6)

The type's center (People for `person`) checks every night that the linked apps hold the same shared fields, without any value crossing (rule 18).

`POST {peer base_url}/api/fbs/connector/tally`, a hub connector token with scope `connector.person.read` (same checks as `changes`: aud, tenant, single-use jti), body `{"type": "person", "salt": "<32 to 64 hex, new each run>"}`. 422 for another type or a bad salt, 403 for another scope.

```json
{"success": true, "message": "", "data": {"records": {"people:12:345": {"first_name": "9c1f…16 hex", "last_name": "…", "status": "…"}}, "truncated": false}}
```

- One entry per record another app knows (Learn: every staff member under `people_external_id`, else its own id; Pay: employees with an `external_id`; Work, Stock, Connect: their synced accounts), at most 5000.
- Per field it holds of `first_name`, `middle_name`, `last_name`, `email`, `mobile`, `employee_number`, `department`, `position`, `date_hired`, `status`: the first 16 hex of HMAC-SHA256 of the value with the salt, after lower case, trimming and single spaces; `status` is `active` or `inactive`; null is the empty string. `employment_type` is left out (each app words it its own way).
- The asker finds each id with its own `PersonType::find`, hashes its own fields the same way, and keeps only the ids and field names that differ, plus how many ids it could not find (People: `app_fbs_tallies`, `sync_status`). It reports the counts to the hub with its sync health (`connector-report` `peers.*.tally`), never the ids' values. Nothing is changed by a tally.
