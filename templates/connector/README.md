# Connector template: shared records both ways

Every FBS app copies this once, so a change to a shared record in any connected app reaches every other connected app of the same org (owner 2026-11-18: "any app edits"; plan `mcp-fbs-suites/docs/superpowers/plans/2026-11-18-every-app-two-way-sync.md`; contract `integrations/contracts/fbs-record.v1.md`). Rules 7, 17, 18, 23, 31, 33, 55, 66.

The files here are FBS People's, the `person` center (`laravel-fbs-people`, built and tested 2026-11-18). FBS Pay runs the same design as a non-center app (`laravel-fbs-pay` `app/FbsSync`, `app/Jobs/PushFbsChanges.php`); its tests are here as `PayReceiveChangesTest.php` and `PaySendChangesTest.php`.

## What to copy and change

| File | Change when you copy |
|---|---|
| `app/FbsSync/PersonType.php` | Map the shared `person` fields onto your model (`find`, `fields`, `apply`, `externalId`). Match departments and positions by name, else make one with that name (`OrgNames`, QA 2026-11-18). Add one `*Type` class for each other shared type you take. |
| `app/FbsSync/ApplyChanges.php` | `APP` = your app key, `TYPES` = the types you take. A non-center app drops the `forward_status` line: only the type's center passes changes on. |
| `app/FbsSync/ChangeLog.php` | Call `ChangeLog::saved()` from your model's `saved` hook. Skip demos, Test Drives and sandboxes (rule 14). |
| `app/Jobs/PushFbsChanges.php` | A non-center app sends a type to its center when the center is linked, else to every linked app that takes it (see Pay's copy). The center sends to every linked app except the one that made the change. |
| `app/FbsSync/SyncHealth.php` | Reported to the hub after each push (`POST /api/fbs-id/connector-report`, contract `fbs-connector-token.v1`): per peer what waits and since when, the last send, holds, 24-hour counts, the run's error. A non-center app drops the `forward_status` part. Also feeds your `{app}__sync_status` MCP tool. |
| `app/FbsSync/TallyAnswer.php` | Answers `POST connector/tally` (contract fbs-record.v1 "Tally"): a salted hash of each shared field per record another app knows; never a value. This copy is Learn's: list your own shared records under the id the others know them by. Add the route beside `changes`, scope `connector.person.read`. |
| `app/FbsSync/Tally.php` | Only the type's center (People for `person`) runs it nightly: asks each linked app, compares, keeps ids and field names that differ, reports the counts with the sync health. |
| `app/FbsSync/Holds.php` | The holds page's service: list held changes, suggest the likely person (same email or name), Link (keep the other app's id, `ApplyChanges::retry`) or Ignore, under a row lock, audited. This copy is People's (`FbsLink`); Pay keeps the id on `employees.external_id`, Learn on `people_external_id`. Add `ApplyChanges::retry` with it. |
| `app/FbsSync/OrgNames.php` | A department or position another app names that you lack is made with that name. Drop it if your app has no such lists. |
| `app/Http/Controllers/FbsSync/ChangesController.php` | The token audience (`aud`) is your app key. Look the tenant up in your tenant model, real tenants only. |
| `app/FbsSync/Connected.php` | Rule 72: the other apps that share a record, from your own records only (the app its external id names, your other connectors' links, the changes' `origin_app` / `received_from` / `targets` answered accepted, unchanged, superseded or locked). Keep `APPS` to the other apps, never your own key. Send it as `connected_apps` on the record's edit payload (HR / admins only) and have the screen ask in the app's confirm modal before a shared field is saved, the record archived or deleted, or a salary changed (Pay: `resources/js/ops/lib/fbsSync/connected.ts`; People: `resources/js/lib/fbs-connected.ts`, both with tests of the text). A bulk action asks once with a count (Pay: `POST api/employees/connected`). |
| `app/Models/FbsChange.php`, `FbsVersion.php`, the migration | Use your tenant column and table prefix rule. The tenant column is never fillable. |

Wrap every save your other connectors make from another app's data in `ApplyChanges::receiving()`, or the change log sends it back (the loop guard).

## Login apps (owner 2026-11-18 option 2)

Work (`laravel-fbs-work`), Stock (`laravel-fbs-stock`) and Connect (`laravel-fbs-connect`) keep people as sign-in accounts, so they add one thing to this template: an email another app changes never applies at once. It waits in `fbs_email_changes` for that app's admin (Work: a workspace admin, Settings › Users; Stock: an owner of the store, Settings; Connect: an admin, the Users page). Confirm applies and audits it; Reject keeps the email and marks the change `superseded`. A status from another app never switches off the last active admin or owner. Copy `app/FbsSync/*`, `PersonType::apply` and `FbsSync\EmailChangeController` from one of them.
