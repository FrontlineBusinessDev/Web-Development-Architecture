# Connector template: shared records both ways

Every FBS app copies this once, so a change to a shared record in any connected app reaches every other connected app of the same org (owner 2026-11-18: "any app edits"; plan `mcp-fbs-suites/docs/superpowers/plans/2026-11-18-every-app-two-way-sync.md`; contract `integrations/contracts/fbs-record.v1.md`). Rules 7, 17, 18, 23, 31, 33, 55, 66.

The files here are FBS People's, the `person` center (`laravel-fbs-people`, built and tested 2026-11-18). FBS Pay runs the same design as a non-center app (`laravel-fbs-pay` `app/FbsSync`, `app/Jobs/PushFbsChanges.php`); its tests are here as `PayReceiveChangesTest.php` and `PaySendChangesTest.php`.

## What to copy and change

| File | Change when you copy |
|---|---|
| `app/FbsSync/PersonType.php` | Map the shared `person` fields onto your model (`find`, `fields`, `apply`, `externalId`). Match departments and positions by name; never create them. Add one `*Type` class for each other shared type you take. |
| `app/FbsSync/ApplyChanges.php` | `APP` = your app key, `TYPES` = the types you take. A non-center app drops the `forward_status` line: only the type's center passes changes on. |
| `app/FbsSync/ChangeLog.php` | Call `ChangeLog::saved()` from your model's `saved` hook. Skip demos, Test Drives and sandboxes (rule 14). |
| `app/Jobs/PushFbsChanges.php` | A non-center app sends a type to its center when the center is linked, else to every linked app that takes it (see Pay's copy). The center sends to every linked app except the one that made the change. |
| `app/Http/Controllers/FbsSync/ChangesController.php` | The token audience (`aud`) is your app key. Look the tenant up in your tenant model, real tenants only. |
| `app/Models/FbsChange.php`, `FbsVersion.php`, the migration | Use your tenant column and table prefix rule. The tenant column is never fillable. |

Wrap every save your other connectors make from another app's data in `ApplyChanges::receiving()`, or the change log sends it back (the loop guard).
