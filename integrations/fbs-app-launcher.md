# FBS app launcher (contract `fbs-app-launcher.v1`)

What every FBS app shows behind its top-left logo: the FBS apps the customer's organisation has (open them), and the other FBS apps, greyed, with what each does, how it would connect, and a way to subscribe.

- **Applies to:** every FBS app with a sidebar logo. Built in FBS People, FBS Pay, FBS Learn, FBS Work, FBS Stock and FBS Connect; the CRM follows.
- **Source:** FBS CRM (`laravel-fbs-crm`) only. Not FBS Connect, and not the FBS Suites hub (the hub keeps its own technical link data, `app_org_tenants` and `connector-peers`, which the launcher never reads).
- **Owner decision:** 2026-11-21.
- **Rules:** 3 (skeleton while it loads), 12 (the token is a key), 14 (Test Drive sample), 41 (floating layer), 60 (ask before Request), 63 (an app the customer lacks stays visible with the way to get it), 75 (pull before you plan).

## 1. Where the data lives (FBS CRM)

| Table | What | Screen |
|---|---|---|
| `fbs_apps` | The catalog: `key` (the hub app key, section 5 of [fbs-suites-hub.md](fbs-suites-hub.md)), `name`, `tagline`, `description`, `connects` (`{app_key: "how this app connects to that one"}`), `color`, `icon`, `app_url`, `self_serve` + `signup_url`, `sort`, `status` | Settings › FBS apps (`ServiceCatalog` module) |
| `company_fbs_apps` | Which client has which app: `fbs_app_id`, `tenant_ref` (the app's own tenant id), `company_id` (null while still a lead), `status`, `since`, who requested it | Client 360 › FBS apps (`ClientRecords` module) |

`status` is one of `requested`, `trial`, `subscribed`, `cancelled`. The CRM keeps it from the hub's intake events, with nothing extra for the apps to send:

| Hub event | Status |
|---|---|
| `tenant.signed_up` | `trial` (never takes `subscribed` back) |
| `subscription.paid` | `subscribed` |
| `tenant.cancelled` | `cancelled` |

A lead's rows move to its client when the lead is converted. Staff add or correct a row on Client 360.

## 2. API (the apps call the CRM)

Token-only (a browser session is refused). The token is made in FBS CRM › Developer › Integrations › FBS app launcher (abilities `launcher.read` and `launcher.request`). Each app keeps it as a secret (`services.fbs_crm.url` and `services.fbs_crm.token`, set in its own Keys & secrets screen; rule 12). Answers use `{ success, message, data }`.

### `GET /api/v1/launcher?app={key}&tenant={ref}`

```json
{
  "success": true,
  "message": "OK",
  "data": {
    "company": { "id": 12, "name": "Bayanihan Foods" },
    "apps": [
      { "key": "people", "name": "FBS People", "tagline": "Employee records, time, leave and HR.", "color": "#c22d40", "url": null, "status": "here", "connects_here": null, "self_serve": false, "signup_url": null },
      { "key": "pay", "name": "FBS Pay", "tagline": "Payroll, payslips and remittances.", "color": "#1d9e75", "url": "https://pay…", "status": "subscribed", "connects_here": "Takes people, time and leave from FBS People", "self_serve": false, "signup_url": null }
    ]
  }
}
```

- Every active catalog app, in `sort` order. `status` is `here` for the asking app, else the organisation's status, else `none`.
- The organisation is found from the asking tenant's own row; when that row belongs to a client, every row of that client counts.
- An unknown tenant gets the whole catalog with nothing subscribed, never a 404.
- `connects_here` is how that app connects to the asking one.

### `POST /api/v1/launcher/requests`

Body: `app`, `tenant`, `tenant_name`, `wants` (an active app key, not `app`), `name`, `email` (the person asking). The CRM makes a `requested` row and a lead (`source = fbs-{app}-launcher`; the new-lead alert tells staff). Asking again while the request is open returns the same row (200 instead of 201).

## 3. In each app

- **Server:** one small client (People: `App\FbsCrm\Launcher`) with a 5 s timeout and a 10-minute cache per tenant. On any error it returns an empty list: the shell never breaks. Off while the address or token is empty.
- **Endpoints:** `GET /shell/apps` (`{apps, can_subscribe}`) and `POST /shell/apps/request` (tenant admins only, audited, never from a sandbox or the Test Drive).
- **UI:**
    - Hovering the logo for about 300 ms opens the panel. A chevron beside the logo opens it on click or keyboard (nothing important only on hover), and the phone drawer's header has the same chevron. The logo itself still goes Home.
    - With no apps (not set up or unreachable), there is no chevron and no panel.
    - **Your FBS apps:** this app ("You're here") and the apps the organisation has, in the app's brand colour, with "Connected: …" and an Open link in a new tab.
    - **More FBS apps:** greyed, each with what it does, "Would connect: …", **Request** (asks first, then "FBS will contact you"), and **Subscribe and pay** when the app is `self_serve` (opens that app's own sign-up and billing in a new tab). People without admin rights see "Ask your admin to add it."
    - The Test Drive answers from a sample and sends nothing.
- **Reference:** FBS People `resources/js/components/kit3/shell/brand-launcher.tsx`, `app-launcher.tsx`, `app-launcher-api.ts`, `app-launcher-sample.ts`; `app/Http/Controllers/ShellAppsController.php`; tests `tests/Feature/MultiCompany/AppLauncherTest.php`. FBS Pay (ops kit, react-router) `resources/js/ops/components/layout/AppLauncher.tsx`, `lib/services/launcherService.ts`, Test Drive `demo/modules/launcher.ts`; `ShellAppsController` on `api/shell/apps` (`notifications.view|ess.view` to read, `settings.company.edit` to request); tests `tests/Feature/AppLauncherTest.php`, `tests/js/launcher.test.mts`. FBS Learn (kit B, no popover dependency) `resources/js/components/AppLauncher.tsx` (its own portal panel) in every portal sidebar; the CRM address and token on Platform › Settings › FBS app launcher (`App\FbsCrm\FbsCrmSettings`, a `SuiteHubSettings` subclass, and `SuiteHubCard` with `LAUNCHER_CARD`); a demo school is answered server-side from `LauncherSample` and sends nothing; `ShellAppsController` on `/shell/apps` (School Admin to request); tests `tests/Feature/Integrations/AppLauncherTest.php`. FBS Work (no popover dependency) `resources/js/components/app-launcher.tsx` (its own portal panel, lazy-loaded by `shell.tsx`, confirm through `lib/confirm.ts`); the CRM address and token (`FBS_CRM_URL`, `FBS_CRM_TOKEN`) beside FBS ID on Console › View as a user › FBS ID (`App\Support\FbsIdSettings`, `FbsIdSettingsController`); tenant = the workspace; a demo sandbox is answered server-side from `App\FbsCrm\LauncherSample` and sends nothing; `ShellAppsController` on `/shell/apps` (`routes/shell.php`, workspace admin to request); tests `tests/Feature/AppLauncherTest.php`. FBS Stock (JavaScript, no popover dependency) `resources/js/components/AppLauncher.jsx` (lazy-loaded by `layouts/AppLayout.jsx`; its own portal panel `AppLauncherPanel.jsx`, loaded on hover or focus with a skeleton); tenant = the store id, the ref Stock reports to the hub; the CRM address and token on Settings › FBS ID (`FbsIdSettingsController`, `PlatformSettings` `FBS_CRM_URL` / `FBS_CRM_TOKEN`); the FBS Sample store reads its apps but never requests, the browser demo answers a sample; `ShellAppsController` on `/shell/apps` (store owner or admin to request); tests `tests/Feature/AppLauncherTest.php`. FBS Connect (own kit, no popover dependency) `resources/js/components/layout/AppLauncher.tsx` (lazy-loads `AppLauncherPopover.tsx` and `AppLauncherPanel.tsx`, its own portal panel, confirm through `useConfirm()`) around `Sidebar.tsx`'s logo, off for the client portal; the CRM address and token on Platform › Integrations › FBS app launcher beside FBS ID (`App\FbsCrm\FbsCrmSettings`, a `SuiteHubSettings` subclass, and `SuiteHubPanel` with `LAUNCHER_PANEL`); tenant = the business id; the demo sandbox is answered server-side from `LauncherSample` and its request refused, nothing sent; `Api\ShellAppsController` on `api/shell/apps` (any staff to read, Admin / Developer to request; named-keys JSON `{apps, can_subscribe}`); tests `tests/Feature/MultiBusiness/AppLauncherTest.php`. FBS CRM `app/Support/Launcher/Launcher.php`, tests `tests/Feature/Launcher/LauncherTest.php`.

## 4. Adding an app

Add its key in the hub, the CRM intake (`SignupIntake::APPS`) and the app ([fbs-suites-hub.md](fbs-suites-hub.md) section 5), then add it in FBS CRM › Settings › FBS apps with how it connects to each app.
