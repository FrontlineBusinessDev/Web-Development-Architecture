# FBS People to FBS Pay: the connector contract

How FBS People (the HRIS) hands people, time and money items to FBS Pay (payroll), and how pay comes back. FBS People keeps the people; FBS Pay makes and keeps the payroll. Rules 12, 18, 21, 23, 25, 28, 32, 33, 46 and 55.

- **Applies to:** `laravel-fbs-people` and `laravel-fbs-pay` only. No other app talks to this connector.
- **Sources:**
  - People: `tasks/plan-fbs-pay.md` (phases and decisions), `tasks/todo.md` ("FBS Pay"), `app/FbsPay/*` (`Client`, `ConnectionCode`, `EmployeeRow`, `CutoffPackage`, `MoneyItems`, `Watch`), `app/Jobs/{PushToFbsPay,SendCutoff,PullPayslips}.php`, `app/Http/Controllers/FbsPayWebhookController.php`, `routes/fbs-pay.php`, test `tests/Feature/MultiCompany/FbsPayConnectorTest.php`.
  - Pay: `docs/PROGRESS.md` ("FBS People connector"), `app/Connector/*` (`People`, `SyncEmployees`, `SyncCutoff`, `SyncAdjustments`), `app/Http/Controllers/Connector/*`, the `connector` group in `routes/api.php`, test `tests/Feature/FbsPeopleConnectorTest.php`.
- **Last updated:** 2026-11-10 (phases 1 to 6 built; checked end to end with both apps on one machine)

## 1. Who owns what (owner, Nov 10)

| | FBS People | FBS Pay |
| --- | --- | --- |
| People, their pay record, branch, department | owns; sends | keeps a copy, read-only ("Managed in FBS People") |
| Time: punches, overtime, leave | owns; sends when a cutoff locks | takes it; its own time entry is closed while connected |
| Claims, HMO dependent premiums, leave conversions | approves; sends | pays as payroll adjustments |
| Payroll runs, payslips, statutory, tax | never makes them | owns everything |
| Payslip detail | shows an overview (gross, deductions, net) and links to FBS Pay | the full payslip |

- People never makes a pay run. Pay changes nothing outside its connector for People.
- People keeps showing the overview figures it already had (pay records, overtime estimates). Amounts stay behind its Show pay button (rule 18).
- An employee opens a payslip in FBS Pay with an FBS Pay login. HR invites them there; there is no FBS sign-in across the products yet.

## 2. Connecting

1. In FBS Pay, Integrations › Connected Services › FBS People › Connect makes a **connection code**: `fbspay1.` + base64url JSON `{"u": "<Pay address>", "k": "<connector key>"}`.
2. In People, Company › Integrations › FBS Pay takes the one pasted code. People checks it (`GET /api/v1/connector/company`). It takes Pay's only payroll group, or asks which when Pay has several: a 422 carrying `payroll_groups`, also under `data` for the browser's API client.
3. People links its own webhook (`PUT /api/v1/connector/link` with `webhook_url` and `webhook_secret`). Nothing is pasted back.
4. People sends everyone at once.

- **The connector key** belongs to a system user "FBS People" per Pay company. It holds only the `connector.sync` capability and the abilities `people.write`, `time.write` and `adjustments.write`, which no other key type can hold. It works on every Pay plan (People's plan gates the connector). A new code retires the old key.
- People keeps the key and webhook secret encrypted (rule 12) and shows only the key's last 4 characters.
- One connection per People company, or per entity when entities run their own payroll. Pay pays in pesos only.
- People's switch `FBS_PAY_CONNECTOR` (`services.fbs_pay.connector`) keeps the connector off until set.

## 3. Calls (People to Pay)

All under `/api/v1/connector`, bearer connector key. Every write sends an `Idempotency-Key`; Pay keeps the reply, so a retry answers the same (rule 33). 60 calls a minute per connection.

| Call | What | Answer |
| --- | --- | --- |
| `GET company` | Pay's company name, payroll groups, branches, time-source switch | `data` |
| `PUT link` | People's webhook for `payroll.finalized` | `Linked.` |
| `PUT employees` | Up to 500 rows, upserted by `external_id` (`people:{company}:{employee}`), effective-dated pay | per row: `accepted`, `unchanged`, `refused` with a reason |
| `PUT cutoffs` | One locked cutoff: `period`, each person's `days` and `summary`, `overtime`, `leave` | `data` counts; 422 `errors.package` lists every problem; 409 when the period is held |
| `PUT adjustments` | Claims (`claim:{id}`), HMO premiums (`hmo:{Y-m}:{enrollment}`) as `amount`; leave conversions (`leave-conversion:{year}:{employee}:{type}`) as `days` | per row, like employees |
| `DELETE adjustments/{ref}` | Withdraw an item | 409 once a run paid it |
| `GET payroll?since=Y-m-d` | Runs finalized since then (default 31 days) | `data` |
| `GET payroll/{period}` | A finalized run: per line `id`, `external_id`, `gross_pay`, `total_deductions`, `net_pay`, `url`, `paid_refs` | 404 unless finalized |

- **Cutoffs:** a whole package or nothing. Pay refuses it when someone is unknown, a date is outside the period, it has no overtime rate for a day, or the days do not add up to People's `summary` (late, undertime, absences, overtime minutes). Sending the period again replaces it. A run approved or finalized over those days holds it (409), but only a run of those people's own payroll groups or a run with no group.
- **Before the lock**, People refuses to lock a connected cutoff while anyone has a day with one punch, naming them, so no half day is paid as worked.
- **Money:** Pay prices leave-conversion days once at the person's own daily rate (yearly pay over 261 days). A later raise does not reprice them. An item a run has paid is never changed.
- **Pay amounts** are strings with 2 decimals (`"12150.00"`) in major units (rule 21).

## 4. Pay back (Pay to People)

- Pay signs its webhooks like the hub: `X-FBS-Signature: t=…,v1=HMAC-SHA256(secret, "t.body")`, plus `X-FBS-Event` and `X-FBS-Delivery`. People refuses a signature more than 5 minutes off and keeps each delivery id, so a retry is a no-op.
- On `payroll.finalized` (`data.id` is the run), People pulls `GET payroll/{period}` and keeps an overview per line. It marks the claims in `paid_refs` paid, with the run's payroll number as the reference. It sets final pay to Released for whoever's last day the run covers, and tells each person their payslip is ready (rule 25).
- People keeps a payslip link only when it is on the connection's own Pay address.
- Pay's payslip PDF is not sent: it opens with a birth-date password, and People sends no birth dates.

## 5. Watching it (rule 28)

- Every call is logged in People (`app_fbs_pay_syncs`) with counts only, never personal data. The last 50 show in Company › Integrations › FBS Pay, with "N of M people are in FBS Pay", who is not there yet, and Send again.
- A 401 marks the connection broken and tells the owners once. Console › System counts broken links across companies. `/health` does not: one company's link is not the platform failing.
- The nightly `fbs-pay:push` sends what did not go and pulls any finalized run whose webhook was missed.

## 6. Changing the contract

- Change both sides in the same piece of work. Pay's `FbsPeopleConnectorTest` pins the keys of `GET payroll/{period}`; People's `FbsPayConnectorTest` fakes the same shape. A change to one fails the other's test until both agree.
- Keep Pay's side inside its connector (`app/Connector`, `Http/Controllers/Connector`, the `connector` routes). Nothing else in Pay changes for People (owner, Nov 10).
- Update this file in the same work.
