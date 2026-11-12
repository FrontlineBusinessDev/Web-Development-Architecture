# System rules

The numbered rules every FBS web app follows when it plans, builds or reviews a screen or feature.

- **Applies to:** every FrontlineBusinessDev web app: FBS People, FBS CRM, FBS Connect, FBS Pay, FBS Learn, FCA-SMS, FBS Stock, FBS Work and FBS Suites (MCP), and every new project.
- **Sources:** `laravel-fbs-people/SYSTEM-RULES.md` (rules 1–65 and design profile D1–D12, the base of this file), `laravel-fbs-crm/SYSTEM-RULES.md` (rules 1–62, aligned to the CRM on 2026-11-02), `laravel-fbs-pay/docs/SYSTEM-RULES.md` (an older copy of People's), and the owner's decisions of 2026-11-06.
- **Last updated:** 2026-11-07

## How to read this file

- **Rules 1–66** say what to do in general terms. Each has **Rule**, **Why**, **Do / Don't**, **Reference (FBS People)** and **Check**.
- **Reference (FBS People)** lines show how the reference project (rule 6) applies the rule today, including what it has not built yet. Paths in them are relative to the `laravel-fbs-people` repo unless another repo is named. They are examples, not requirements: the Rule, Do and Don't lines are the requirement.
- **The design profile (D1–D12)** at the end is the shared design system: layout, sizes, controls and behaviour. It holds no brand colours. Token names and how each app sets its brand are in [../structure/design-system.md](../structure/design-system.md).
- Rules 7, 13, 14, 15 and 63 are written for multi-tenant subscription products. A single-organisation app (such as FBS CRM) skips them.
- A project never keeps its own edited copy of this file. Its `CLAUDE.md` links this repo, and project facts (paths, modules, what is built) stay in that project's own docs (rule 66).

People and AI assistants read this file before planning, building or reviewing. When a rule and a design disagree, the rule wins; if the rule is wrong, change it here first.

## Rules at a glance

Search for `## Rule N.` to jump to a rule.

1. Server-render only what search engines must see
2. Pick records with an async select
3. Skeleton on first load, a contained spinner for changes
4. No dead whitespace: every space and element has a purpose
5. Client-rendered pages work through an API
6. Start from the reference project
7. Multi-tenant subscription products
8. Fewest clicks for the most common tasks
9. Show data as infographics, not stock charts
10. Every file and backup lives in private object storage
11. Each tenant manages its files and backups
12. Keys and secrets are managed in the platform console, not `.env`
13. A landing page whose every claim is true, with legal pages from the CMS
14. A free public demo that never touches the database
15. One reusable billing engine, run from the platform console
16. New modules reuse the design; better ideas are proposed first
17. Plan and build for reuse
18. Privacy by design for personal data
19. Accessible to everyone (WCAG 2.2 AA)
20. AI agents act safely
21. Dates, time zones and money are exact
22. Definition of done
23. Safe deploys and migrations
24. Performance budgets
25. Notifications that help, not nag
26. Always a PWA with push, phone first
27. Import and export without fear
28. Errors are clear and the system is observable
29. Sign-in and account security
30. Roles and permissions
31. One audit log
32. Integrations: API, webhooks and AI clients
33. Background jobs are safe to retry
34. The URL holds the view
35. Dependencies are few, pinned and kept current
36. Write down what was built, shortcuts included
37. Nothing jumps: a stable layout
38. One typed client layer: axios and TanStack Query
39. File uploads show their progress
40. Imports and exports run with a progress toast that survives
41. Floating layers render in a portal and stay on screen
42. A Back to top button after 20% of the page
43. Edits never overwrite each other silently
44. Archive before delete
45. Forms never lose work
46. Changes that affect pay are effective-dated
47. One approval engine
48. Documents and PDFs come from one template engine
49. Static checks and tests gate every build, inside the Docker build
50. Each project keeps its files under one root folder
51. Every component is reusable, and no file is over 300 lines
52. Every page and tab has its own route
53. Filters take one record, several, or any
54. Bars and strips never show a scrollbar
55. Every plan cites these rules and breaks none
56. Long AI work stops at 93% of the usage limit
57. Clean up what a feature touches before adding the feature
58. Frontend first, then its backend
59. *(Retired 2026-11-06)* One UI from the Claude Design canvas
60. No browser dialogs: every question is the app's own modal
61. A dialog over a dialog keeps one dim and sits above it
62. A card's content fills the card
63. A locked module shows read-only, with the plan that unlocks it
64. Labels never wrap: they move to a menu, or become an icon
65. A card's content fits it: nothing scrolls sideways, nothing is cut off, nothing is stretched thin
66. This repo is the source of truth
67. Every Coolify resource can be redeployed
68. People's names are stored as first_name and last_name

Design profile: D1 Shell, D2 Grid and cards, D3 Type, D4 Color, D5 Controls, D6 Lists and tables, D7 Stats and charts, D8 Steppers, inboxes, dialogs, D9 Pickers, loading and empty states, D10 Copy, AI and tours, D11 Dark mode and phone, D12 Each app's product profile.

## How a rule is written

Each rule has:

- **Rule:** the one-line decision.
- **Why:** the reason, so edge cases can be judged.
- **Do / Don't:** the specifics.
- **Reference (FBS People):** where and how the reference project applies it (other projects are named when they show it better).
- **Check:** how a reviewer confirms it.

Add new rules at the end with the next number (see [Adding a rule](#adding-a-rule)). Never renumber and never reuse a number: other documents, plans and reviews refer to rules by number. A rule that no longer applies is marked retired and keeps its number (rule 59).

---

## Rule 1. Server-render only what search engines must see

- **Rule:** Use server-side rendering (SSR) when a page must be found by search engines. Use client-side rendering (CSR) when SEO doesn't matter.
- **Why:** SSR gives crawlers and link previews real HTML, but costs a render server, more complex code (no browser APIs during render) and a risk of putting private data in HTML. Pages behind a sign-in gain nothing from it.
- **Do:**
    - SSR public, indexable pages: landing and pricing pages, careers and job posts, public help articles, anything shared as a link.
    - Give every SSR page a unique title, a description, a canonical URL and Open Graph tags.
    - Guard browser-only code (`window`, `localStorage`, `matchMedia`) so it runs only after the page mounts.
    - CSR everything behind a sign-in: dashboards, lists, forms, settings, admin consoles.
    - Mark private pages `noindex`.
- **Don't:**
    - SSR a page just because the framework allows it.
    - Put personal or tenant data in server-rendered HTML that a crawler or cache could keep.
- **Reference (FBS People):** Render pages through `App\Http\Responses\InertiaPageResponse`: `csr()` for app pages, `ssr()` for public pages, or `auto()`, which picks SSR for components listed in `App\Helpers\InertiaPageType::PUBLIC_PAGES`. Add each public page (careers, job posts and apply, offers, pre-boarding links, pricing, sign-up) to that list. A plain `Inertia::render()` falls back to `config/inertia.php`, where SSR is on, so over 100 routes are still server-rendered and need moving to the helper.
- **Check:** View the page source signed out. Public pages show their content and meta tags; app pages show only the shell. App pages render no differently with the SSR server stopped.

## Rule 2. Pick records with an async select

- **Rule:** Any field that picks records uses an async select: searchable, loading results from the server as you type. This holds for one record or several, and for single records or groups of records.
- **Why:** Record lists grow without limit. A plain dropdown loads every row, gets slow, can't be searched and breaks at a few hundred items. One pattern everywhere also means people learn it once.
- **Applies to:**
    - People and other single records: employees, applicants, users, companies, courses, positions, templates, invoices.
    - Groups of records: branches, departments, roles, teams. An audience picker can mix groups and single records ("Davao Store + Kitchen + Ramona Dizon").
    - Single and multiple selection alike.
- **Not for:** short fixed lists that aren't records, such as a status, yes/no, a month, or a size. Use a plain select or a segmented control for those.
- **Do:**
    - Search on the server with a short debounce (about 250 ms), a page of about 20 results, and cancel the request a newer keystroke replaces.
    - Before anything is typed, show useful suggestions (recent, my team, most used).
    - Show each result with enough to tell look-alikes apart: a name plus a line such as position and branch, or a group's type and member count.
    - Show selected items as tokens, each with a Remove button. A multiple select also has Clear.
    - Keep selected items visible even when they aren't in the current results.
    - Show loading, no-results and error states inside the list.
    - For audience pickers, offer tabs per source (Everyone, Branches, Departments, People) and show how many people the combination reaches, counting each person once.
    - Build it as an accessible combobox: label, keyboard navigation, `aria-expanded`, `role="listbox"` and `aria-selected`.
- **Don't:**
    - Preload a full record list into the page for a dropdown.
    - Build a second, slightly different picker for one screen.
- **Reference (FBS People):** One shared async select component for every record field (single, multiple and audience modes); `resources/js/components/ui/select.tsx` stays for fixed lists only. The design canvas draws it as the `pick` field type (`docs/ui-redesign/canvas`, owner rules 15 and 16 in `docs/ui-redesign/NEXT.md`). Not built yet: no shared async select exists, and `components/people/employee-form.tsx` and `pages/people/org.tsx` preload up to 200 records from `search-active?limit=200`. `searchActive` in `v1\BaseController` accepts a `limit` up to 200; lookups should stay near 20.
- **Check:** Any record field: type two letters and see a request go out and results arrive. Pick two items, remove one, clear all. Tab to the field and work it with the keyboard only.

## Rule 3. Skeleton on first load, a contained spinner for changes

- **Rule:** When a page or panel loads for the first time, show a skeleton of its layout. When an existing record is created, updated or deleted, show a spinner over only the container that is changing, with that container dimmed.
- **Why:** A skeleton shows the shape of what's coming and avoids layout jumps. A contained spinner shows exactly what is busy without blocking the rest of the page, so people can keep reading and working.
- **Do:**
    - **First load:** render a skeleton that matches the final layout (same cards, rows and heights). Never show a blank page or a full-page spinner.
    - When data is already on screen or cached, show it and refresh quietly; don't flash the skeleton again.
    - **Create, update, delete:** put a spinner over the affected container only (the row, card, form or dialog). Lower that container's opacity (about 0.5) and block its pointer events and inputs while the request runs.
    - Set `aria-busy="true"` on the busy container, and keep its size so nothing around it moves.
    - Show the spinner only after a short delay (about 150 ms), so fast requests don't flicker.
    - On success, restore the container and confirm with a toast or inline message. On error, restore it and show the error in place, keeping what the person typed.
    - **Page change (owner, Oct 31):** every time a menu item, tab or link opens another page, blur the page area (right of the menu, below the top bar, never the menu or top bar themselves) and show the app's mark in the middle of the screen with an orbiting arc and a soft glow, until the new page arrives. Show it only after about 150 ms, never for a change that only touches the query (a filter, `?open=`) or a background reload, and keep the animation still for "reduce motion". When the new page arrives, its body eases in (fade and a 6 px rise, about 220 ms) while the menu, top bar and tabs stay put.
- **Don't:**
    - Cover the whole page or block navigation for a change to one record.
    - Use a skeleton for a save, or a spinner for a first load.
    - Disable unrelated parts of the page.
- **Reference (FBS People):** Build skeletons from `resources/js/components/ui/skeleton.tsx` and the spinner from `ui/spinner.tsx`. Wrap any container that saves in one shared busy wrapper (opacity, overlay spinner, `aria-busy`), and set it from the Inertia form or partial reload that container started. Not built yet: `aria-busy` appears nowhere in `resources/js`. UI 3.0: the page-change loader is `kit3/nav-loader.tsx` (mounted in `Shell` and `ConsoleShell`), the ease-in is `.ui3-enter` in `resources/css/ui3.css`.
- **Check:** Throttle the network. A first visit shows skeletons in the final layout. Editing one row dims and spins only that row while the rest of the page stays usable. Opening another page from the menu blurs only the page area and shows the mark with its arc; changing a filter on the same page shows nothing.

## Rule 4. No dead whitespace: every space and element has a purpose

- **Rule:** Every gap and every element on a screen is there on purpose. Cards, rows and dialogs carry real content edge to edge; space separates things, it never fills leftover room. Nothing is added for decoration alone: each card, label, icon, chart and button helps the person read or do something (owner, Oct 31, 2026). This applies to designs and to the built app alike.
- **Why:** Empty areas read as missing data or a broken layout, push useful content below the fold, and waste the small screens frontline staff use.
- **Do:**
    - Use one spacing scale (4 px base: 4, 8, 12, 16, 24, 32) and pick the step that matches the relationship: tight inside a group, wider between groups.
    - Make cards in one row end at the same height with real content. If one is shorter, give it related content (a breakdown, a list, a next step) or give its width to its neighbour.
    - Let charts, calendars and tables fill the card's width.
    - When a table is sparse, add useful columns or narrow the card.
    - Give a stepper's next-action box the full height of its card.
    - Put a lone control (a view switch, a date range) in the toolbar it belongs to, not in its own empty row.
    - Use an empty state only when there is truly nothing, and still offer the next action.
- **Don't:**
    - Stretch a card with blank space to match its neighbour.
    - Center a small form or a few rows in a large empty card.
    - Leave a half-empty grid row.
    - Add padding to make a page "breathe" at the cost of content.
    - Add an element with no job: a decorative card, a repeated title, a chart that says less than a number, an icon that adds nothing to its label.
- **Reference (FBS People):** `REPORT=1 node docs/ui-redesign/canvas/tools/layout.cjs` lists canvas rows where a card would stretch more than 40 px past its content. The spacing scale and card rules are in the design profile below.
- **Check:** On a screenshot at 1440 px and 390 px, no card has more than 40 px of blank space at the bottom, no row has an empty column, and every element on the page can be named by what it lets the person read or do.

## Rule 5. Client-rendered pages work through an API

- **Rule:** A client-rendered page (rule 1) is a shell plus API calls. The server renders only what the shell needs (the signed-in person, their permissions, feature flags); every list, record, lookup and change goes through JSON endpoints that follow one contract and are secure by default.
- **Why:** One contract serves the web app, phones, integrations and AI tools alike, and it can be tested, cached and paginated. The shell loads fast with skeletons (rule 3) while data streams in. Putting every check on the server keeps security in one place, whatever the client.
- **Do, for reuse:**
    - Build each module on one base controller or service with the same actions: `list` (search, filter, sort, page), `lookup` (the light search behind async selects, rule 2), `show`, `create`, `update` and `delete`.
    - Use the same query parameters everywhere: `search`, `filters[field]=value`, `sort_by`, `sort_dir`, `page`, `per_page` (default 25, at most 100).
    - Use the same responses everywhere:
        - Lists: `{ "data": [...], "meta": { "current_page", "last_page", "per_page", "total", "from", "to" } }`.
        - One record: `{ "data": {...} }`.
        - Errors: `{ "message", "errors": { "field": ["…"] } }` with the right status: 401, 403, 404, 409, 419, 422 or 429.
    - Shape every response with a resource or transformer that lists the fields it returns. Dates are ISO 8601; money is decimal strings or minor units with a currency.
    - Call the API from one client layer (rule 38): generated, typed route helpers instead of hand-written URLs, and one HTTP client that adds the CSRF token, handles errors, cancels superseded requests and caches reads.
    - Version the public API (`/api/v1/…`) and never break a version. Internal endpoints for the web app can change with it.
- **Do, for security:**
    - **Authentication:** the first-party web app uses the session cookie on the same origin (HttpOnly, Secure, SameSite=Lax) plus a CSRF token on every write. Never put tokens in `localStorage`. Bearer tokens are for third-party and machine clients only: scoped, hashed at rest, expiring and revocable.
    - **Authorization:** check permission on every request with policies. Hiding a button is not security.
    - **Scoping:** scope every query to the tenant and to what this user may see (their own records, their team), failing closed. Never trust a tenant or user id sent by the client.
    - **Input:** validate everything with request classes. Allow-list searchable, filterable and sortable fields, and never put a client value into SQL as a column or raw expression. Guard mass assignment.
    - **Output:** return only fields this caller may see; pay, government IDs and health data go only to roles allowed them. Use public ids (ULID or UUID) in URLs that leave the app.
    - **Limits:** rate-limit per user and per IP, cap page sizes and set request timeouts.
    - **Errors and logs:** in production, give generic messages with no stack traces or SQL. Log with a request id, and keep personal data out of logs.
    - **Cross-origin:** no CORS for first-party endpoints; an explicit allow-list for anything else.
    - **Files:** serve them through short-lived signed URLs, never public paths.
    - **Audit:** log sensitive reads and every change (who, what, when, from where).
- **Don't:**
    - Pass whole lists or records as page props on a client-rendered page.
    - Return models as they are (`toArray()`, `SELECT *`).
    - Write one-off endpoints per screen when the module's contract covers it.
    - Call the API with hand-built URLs scattered through components.
- **Reference (FBS People):**
    - **Modules:** extend `App\Http\Controllers\v1\BaseController`.
        - The page itself: `index()` and `show()` return the shell through `InertiaPageResponse::csr()`.
        - Lists: `paginationSearch()` honours the `$searchable`, `$filterable`, `$exactFilters` and `$sortable` allow-lists.
        - Async-select lookups: `searchActive()` returns only `$activeColumns`.
        - The rest: `$resource` shapes output, `authorize()` runs the policy, `auth` and `throttle:120,1` are the default middleware, and files go out as presigned URLs (`$fileUrlExpiry`).
    - **Sign-in:** the app uses Fortify sessions. The public API at `/api/v1` uses `api.token` bearer tokens (`AuthenticateApiToken`) with `can:people.manage` and `throttle:120,1`.
    - **Tenant scope:** the `CompanyScope` and `BranchScope` global scopes.
    - **Client:** use Wayfinder route functions (`laravel/wayfinder`). These still call `fetch()` with hand-written paths and should move to the client layer (rule 38): `components/bell.tsx`, `components/command-palette.tsx`, `components/people/employee-form.tsx`, `pages/people/org.tsx`, `pages/company/ai.tsx` and `lib/ai.ts`.
- **Known differences today:** FBS Learn's and FCA-SMS's `BaseController` default `per_page` to 10 and allow up to 1,000; FBS CRM's `Pagination` defaults to 10 with a cap of 2,000; FBS Pay's ops API uses `sort` and `direction` with camelCase keys. New code follows this rule's names and limits. The `{ success, message, data }` envelope that FBS Learn, FCA-SMS and FBS Suites send fits this contract (`data` and `message` as here, plus `success`).
- **Check:**
    - In the browser's network panel, the page HTML carries only shell props and data arrives as JSON.
    - Requesting another tenant's id returns 404, a write without the CSRF token returns 419, and an unknown sort field is ignored or rejected.
    - Going over the limit returns 429, and production errors show no stack trace.

## Rule 6. Start from the reference project

- **Rule:** A new project or module starts from the reference project, FBS People, and from this architecture repo, never from a blank page: their stack, folder layout, base classes, design profile and these rules. Change only what the new product truly needs.
- **Why:** The reference already settles hundreds of decisions (shell, tokens, tables, dialogs, pickers, states, wording, deploy) and has been reviewed by its owner. Starting from it is faster, keeps the FBS products consistent, and avoids the generic look a blank start drifts into.
- **Do:**
    - **Read first:** this file, this repo's [README](../README.md) index, the design profile (D1–D12 below) and [../structure/design-system.md](../structure/design-system.md).
    - **Copy the skeleton:** start from [../templates/](../templates/) and the layout in [../structure/folder-structure.md](../structure/folder-structure.md). Follow [../docs-and-ai/new-project-checklist.md](../docs-and-ai/new-project-checklist.md).
    - **Pick one UI kit and keep it:** kit A (shadcn new-york + Radix, as FBS People, FBS Pay and FBS Suites) or kit B (FBS Learn's own `CardGridPanel` kit). Never mix the two in one project ([../structure/frontend-patterns.md](../structure/frontend-patterns.md)).
    - **Same product family:** keep D1–D11 as they are, reuse the kit's components, and change only the data, copy and the screens that differ.
    - **Own brand:** write the app's own D12 (accent, font, logo, card tints) with the shared token names. The numbered rules stay as they are.
    - **Sample data first:** agree one sample dataset before building screens, so every screen tells the same story.
    - **Every screen gets:** its tabs, its dialogs, and the empty, loading and error states.
    - **Close the loop:** when a project solves something the reference doesn't, add it to this repo, as a rule, a design-profile section or a pattern doc (rule 66), so the next project gets it too.
- **Don't:**
    - Start a design from a generic template or a blank page when the reference covers it.
    - Copy the reference's sample company, people or brand into another product. Copy the patterns, not the data.
    - Change a shared rule in one project only. Change it in this repo first, then bring the projects in line (rule 66).
- **Reference (FBS People):**
    - Repository: `https://github.com/FrontlineBusinessDev/laravel-fbs-people`.
    - Design source: its design profile and the approved pictures in `docs/design-system/` (plan and handoff there too).
    - **No Claude Design step (owner, Nov 6, 2026):** a task does not have to be drawn in Claude Design or on the canvas first. The canvas in `docs/ui-redesign/canvas` stays as an archive for reference only.
    - Status and owner rules: `docs/ui-redesign/NEXT.md`. The sample-data pattern: `docs/ui-redesign/DATASET.md`.
- **Known differences today:** FBS CRM, Connect, Learn, FCA-SMS, Stock and Work were started before this rule, with their own kits and layouts. They move toward the reference as each file is touched (rule 57), never in one big rewrite.
- **Check:** A new project's first design review names what it kept from the reference and what it changed, and why.

## Rule 7. Multi-tenant subscription products

- **Applies to:** any product where customer organisations (companies, schools, stores) each get their own space and pay on a plan. Skip this rule for single-customer apps.
- **Rule:** One tenant can never see or change another's data. Plans decide what a tenant can use, never what it keeps. Billing states are explicit and reversible. Platform staff work outside any tenant, and every visit inside one is audited.
- **Why:** In a shared system, one missed filter leaks a customer's data to another customer, and a billing mistake can lock a company out of its own records. These rules make the safe path the default.
- **Do, for isolation:**
    - **Schema:** put a tenant id on every tenant-owned table, first in its indexes and in every unique key (`unique(tenant_id, code)`). Mark platform-wide tables as platform tables.
    - **Scoping:** apply one global scope to every tenant-owned model. It fails closed: no current tenant means no rows, never all rows.
    - **Resolving the tenant:** take it from the signed-in session, subdomain or token, never from a request field. Re-check membership on every request.
    - **Jobs, events and schedules:** carry the tenant id explicitly and restore the tenant before they run. Cache keys, file paths, queues, search indexes and rate limits are namespaced by tenant.
    - **Tests:** every module has a test that another tenant's id returns 404 for reading, changing and deleting.
- **Do, for plans:**
    - **Plans:** each plan is a list of modules (features) and limits: people, seats, branches, storage, AI credits. Check modules on the server (middleware or policy), not only in the menu.
    - **Locked modules:** a module the plan leaves out still shows, with a badge naming the plan or add-on that unlocks it, and opens read-only (rule 63).
    - **Limits:** going over a limit blocks new records with a clear message and an upgrade link. It never deletes or hides existing data, and downgrades follow the same rule.
    - **Usage:** count usage per tenant per period (AI credits, storage) and show it to the owner before they hit the limit.
- **Do, for billing:**
    - **Pricing:** keep it in data, not code (plans, bands, currencies, add-ons, discount codes), and log every change. A price change reaches existing tenants only at their next renewal, with notice.
    - **Invoices:** numbered in sequence and never edited; fix one with a credit note. Tax follows the tenant's country.
    - **Payment gateways:** report through signed webhooks. Store each event once, keyed by the gateway's event id, so a retry never charges or credits twice. Manual payments (bank transfers) wait for a person to review the proof.
    - **States:** every tenant is in exactly one state:
        1. trial
        2. active
        3. past due (retries and reminders)
        4. suspended (people can sign in, view and export; nothing new is saved except writes that protect people, such as time punches)
        5. cancelled
        6. deleted, only after a stated retention period and a final notice (the client has 30 days after termination to request its data: [client data policy](../policies/client-data-ownership-and-extraction.md))
    - **Reactivating:** paying, or a staff action with a reason, restores the tenant at once.
    - **Trials:** no card needed, a fixed length, reminders before the end, and an extension a staff member can grant with a reason.
- **Do, for the platform:**
    - **Staff:** platform staff are separate users with their own roles (for example owner, finance, support, developer), never tenant users with a flag.
    - **Entering a tenant:** needs a reason and a time limit. It is logged, and the tenant's owner can see the visit.
    - **The console:** tenants, billing, plans, flags, queues, logs and health live there, outside every tenant.
    - **Rollouts:** feature flags roll features out per tenant or per plan, with a kill switch for each. Maintenance windows give tenants notice.
- **Do, for each tenant's own space:**
    - **People:** the tenant's owner manages its users, roles and sign-in, including SSO on plans that offer it.
    - **Privacy:** every tenant can export all of its data at any time. Privacy duties (a data protection officer, data requests, retention) are handled per tenant.
    - **Logs:** carry the tenant id but no personal data.
- **Don't:**
    - Write a query on tenant data without the scope, or turn the scope off without a comment saying why.
    - Accept a tenant id from the client.
    - Delete or hide data because a plan changed or a payment failed.
    - Edit a sent invoice.
    - Let platform staff into a tenant silently.
- **Reference (FBS People):**
    - **The tenant:** a `Company`. Tenant-owned tables are `app_` tables with `company_id` indexed first.
    - **Scoping:** `App\Models\Scopes\CompanyScope` fails closed: with no current company it matches nothing. `BranchScope` narrows further. `ResolveCompany` sets the company for each request.
    - **Plans:** module gates use the `feature:` middleware (`EnsureFeature`), and limits throw `App\Exceptions\PlanLimitReached` (422 with an upgrade link).
    - **Platform staff:** `PlatformRole` (owner, finance, support, developer), with `EnsurePlatformStaff` guarding the `/console` routes.
    - **Billing:** PayMongo, Xendit and Stripe webhooks at `webhooks/{gateway}`, stored once each in `app_gateway_events`. Details are in `docs/subscription/REQUIREMENTS.md`.
    - **Console design:** on the canvas, including tenants, billing, plans, flags, maintenance and the audit log (`docs/ui-redesign/DATASET.md`, Platform console).
- **Check:**
    - A second test tenant can't read, change or list the first one's records through any endpoint.
    - A plan downgrade keeps every record readable.
    - A gateway webhook sent twice records one payment.
    - Entering a tenant from the console shows up in that tenant's audit log.

## Rule 8. Fewest clicks for the most common tasks

- **Rule:** Each screen's most common task finishes in the fewest steps, from where the person already is, without leaving the page when that can be avoided. Every extra click needs a reason.
- **Why:** Frontline staff and busy managers use the app between other work, often on a phone. Each extra page, menu or confirmation costs time and attention, and makes people give up or work around the system.
- **Do:**
    - **Give every task a click budget,** write it down, and design to it.

        | Task                                            | Budget                   |
        | ----------------------------------------------- | ------------------------ |
        | Clock in or out                                 | 1                        |
        | Approve one request from Home or a notification | 1                        |
        | Approve several                                 | 2                        |
        | Find any person, page or action                 | 2 (`Ctrl K`, then Enter) |
        | See my latest payslip                           | 2                        |
        | File leave                                      | 3                        |

    - **The task comes to the person.**
        - Home shows what waits for them, with the action on each row ("Review", "Approve").
        - Alerts, push notifications and emails link straight to the record with its action ready.
    - **Act in place.**
        - Approve, decline, retry or edit from the list row or the inbox, and select several for a bulk action bar.
        - Open records and forms in a side sheet so the list stays behind, never on a new page.
    - **Know what you can.**
        - Prefill from context: the person, today, their branch, their last choice.
        - Offer one-tap presets ("This cutoff", "7 days").
        - Remember each person's filters, views and sort.
    - **Ask less.** Ask only for what can't be derived, and tuck rare options behind "More options".
    - **Undo instead of asking.** Act at once and offer Undo in a toast for 5–10 seconds. Keep confirmations for actions that can't be undone, and say exactly what will change.
    - **One way to everything.**
        - A command palette (`Ctrl K`) reaches every page, record and action ("file leave for Joy").
        - Ask AI turns a multi-step task into a single approval card.
    - **Save as people type.** Autosave drafts, Enter submits, Esc closes, and the most-used actions have keyboard shortcuts.
    - **Phone.** The primary action sits within thumb reach, the main hubs are in the bottom tab bar, and nothing needs a hover.
- **Don't:**
    - Hide the main action in a ⋯ menu.
    - Split a short form into a multi-page wizard.
    - Confirm an action that can be undone.
    - Ask again for something the system already knows.
    - Send people to a page that only links to another page.
- **Reference (FBS People):**
    - **On the canvas:** Home and Team "Waiting for you" rows with Review, the approvals inbox with bulk approve, the `Ctrl K` palette, Ask AI approval cards, and sheets for records and forms.
    - **Notification links:** these open `/approvals?open=leave-12`, with the request first and one tap from Approve (`docs/core-hr/REQUIREMENTS.md`, Approve from push).
- **Check:** Walk the click budget table on the built app, desktop and phone. Any task over its budget needs a written reason or a fix.

## Rule 9. Show data as infographics, not stock charts

- **Rule:** Every chart answers one question, and its form comes from that question. Prefer forms that show the real shape of the data (calendars, people-by-day grids, progress, funnels, steps) over stock bar, pie and line charts. Every chart says its takeaway in words.
- **Why:** Stock charts make people decode axes and legends to find the point. An infographic built for the question shows the answer at a glance, and frontline data (shifts, days, people, steps) has natural shapes that read better than bars.
- **Do:**
    - **Start from the question,** then pick the form from the catalog below. If none fits, use a plain table, not a stock chart.
    - **Lead with a summary line:** "9.1 people clocked in a day, on average, +12% vs the 14 days before".
    - **Label directly:** put names and values on the marks instead of a far-away legend.
    - **Highlight the one that matters** in the accent color and mute the rest. Add reference lines for targets and limits ("8 h target", "Headcount 12").
    - **Give context** with deltas and their basis: "+6 vs September", not a lone "+6".
    - **Meet accessibility basics:** every chart has a "Show data" table and a text alternative, and color is never the only signal (add text, icons or patterns).
    - **Keep scales honest:** start bars at zero and don't show false precision ("₱742.6k", not "₱742,612.37").
    - **Handle thin data:** a chart with too little data shows the numbers instead, and an empty chart says why and what to do.
- **Don't:**
    - Use plain vertical bar charts, 3D, or pies with more than 5 slices.
    - Use two y-axes or rainbow palettes.
    - Show a chart with no takeaway, or a chart where a single number would do.
- **Catalog:**

    | Question                                          | Form                                                                      | People canvas kind    |
    | ------------------------------------------------- | ------------------------------------------------------------------------- | --------------------- |
    | How are we doing right now?                       | Stat strip: big number, delta pill, hint line, sparkline or ring          | `kpis`                |
    | How does a count change over time?                | Capsule columns with a reference line and an optional second line (lates) | `cols`                |
    | How do rare events spread over time?              | Lollipops                                                                 | `cols`, style `lolli` |
    | How does money change over time?                  | Smooth area with the end value labelled                                   | `area`                |
    | What happens day by day across months?            | GitHub-style calendar, darker is more                                     | `contrib`             |
    | Who was in, late, on leave or off each day?       | People-by-day grid, one row per person                                    | `heat`                |
    | What are the parts of a whole (5 or fewer)?       | Donut with the total in the centre and counts beside it                   | `donut`               |
    | What ranks highest? Where is it concentrated?     | Horizontal bars with values on them                                       | `hbars`               |
    | How close are we to a goal?                       | Gauge or rings                                                            | `gauge`, `rings`      |
    | How much of a limit is used?                      | Meters that turn amber near the limit                                     | `meter`               |
    | How many make it through each step?               | Funnel or stage counters                                                  | `funnel`, `stages`    |
    | Who joined and who left?                          | Diverging columns with names as chips                                     | `diverge`             |
    | Where does this process stand, and what's next?   | Stepper with a next-action box                                            | `steps`               |
    | What is in progress, by stage?                    | Kanban                                                                    | `kanban`              |
    | Where do people sit on performance and potential? | 9-box                                                                     | `nine`                |
    | Who reports to whom?                              | Org tree                                                                  | `tree`                |
    | Who works when? When are people away?             | Week roster; month calendar                                               | `week`; `cal`         |
    | What happened, in order?                          | Timeline                                                                  | `tl`                  |
    | Who approves what? Who can do what?               | Flow; permission matrix                                                   | `flow`; `matrix`      |

- **Reference (FBS People):** The catalog's kinds are built in the canvas renderer (`prep()` in `docs/ui-redesign/canvas/project/Page.dc.html`) and are the reference for the React chart components. Design profile D7 sets their look. "Show data" tables and text alternatives are still to add (gap 23 in `docs/ui-redesign/NEXT.md`).
- **Check:** For each chart on a page, a reviewer can say its question and read its answer within five seconds, without the legend.

## Rule 10. Every file and backup lives in private object storage

- **Rule:** All files (uploads, documents, exports, images) and all backups are stored in S3-compatible object storage, in private buckets. Nothing is kept on the app server's disk.
- **Why:** App servers and containers come and go, and their disks are lost on redeploy. Object storage keeps files safe, scales without planning, supports lifecycle rules and locking for backups, and stays portable: any S3-compatible provider can replace it without code changes.
- **Do:**
    - Use the framework's S3 driver pointed at the provider's endpoint, so the code only knows "S3".
    - Keep private buckets per environment, a copy of backups in a second region, and public images only behind the CDN. Projects that share a bucket each keep everything under their own root folder (rule 50).
    - Put every tenant in its own folder inside the project's root folder (rule 50), for example `{root}/uploads/companies/{tenant_id}/…`.
    - Store objects under generated keys, and keep the original file name, type, size, checksum and owner in the database.
    - Serve private files only through short-lived signed URLs, after a permission check.
    - Upload large files straight to storage with a signed upload, then check them on the server: size, real type (by content, not extension), and a virus scan before anyone can open them.
    - Turn on versioning, encryption at rest and lifecycle rules (empty the recycle bin after 30 days, expire backups on schedule). Lock backup objects until they expire.
    - Pick the region closest to the users and within data-residency rules.
- **Don't:**
    - Write files to `storage/app` or `public/` in production.
    - Make a bucket or object public, unless it holds public images behind the CDN.
    - Put tenant names or personal data in object keys. The tenant id in its folder name is fine.
    - Share one key across environments.
- **Reference (FBS People):** The provider is DigitalOcean Spaces in `sgp1` (Singapore, the closest region to the Philippines). The `s3` disk in `config/filesystems.php` already points at it (`AWS_ENDPOINT=https://sgp1.digitaloceanspaces.com`), and every key starts with the project's root folder, `AWS_BUCKET_FOLDER` (rule 50). `v1\BaseController` serves files through presigned URLs (`$fileUrlExpiry`). The buckets and lifecycle are drawn in the console's Storage page on the canvas. Spaces keys move to the console under rule 12.
- **Check:** Delete the app server's local storage and redeploy: every file and backup still opens. No bucket lists publicly, and an expired signed URL returns 403.

## Rule 11. Each tenant manages its files and backups

- **Rule:** Every tenant gets a file explorer for its documents and a nightly backup it can see, download and ask to restore. Both stay inside the tenant's own space and storage quota.
- **Why:** Customers trust a system with their records when they can find every file, get their data out at any time, and recover from mistakes without waiting on support.
- **Do, for files:**
    - **Feel like a desktop file explorer:**
        - A folder tree, a breadcrumb path, and a list or grid with name, size, date and owner.
        - A details pane with a preview, versions, who can open the file, shared links and the scan result.
        - Checkboxes, drag and drop to upload or move, right-click with the same actions as the toolbar, and keyboard keys (Delete, F2 to rename, `Ctrl A`).
    - **System folders follow records:** each employee's 201 file, payslips, hiring and policies. Who can open them follows who can see the record; nobody sets those permissions by hand.
    - **User folders** can be added anywhere outside system folders, with an audience picker (rule 2) for access.
    - **Deleting** moves files to a recycle bin for 30 days. Files the law requires you to keep can't be deleted until their retention ends.
    - **Versions:** an upload with the same name adds a version, and duplicates are flagged instead of stored twice.
    - **Shared links** expire, can have a password, and are logged. Files with health data or government IDs can't be shared outside the company.
    - **Search** by name, type, owner and folder. Download several at once as a zip.
    - **Quota:** a storage meter shows files, backups and the recycle bin against the plan's limit (rule 7).
- **Do, for backups:**
    - **Nightly:** back up every tenant at a quiet hour in its own time zone. A backup holds every record, every file, the settings and the audit log, all encrypted and checksum-verified.
    - **Retention:** keep 7 daily, 4 weekly and 12 monthly backups, and a manual backup for 30 days.
    - **Owners can:**
        - see every backup and its size and check result
        - back up now
        - download a backup as open formats (CSV or JSON plus the files)
        - copy each backup to their own S3-compatible bucket, using a write-only key that is tested before saving
    - **Restoring:**
        - One file or record: the owner restores it from the recycle bin.
        - A full or partial restore: platform staff run it from a request, with a reason and point in time, taking a fresh backup first. It is logged and shown to the owner.
    - **Failures:** a failed backup or copy retries, then alerts the owner and the platform. Run a restore drill every month and record the result.
- **Don't:**
    - Let one tenant's backup include, or restore into, another tenant.
    - Delete a backup early because a plan changed or a payment failed (rule 7).
    - Show file permissions that disagree with the record's permissions.
- **Reference (FBS People):** These are drawn on the canvas as Company › Data › Files and Backups, the console's Storage pages, and their dialogs: upload, new folder, move, share, details, recycle bin, back up now, backup settings, ask for a restore, and restore a company.
- **Check:** A manager opens a direct report's 201 file but not a peer's. A deleted file comes back from the recycle bin. Last night's backup downloads, opens, and restores into a test tenant.

## Rule 12. Keys and secrets are managed in the platform console, not `.env`

- **Rule:** Every outside credential is added, rotated and revoked by platform Developers or the Platform owner on a console page: AI providers, payment gateways, storage, mail, sign-in providers, chat apps and push. `.env` holds only what the app needs to start.
- **Why:** Keys in `.env` need server access and a redeploy to change, get copied between machines, and leave no record of who changed what. A console page makes rotation routine, keeps a history, and limits who can touch keys.
- **Do:**
    - Keep in `.env` only `APP_KEY` (or a key-management key), the database, the cache or Redis connection, and settings that aren't secret but are needed at start-up, such as the storage root folder (`AWS_BUCKET_FOLDER`, rule 50).
    - Store each key encrypted at rest (with `APP_KEY` or a KMS), per environment, with who added it, when, when it was last used, and when it was rotated.
    - Make values write-only: after saving, show only the last 4 characters. Nobody can read a key back, including staff.
    - Test a new key with one real call before saving. Let the old key keep working for a short overlap (for example 1 hour), then revoke it.
    - Read keys at use time through one secrets service with a short cache that is cleared on save, so a rotation takes effect without a deploy or restart.
    - Flag keys older than 90 days, and log every add, rotation, reveal attempt and delete in the platform audit log.
    - Limit the page to Developers and the Platform owner, with two-step sign-in required, and re-ask for it before showing or changing a key.
    - Use least-privilege keys: separate keys per environment and per purpose (files and backups apart), with write-only keys where possible.
- **Don't:**
    - Put a third-party key in `.env`, in code or config files, in logs or in error reports.
    - Email keys or paste them into tickets.
    - Reuse a production key in staging.
- **Reference (FBS People):** Today the outside keys (`AWS_*` for Spaces, the gateways, the AI provider, mail, and `VAPID_*` for push) come from `.env`. `App\Models\Setting` (`app_settings`) stores platform settings as plain JSON, so keys need an encrypted store: an encrypted cast or a separate secrets table, behind a secrets service that config reads from. The console page is drawn on the canvas as Settings › Keys & secrets, with a Rotate a key sheet.
- **Check:**
    - `.env` holds only the start-up values.
    - Rotating a key on the page takes effect without a deploy.
    - The database never holds a key in plain text.
    - The audit log shows who rotated which key and when.

## Rule 13. A landing page whose every claim is true, with legal pages from the CMS

- **Applies to:** multi-tenant subscription products (rule 7).
- **Rule:** The product has a public landing page, and everything it says, shows and prices matches the product as built and as designed. The privacy policy and the terms are never copied into the app: they are shown from the company's CMS in an iframe, so there is one current version.
- **Why:** A claim the product can't back up loses trust at the first demo and can be a legal problem (misleading advertising, privacy law). Legal text copied into the app drifts out of date; one CMS page that legal owns stays current everywhere.
- **Do, for the landing page:**
    - **Render it on the server** (rule 1), with a title, description, canonical URL and Open Graph tags.
    - **Claim only what ships.**
        - Every feature named on the page is live on the plan it is listed under.
        - Anything coming next is labelled "Coming soon".
        - Write "AI-assisted", not "automatic", where a person approves (rule 8, approval cards).
    - **Show the real product:** screenshots and mockups come from the approved design pictures or the built app, with sample data that obeys the dataset rules. No stock dashboards.
    - **Prices come from the same plan data billing uses** (rule 7): bands, minimums, currencies, VAT note and trial terms. A price change in the console changes the page.
    - **Numbers are real and sourced:** customer counts, uptime, response times. Remove a number nobody can back up.
    - **Compliance claims name the law:** "built for the Data Privacy Act (RA 10173)" and "DOLE-ready reports" only when the feature exists and was checked.
    - **Review the page in the same review as each release:** any feature, plan or price change updates the landing page in the same release.
- **Do, for the privacy policy and terms:**
    - **Link every mention** (footer, sign-up, checkout, invites, careers, the app's Help menu) to one component that opens the CMS URL in an iframe, with a link to open it in a new tab.
    - **Configure the URLs** in platform settings (not hard-coded) and record the version and date each tenant accepted.
    - **Embed it safely:**
        - Give the iframe a `title`, `referrerPolicy="no-referrer"`, and a `sandbox` that allows only what the page needs.
        - Allow only the CMS origin in the app's CSP `frame-src`, and have the CMS allow the app in `frame-ancestors`.
        - While it loads, show a skeleton (rule 3). If it can't load, show a plain link to open the policy in a new tab.
    - **When the CMS text changes,** ask owners to accept the new version (Settings › General: "Ask companies to accept new terms").
- **Don't:**
    - Claim a feature, integration, certification or price the product doesn't have.
    - Use mockups of screens that don't exist.
    - Paste legal text into the app or the repo.
    - Frame any page other than the CMS legal pages.
- **Reference (FBS People):**
    - **Landing page:** the `welcome` page, listed in `InertiaPageType::PUBLIC_PAGES` for SSR.
    - **Legal pages:** `resources/js/components/legal-link.tsx` already opens `https://frontlinebusiness.com.ph/privacy-policy` and `/terms-of-service` in an iframe dialog from the landing and sign-up pages. Still to do:
        - add a `sandbox`
        - move the URLs into platform settings
        - replace its "Loading…" text with a skeleton
        - send a Content Security Policy with `frame-src` limited to the CMS origin (the app sends none today)
    - **Source of truth:** plans and prices come from `docs/subscription/REQUIREMENTS.md` §2.
    - **Canvas:** the landing and pricing pages are not drawn on the canvas yet.
- **Check:** Before each release, walk the landing page claim by claim against the built app and the plan data, and fix or remove anything that doesn't match. Open the policy links from sign-up and the footer: both load the CMS page, and both open in a new tab when the frame is blocked.

## Rule 14. A free public demo that never touches the database

- **Applies to:** multi-tenant subscription products (rule 7).
- **Rule:** Anyone can try the product without signing up, as each of its roles. The demo's data is stored in the database as a read-only snapshot. When someone starts the demo, the browser loads that snapshot once and runs on its own copy. Every change stays on that person's computer; nothing is ever written back to the server.
- **Why:** A live demo sells better than screenshots. Keeping the data in the database lets the team update it without a release, and running it in the browser means visitors can never change shared data, see each other's changes, or reach a real tenant.
- **Do, for the snapshot:**
    - **Build it** from a dedicated demo tenant seeded with fictional data that follows the project's dataset rules (no real people, emails, phone numbers or IDs). Export it into a platform-level snapshot record with a version, then remove the tenant: the snapshot is not a tenant, so tenant queries, metrics and billing never count it.
    - **Make it complete:** every module the landing page names (rule 13), each role's view, and the states worth showing (approvals waiting, a cutoff to lock, a probation due).
    - **Show the AI at work:** include a history of AI so visitors see what it does: runs for every agent with their tools, cost and outcome, Ask AI threads for each role, approval cards both waiting and decided, Ask Insights answers, and the credits used against the pool (rule 20). Visitors can approve or decline the waiting cards, and the change stays in their own copy.
    - **Keep it small and fast:** a compressed JSON bundle (aim under 2 MB) served from a public, read-only, cached and rate-limited endpoint, with an ETag or version so browsers reload it only when it changes.
    - **Let platform staff refresh it** from the console, with the change logged.
- **Do, in the browser:**
    - **Swap the API, not the screens:** the demo runs the real screens, and only the API client (rule 5) changes, to a local adapter that answers from the snapshot.
    - **Keep writes local:** creates, updates and deletes go to the browser's own copy (memory or IndexedDB), keyed by snapshot version. Reset wipes it, and so does signing out of the demo.
    - **Shift dates relative to today** when the snapshot loads, so the demo never looks old.
    - **Simulate side effects:** emails, SMS, payments, uploads, exports and integrations show a "Simulated in the demo" note. AI uses canned answers or a small, rate-limited quota.
    - **Make it clear it's a demo:** a banner with a "Viewing as" role switcher, Reset, and a sign-up call to action. Role tours (rule 8 and the page tours) start on first visit.
- **Do, for security:**
    - The demo has no server session and no tenant: every non-demo endpoint answers it 401 or 403, and the snapshot endpoint is GET-only.
    - The snapshot holds no secrets, keys or internal notes, and it is checked for real personal data before it's published.
    - Demo traffic is rate-limited, kept out of tenant metrics, and counted on its own (visits, role chosen, sign-ups).
- **Don't:**
    - Let the demo write to any database, queue or storage bucket.
    - Share one demo tenant that every visitor edits on the server.
    - Hard-code demo data in the frontend, where it drifts from the product.
    - Send a real email or charge a real card from the demo.
- **Reference (FBS People):**
    - **Today:** the public Test Drive at `/demo/{role}` (`App\Http\Controllers\DemoController`) renders the real pages with a fake signed-in user, for the Owner, HR Admin, Manager and Employee roles. It keeps records in the visitor's browser (`resources/js/demo`), and they are wiped on sign-out. But the seed data is written in the frontend, not stored in the database.
    - **To meet this rule:**
        1. Move the seed data into a versioned snapshot record in the database, built from a seeded demo tenant that follows `docs/ui-redesign/DATASET.md`.
        2. Serve it from a cached, read-only endpoint.
        3. Have the browser's mock API load it instead of its built-in data.
    - **Requirements:** `docs/subscription/REQUIREMENTS.md` §10.
- **Check:** Start the demo, change records, approve requests and delete a file. The database, queues and buckets show no change. A second browser sees the untouched snapshot, and Reset brings the first back to it.

## Rule 15. One reusable billing engine, run from the platform console

- **Applies to:** multi-tenant subscription products (rule 7).
- **Rule:** Billing is one self-contained module that any subscription product can reuse unchanged. The product only tells it three things: who the tenant is, what to count (usage), and which modules each plan unlocks. Platform staff manage every plan, invoice, payment and subscription from the console; nobody edits billing data by hand in the database.
- **Why:** Billing is costly to get right (tax, sequences, gateways, retries, refunds) and costly to get wrong. Building it once, behind a clear boundary, lets every new product launch paid plans quickly, and keeps every money action reviewable in one place.
- **Do, for the engine:**
    - **The boundary:** the product supplies a tenant contract (id, name, country, currency, billing contact), usage meters (for example people, storage, AI credits) and a module map. The engine owns everything else and never reads product tables.
    - **Catalog in data:**
        - plans, graduated price bands per currency, minimums, add-ons, discount codes and trial terms
        - yearly or monthly cycles, with the yearly discount as a setting
    - **Subscriptions:**
        - one per tenant: plan, cycle, start and renewal dates, scheduled changes, and the state machine from rule 7
        - upgrades take effect at once with proration; downgrades wait for the renewal
    - **Invoices and credit notes:**
        - numbered in sequence per issuing entity and never edited
        - tax by the tenant's country (VAT in the Philippines, none for exports)
        - a frozen copy of the buyer, lines and usage at issue
        - a PDF
    - **Gateways behind one interface:** checkout, charge a saved method, refund, verify a webhook. Adding a gateway means adding one adapter. Webhooks are signed and stored once per event id (rule 7).
    - **One daily run** for renewals, trial ends, dunning retries and reminders, suspensions and scheduled plan changes. It is safe to run twice.
    - **Exports for accounting:** invoices, payments, refunds and tax, by period.
    - **Gateway keys** live in the console (rule 12).
- **Do, in the platform console:**
    - **Plans:** plans, prices, modules, add-ons and discount codes, with a preview of who is affected and notice before a price change reaches them.
    - **Subscriptions:** change plan, extend a trial, grant credit or free months, pause, cancel or reactivate. Each needs a reason.
    - **Invoices:** issue one-off invoices, resend, and void through a credit note.
    - **Payments:** review bank-transfer proofs (with the payment-verifier suggestion), retry, and refund. A refund needs a reason; above a set amount, a second person approves it.
    - **Dunning:** the retry schedule, reminders and the grace period before suspension.
    - **Reports:** MRR and ARR by currency, new, expansion and churned revenue, failed payments, and trial conversion.
    - **Access:** each action follows the platform roles (Finance, Developer, Platform owner), and every money action is in the platform audit log.
- **Do, for tenants:** the owner's Billing page is part of the module too: current plan and usage, change plan, payment methods, invoices to download, and pay now. It works the same in every product.
- **Don't:**
    - Put billing logic or price numbers in product code.
    - Change an invoice, a payment or a subscription in the database by hand.
    - Let one product fork the engine; improve it once and update everywhere.
- **Reference (FBS People):**
    - **Built:** the engine is in `app/Billing`:
        - `Ledger.php` for quotes, invoices, settlement and plan changes
        - `Sweep.php`, the daily run (`php artisan subscriptions:sweep`, 01:00 Manila)
        - `Gateways/` for PayMongo, Xendit and Stripe behind one interface
        - `Metrics.php`
    - **Data:** `app_invoices` (`SI-000001` from `app_sequences`), `app_invoice_lines`, `app_payments` and `app_gateway_events`.
    - **Screens:** Company › Billing for owners, and Console › Billing for staff.
    - **Designed but not built:** the console's Billing, Plans and Tenants boards on the canvas show the management screens this rule asks for.
    - **Still to do:**
        - move the gateway settings from `config/billing.php` and `.env` to the console (rule 12)
        - add refunds with a second approver, the subscription actions and the accounting exports
        - keep the module free of FBS People names, so it can be lifted into a package
    - **Requirements:** `docs/subscription/REQUIREMENTS.md` §2 and §7.
- **Check:**
    - A new product can sell plans by supplying only the tenant contract, its meters and its module map.
    - Every money action in the console shows in the audit log with a reason.
    - Running the daily job twice changes nothing the second time.

## Rule 16. New modules reuse the design; better ideas are proposed first

- **Rule:** A new module starts from the existing design: its shell, page templates, components and patterns (rule 6 and the design profile). When the AI or a designer believes a different pattern would serve the module better, they propose it and wait for the owner to approve it before building anything.
- **Why:** Reusing the design keeps the product consistent and fast to build. Good new ideas should still get in, but on purpose: an unreviewed new pattern splits the product's look and behaviour, and is expensive to undo once it ships.
- **Do:**
    - **Map the module to existing templates first:**
        - overview with stats
        - list with a toolbar and a side sheet
        - inbox with detail
        - stepper flow
        - settings form
        - file explorer
        - dialogs and confirms
    - **Reuse components as they are:** the async select (rule 2), tables, charts from the catalog (rule 9) and the loading states (rule 3).
    - **Plan the module's screens** with their tabs, dialogs, and empty, loading and error states, from the templates above and the design profile. There is no Claude Design or canvas step first (owner, Nov 6, 2026).
    - **When proposing something better, write it down** with:
        - what changes
        - why it is better for this module's users
        - where else it would apply
        - what it costs
        - a picture or short mockup showing both the current pattern and the proposal
    - **Ask the owner to choose,** with the current pattern as the safe default.
    - **If the proposal is approved,** add it to the design profile and update the other modules it applies to, so it becomes the standard (rule 6, close the loop).
- **Don't:**
    - Build a module with a new layout, component or interaction that nobody approved.
    - Ship a one-off variant of an existing component for one screen.
    - Leave an approved improvement in one module only.
- **Reference (FBS People):**
    - **The design:** the design profile below and the approved pictures in `docs/design-system/`; the owner rules are in `docs/ui-redesign/NEXT.md`. The canvas in `docs/ui-redesign/canvas` is an archive.
    - **Proposals:** add each proposal to `NEXT.md` with its picture or note, and record the owner's decision there.
- **Check:** Every new module's first review shows which templates it reused. Any new pattern has an approval in `NEXT.md`.

## Rule 17. Plan and build for reuse

- **Rule:** Every plan and every build starts by asking what already exists, and separates what is generic from what belongs to this product. Generic parts are built once, with a clear boundary and settings, so the next product can reuse them unchanged.
- **Why:** Most of what a new product needs (sign-in, tenants, billing, files, CRUD screens, tables, pickers, charts, notifications, audit) has been built before. Reusing it makes delivery faster and safer, because those parts are already tested and reviewed. Rebuilding it creates copies that drift apart and each need fixing.
- **Do, when planning:**
    - **Search the catalogs first:** these rules, this repo's pattern docs ([backend](../structure/backend-patterns.md), [frontend](../structure/frontend-patterns.md)) and earlier projects. List what the plan reuses as is, what it adapts and what is new.
    - **Write the plan in two layers:** a generic module spec (what it does for any product, with its contract and settings) and this product's configuration (names, plans, rules, data).
    - **Mark each new piece** as generic or product-specific. Only the product layer may name the product, its customers or its domain.
    - **Record decisions** that will matter to the next project (what was chosen and why) next to the module.
- **Do, when building:**
    - **Give each generic module a boundary:** a small contract (interfaces, events, config), its own folder, its own tests and a short README. The README covers what it does, what it needs from the product, its settings, and how to add it to a new project.
    - **Prefer settings and data to code branches:** plans, rules, labels, limits and feature switches are configurable, never `if (product === …)`.
    - **Share UI the same way:** one component per pattern (table, async select, sheet, stat strip, chart kinds), driven by props and design tokens, never copied and edited.
    - **Use the shared bases:** a generic CRUD base for lists and records (rule 5), shared request validation, policies and resources.
    - **Keep neutral names** in generic code (`Tenant`, `Plan`, `Meter`), not product words.
- **Do, with judgement:** reuse what exists now; make a piece generic when a second real use is known; extract it into a shared package when a third project needs it. Don't build options nobody needs yet; a clean boundary is enough to generalise later.
- **Don't:**
    - Copy and edit a component or module to make a variant.
    - Put product names, customer data or one-off rules in a generic module.
    - Start a module from scratch without checking the catalogs.
    - Make something generic only in name, with hidden product assumptions inside.
- **Reference (FBS People):**
    - **The catalog:** this repo's [backend](../structure/backend-patterns.md) and [frontend](../structure/frontend-patterns.md) patterns (which replace the old `reusable-components-in-all-projects.md` copies), plus these rules (rule 6).
    - **Generic bases:** `App\Http\Controllers\v1\BaseController` (CRUD and lookups), `app/Billing` (rule 15), `resources/js/components/ui`, and the design profile's tokens.
    - **Still to do:** move FBS People names out of the generic parts so they can become packages.
- **Check:**
    - Each plan lists what it reuses, what it adapts and what is new, and why.
    - Code review finds no product names in generic modules and no copied components.
    - Each generic module has a README with its contract and settings.

## Rule 18. Privacy by design for personal data

- **Rule:** Personal data is shown only to people who need it, kept only as long as the law requires, and every look at sensitive data is recorded. Sensitive fields (pay, government IDs, health, bank details, civil status, disability) are hidden by default and opened on purpose.
- **Why:** HR and customer systems hold the most sensitive data a company has. Privacy laws (the Philippine Data Privacy Act RA 10173 and the GDPR) require need-to-know access, retention limits, data-subject rights and breach reporting. A leak costs trust that no feature wins back.
- **Do:**
    - **Classify every field:** public, internal, personal, or sensitive. Permissions apply per field, not just per page; a manager sees their team's attendance but not their pay or health data.
    - **Mask sensitive values** (`34-•••••12-3`) and log every reveal and every export with who, what and why.
    - **Keep coworker views coarse:** a colleague's leave shows as "Out", never its type.
    - **Retention:** set a period per record type. Expired data is deleted or anonymised by a scheduled job, except where the law says to keep it.
    - **Rights:** let people download their data, ask for corrections, and ask for deletion within the legal limits. Track each request against its deadline.
    - **Consents:** keep them explicit and changeable (birthday shown, photo in the directory).
    - **Breach response:** a register of incidents, with the regulator's 72-hour clock shown from discovery, and a named data protection officer per tenant.
    - **Minimise:** collect only what a feature needs, and keep personal data out of logs, URLs, analytics and AI prompts that leave the tenant.
- **Don't:**
    - Show a sensitive field in a list, an export or an email without a permission check.
    - Copy production personal data into staging, demos (rule 14) or test fixtures.
- **Reference (FBS People):** `app/Privacy`, the privacy pages (Me › Privacy and Company › Data › Privacy), and `docs/core-hr/REQUIREMENTS.md` §7.34 (Privacy and audit). Still to build: the breach register (gap 19 in `docs/ui-redesign/NEXT.md`) and per-field reveal logging (not yet on the gap list).
- **Check:** As a manager, pay and IDs of team members are hidden. Revealing an ID creates an audit entry. A data export arrives within its deadline.

## Rule 19. Accessible to everyone (WCAG 2.2 AA)

- **Rule:** Every screen meets WCAG 2.2 level AA, on desktop and phone, in light and dark mode.
- **Why:** Frontline staff use old phones, bright sunlight, gloves and screen readers. Accessible screens are easier for everyone, and accessibility is often a contract requirement.
- **Do:**
    - **Keyboard:** everything works by keyboard alone, with a visible focus ring and a logical order. Dialogs trap focus and return it on close.
    - **Contrast:** text has at least 4.5:1 (3:1 for large text and UI shapes), checked in both themes. No text below 12 px.
    - **Touch:** targets are at least 44 × 44 px on a phone, and nothing depends on hover.
    - **Real elements:** buttons, links, inputs and labels, with `aria-label` on icon-only buttons, plus `aria-sort`, `aria-busy` (rule 3) and `aria-expanded` where they apply.
    - **More than color:** meaning is never carried by color alone (add text, icons or patterns), and every chart has a text summary and a "Show data" table (rule 9).
    - **Reflow and motion:** pages reflow to 320 px wide and 200% zoom without losing content, and motion respects "reduce motion".
- **Don't:** use placeholder text as a label, put text in images, or set time limits that can't be extended.
- **Reference (FBS People):** Gaps 23 and 24 in `docs/ui-redesign/NEXT.md` (the "Show data" tables, `aria-sort`, and the 9–11 px styles) are still open. Design profile D5 sets the control sizes.
- **Check:** An automated accessibility scan (axe or Lighthouse) shows no serious issues on any page. A keyboard-only run of each top task (rule 8) succeeds, and screen-reader spot checks pass.

## Rule 20. AI agents act safely

- **Rule:** AI agents work as the person who asked, inside that person's tenant and permissions. Anything that changes data becomes a proposal a person approves. Every run is switchable, budgeted, traced and testable.
- **Why:** An agent with broad access can leak data across tenants, act on a misunderstanding, or run up costs. Clear limits let people trust it with real work.
- **Do:**
    - **Autonomy levels per agent:** answer only, report, propose, or act. Tenant agents never act on their own; changes become approval cards ("Nothing is filed until you approve").
    - **Permissions:** tools run with the asker's permissions and tenant scope (rule 7). Platform agents never read inside a tenant.
    - **Off switches,** checked before every run: the global switch and API key, then the platform, each agent, each tenant, and each role or person.
    - **Budgets:** credits per plan and per tenant, a platform spending cap, and visible usage (rule 7).
    - **Traces:** keep every run's prompt, the tools it called, its answer, model, cost and outcome, for a set period. Opening one is logged.
    - **Evals:** test each agent against seeded demo users and run the evals before any model or prompt change.
    - **Data:** send the model only what the task needs, and no other tenant's data, ever.
- **Don't:** let an agent send messages, move money or delete records without a person's approval, or hide that a reply came from AI.
- **Reference (FBS People):** `app/Ai` (runner, triggers, credits), `config/ai.php`, `tests/Evals`, `docs/subscription/REQUIREMENTS.md` §13, and the console's AI & agents pages on the canvas.
- **Check:** An agent asked to change data returns a proposal, not a change. A turned-off agent doesn't run. Evals pass before a model change.

## Rule 21. Dates, time zones and money are exact

- **Rule:** Times are stored in UTC and shown in the tenant's time zone. Dates that matter to people (days, cutoffs, holidays) are stored as dates. Money is exact, with its currency. Every weekday, total and conversion shown is computed, never typed.
- **Why:** Payroll, attendance and billing break on off-by-one days, the wrong time zone or rounding. People notice a wrong weekday or a payslip that doesn't add up immediately, and stop trusting the numbers.
- **Do:**
    - **Store:** instants in UTC; a tenant time zone per tenant (and per branch when they differ); business days and periods as plain dates.
    - **Show:** times in the viewer's tenant time zone with the zone named when it could be ambiguous, and dates with a computed weekday ("Fri, Oct 30").
    - **Money:** stored as decimal or minor units with an ISO currency, never floats. Round once, at the defined step (per line or per total), and document which.
    - **Locale formats:** ₱1,209.60 and Oct 30, 2026 for the Philippines.
    - **Tested calendars:** holiday, cutoff, payday and leave-day calculations are tested for weekends, holidays, month ends, leap years and time-zone edges.
    - **Relative dates** ("in 2 days") are always computed from the tenant's "today".
- **Don't:** do date math in the browser's time zone for tenant data, store money as a float, or hard-code a weekday next to a date.
- **Reference (FBS People):** `config/app.php` already sets `timezone` to `UTC`. Each company has a `timezone` (set by `App\Actions\CreateCompany`), but about 16 places repeat the fallback `?? 'Asia/Manila'`; read it through one helper on `Company`. The pay calendar comes from company settings (gap 26 in `docs/ui-redesign/NEXT.md`). `docs/ui-redesign/DATASET.md` shows how a payslip has to add up.
- **Check:** Tests cover a cutoff across a month end and a holiday. A payslip's lines add up to its total. Every date on a page has the right weekday.

## Rule 22. Definition of done

- **Rule:** A feature is done only when it is designed, built, tested, accessible, documented and deployed behind a flag or to everyone, and its rules are checked. "Works on my machine" is not done.
- **Why:** One shared definition stops half-finished work from shipping and makes reviews quick: everyone checks the same list.
- **Done means:**
    1. **Designed:** it follows the design profile, with its tabs, dialogs and states; a new pattern is approved by the owner (rule 16).
    2. **Built:** it follows every rule that applies (at least rules 1–5, 37, 38 and 41) and the design profile.
    3. **Feature tests:** every endpoint, including tenant isolation (rule 7), permissions, validation, and the happy path.
    4. **Unit tests** for the rules and calculations (rule 21).
    5. **A browser smoke test** of the top tasks (rule 8).
    6. **Accessibility** checked (rule 19), and **privacy** checked (rule 18).
    7. **Performance** within budget (rule 24).
    8. **Docs:** the module README (rule 17), the requirement notes, and a What's new post if users see it.
    9. **Shipped:** committed on `master`, deployed with `php artisan deploy` through the rolling deploy (rule 23), with a feature flag if it's risky, and monitored (rule 28).
- **Where checks run:** inside the Docker image build (rule 49). A hosted CI such as GitHub Actions is not the gate: the deploy never waits for it, so it can't stop a broken build.
- **Don't:** merge without tests, skip the design profile for UI work, or leave a known gap unrecorded.
- **Reference (FBS People):**
    - **Tests:** Pest in `tests/Feature` and `tests/Unit`. AI agent evals are JSON golden sets in `tests/Evals`, run with `php artisan ai:eval all`.
    - **Branch:** commits go straight to `master` ([git-workflow.md](git-workflow.md)).
    - **Still to do:** the `deploy/` scripts run no checks yet. `.github/workflows/tests.yml` (which only triggers on `main`, not `master`) and `.github/workflows/evals.yml` still exist and should go, and `docs/subscription/REQUIREMENTS.md` §13.16 still describes the evals workflow.
- **Check:** Each change's description ticks this list, or names what's missing and where it's tracked.

## Rule 23. Safe deploys and migrations

- **Rule:** Every deploy can go out at any time without downtime, and can be undone. Database changes never break the version that is still running.
- **Why:** With many tenants, every outage hits all of them at once, and a bad migration can corrupt everyone's data. Small, reversible changes keep releases boring.
- **Do:**
    - **Migrations in two steps:** add new columns or tables first (nullable or with defaults), switch the code to them, backfill in a queued job, and only remove the old ones in a later release.
    - **Back up and rehearse:** back up before a migration that changes data, and test it on a copy of production-sized data.
    - **Behind flags:** ship risky features dark behind feature flags (the console's Feature flags page), roll out per tenant, and turn off instead of rolling back.
    - **Restart cleanly:** long-running workers (Octane, queues) restart on deploy, and jobs tolerate both the old and the new code during the switch.
    - **Health checks** gate traffic to the new version, and a one-step rollback to the previous image is always ready.
    - **Plan big changes** in a maintenance window with notice (the console's Maintenance settings).
- **Don't:** rename or drop a column in the same release that stops using it, run a long table rewrite inside a request, or deploy on a Friday evening without someone watching.
- **Reference (FBS People):** Deploys go through Coolify (Octane on FrankenPHP) with the deployer resource and `deploy/rolling-deploy.sh`, which drains and replaces one web container at a time; the shared runbook is [../deployment/publish-and-rollout.md](../deployment/publish-and-rollout.md). Feature flags and maintenance are drawn on the console canvas.
- **Check:** Deploy during a load test: no failed requests. Roll back to the previous version in one step.

## Rule 24. Performance budgets

- **Rule:** Every page and endpoint stays within a budget, measured on a mid-range phone on a 4G connection, and a change that breaks the budget is fixed before it ships.
- **Why:** Frontline staff use cheap phones on mobile data, and a slow app gets abandoned. Budgets catch slowdowns while they are still cheap to fix.
- **Budgets:**
    - **Pages:** the shell is interactive within 2.5 s on 4G (1 s on desktop), and largest contentful paint is under 2.5 s.
    - **Bundles:** the JavaScript for any one route is under 200 KB compressed.
    - **API:** median response time under 150 ms, and 95% of responses under 300 ms for lists and records. Lookups for async selects return within 200 ms.
    - **Pages of data:** at most 100 rows per page (25 by default), and never an unbounded list.
    - **Charts with a row per person** (owner, Nov 3): a grid, roster, calendar or bar list with one row or name per person is never drawn for everyone. It is searchable and filterable, and either paged on the server (a person grid follows its list's search, filters and pages) or capped (a top N, or a few names a day with "+N more"). Wrap a board panel in `PagedPanel` (`resources/js/components/kit3/table/paged-panel.tsx`).
- **Do:**
    - **Queries:** eager-load relations (no queries in loops), index every filter and sort column (tenant id first), and select only the needed columns.
    - **Heavy work goes to queues:** exports, imports, PDFs, emails and AI.
    - **Caching:** cache reference data per tenant, and clear it on change.
    - **Long-running workers** (Octane) keep no request data in static properties or singletons between requests.
    - **Images:** served in modern formats and sizes through the CDN.
- **Don't:** load a whole table to filter it in PHP or JavaScript, or call third parties during a page request when a queue would do.
- **Reference (FBS People):** Octane runs on FrankenPHP, and `v1\BaseController` paginates with allow-listed sorts. Budgets are checked with Lighthouse and the API timings in the console's Health page.
- **Check:** A Lighthouse run on a phone profile meets the budgets for the top pages. A query log shows no repeated queries in loops. With 200 people in a branch, every per-person chart shows at most one page or its cap, and its search and filters narrow it.

## Rule 25. Notifications that help, not nag

- **Rule:** Every notification is worth interrupting someone for, reaches them on the channel they chose, opens straight to the thing it's about, and is sent once.
- **Why:** Too many alerts teach people to ignore all of them, including the important ones. A few useful, actionable alerts make the product feel responsive.
- **Do:**
    - **One notification service** for every channel: in-app bell, web push (rule 26), email, SMS and chat apps (Slack, Teams). Each event is defined once with its audience, urgency and channels.
    - **Preferences per person:** per event and per channel, with sensible defaults. Tenant admins may require some events (approvals, payroll), but never marketing.
    - **Quiet hours and digests:** non-urgent alerts wait outside working hours or roll into a daily digest. Urgent ones (approvals blocking a cutoff, security) go through.
    - **Deep links:** every notification opens the exact record with its action ready (rule 8). Push and email links work signed out and return to the record after sign-in.
    - **Once only:** each event is sent once (an idempotency key per event and recipient), grouped when several arrive together, and marked read everywhere once read anywhere.
    - **No personal data** on lock screens or in SMS beyond what's needed ("You have 1 request to review").
- **Don't:** notify someone about their own action, send the same alert on every channel at once by default, or send without an unsubscribe for optional emails.
- **Reference (FBS People):** `docs/core-hr/REQUIREMENTS.md` §7.32 (Notifications), the bell, Settings › Notifications (`/settings/notifications`) and `SendChatAlert` for chat apps.
- **Check:** Approving a request sends one push to the right person, and the push opens that request. Changing a preference stops that channel.

## Rule 26. Always a PWA with push, phone first

- **Rule:** Every application ships as an installable Progressive Web App and uses push notifications on phones and desktops. Screens are designed for a phone first, then grow to desktop.
- **Why:** Frontline staff live on their phones. A PWA installs from the browser to the home screen without app stores, updates instantly, and sends push alerts like a native app, from one codebase.
- **Do:**
    - **Installable:**
        - a web app manifest with name, icons (including maskable icons), theme color, start URL and display mode `standalone`
        - a service worker
        - HTTPS everywhere
        - an install prompt shown at a useful moment, with a guide for iPhone, where installing is "Share › Add to Home Screen"
    - **Push:** web push with VAPID keys kept in the console (rule 12). Ask for permission only after a clear action ("Get alerts for approvals"), never on first load. Respect rule 25's preferences and quiet hours, and drop subscriptions that expire.
    - **Phone first:**
        - design every screen at 390 px first
        - thumb-reach primary actions and a bottom tab bar
        - 44 px targets (rule 19)
        - no hover-only actions
        - tables turn into cards
    - **Poor connections:**
        - cache the app shell and static assets for fast start
        - show a clear offline banner
        - say exactly what does and doesn't work offline; for example, kiosk punches aren't recorded without a connection, so the kiosk tells people to use the logbook
        - never let an action fail silently
    - **Updates:** the service worker updates in the background and asks to reload once a new version is ready, never mid-form.
- **Don't:** cache personal data or API responses in the service worker without a reason and an expiry, or show the browser's permission prompt with no context.
- **Reference (FBS People):** `public/manifest.webmanifest`, `public/sw.js`, the icons in `public/images`, the `InstallGuide` component (`components/install-app.tsx`), and `docs/core-hr/REQUIREMENTS.md` §7.33 (PWA install and push). Phone boards are on the canvas. The VAPID keys still come from `.env` through `config/services.php` and move to the console under rule 12.
- **Check:** Lighthouse marks the app installable. It installs on Android and on iPhone. A test push arrives on both with the app closed and opens the right screen.

## Rule 27. Import and export without fear

- **Rule:** People can bring their data in and take it out without help. Imports show exactly what will change before anything is saved, and can be undone. Every list can be exported.
- **Why:** Moving from spreadsheets is the first thing every new tenant does, and a bad import is the fastest way to lose them. Easy export is a promise that their data is theirs (rule 18).
- **Do:**
    - **One import centre** for every record type, ordered by dependency (branches before people), each with a downloadable template that lists the valid values.
    - **Match columns automatically** ("Emp No", "Date Hired") and remember the matching for next time.
    - **Review before saving:** every row marked New, Update or Error, with errors fixable in the grid and missing references created in one click ("Create Cebu").
    - **Confirm, then run in the background:** "118 new, 14 updated, 2 skipped". Nothing is saved before confirming, and a progress toast and the bell report the end.
    - **Undo:** download skipped rows, and undo a whole batch within 24 hours. Keep a history of who imported what.
    - **Export:** every list exports what it shows (CSV or Excel), large exports run in the background with a link, and exports respect permissions and are logged (rule 18).
- **Don't:** let one bad row block a whole file without saying which row and why, or import silently over existing records.
- **Reference (FBS People):** Company › Data › Import, the blueprint's import centre (`docs/ui-redesign/blueprint.html`), and the import dialogs on the canvas.
- **Check:** Import a file with one bad row: the rest import, the bad row is explained, and the batch undoes cleanly.

## Rule 28. Errors are clear and the system is observable

- **Rule:** When something fails, the person sees what happened and what to do next, in plain words, and the team can trace exactly what went wrong, without exposing personal data.
- **Why:** Vague errors create support tickets and lost work. Without traces, problems are found by customers first.
- **Do:**
    - **Error states that keep work:**
        - errors show next to the field or in the container (rule 3), and keep what the person typed
        - they say what to do ("Check your connection and try again", "Ask HR to add your branch")
        - 403, 404, expired-link and offline pages offer a way back
    - **A request id** on every request, carried into logs, jobs and error pages ("Error code 7F3A"), so support can find it.
    - **Error tracking** groups errors by cause with releases and tenant ids, and strips personal data (rule 18). Details are visible only to platform developers.
    - **Structured logs** with levels and context, health checks for every dependency, and alerts on error spikes, failed jobs, slow queues and failed backups.
    - **A public status page,** with incidents posted from the console and named affected services.
- **Don't:** show stack traces, SQL or internal messages to users; swallow exceptions silently; or log passwords, tokens or personal data.
- **Reference (FBS People):** Logs & errors, Queues & jobs, Health and the status update dialog are on the console canvas. Production errors stay generic (rule 5). Still to build: a request id on every request (no middleware sets one yet) and health checks per dependency (`/up` is the only one).
- **Check:** Break a dependency in staging: the page explains what happened and the request id leads to the trace. The health page and status page both show it.

## Rule 29. Sign-in and account security

- **Rule:** Sign-in is strong by default and stronger for people with more access. Everyone can use a passkey or two-step sign-in, admins must, and a tenant on a plan that offers it can require SSO. People can see and end their own sessions.
- **Why:** Most breaches start with a stolen or guessed password. HR and payroll data make every admin account a target, and one taken-over owner account exposes a whole company.
- **Do:**
    - **Ways in:** a password with two-step sign-in (an authenticator app), passkeys, and, on plans that offer it, SSO (SAML or OIDC) with SCIM to add and remove people.
    - **Required by role:** owners, admins and platform staff turn on two-step sign-in or a passkey before they reach admin pages. A tenant can require it for everyone.
    - **Brute force:** throttle sign-in per account and per IP, and slow down or lock after repeated failures, with a notice to the person.
    - **Sessions:**
        - an idle timeout set per tenant
        - a list of signed-in devices with "Sign out everywhere else"
        - every session ended on a password change, a role removal or deactivation
    - **Recovery:** reset links expire and work once, and recovery codes are shown once. Changing the email, password or two-step settings asks for the password again and emails the person.
    - **Tenant rules:** the owner sets an IP allow-list and the allowed sign-in methods.
    - **Log it:** sign-ins, failures, lockouts and security changes go to the audit log (rule 31).
- **Don't:**
    - Say whether an email has an account ("Wrong email or password", never "No such user").
    - Let admins use SMS as their only second step.
    - Keep a person signed in after they are deactivated or removed from a tenant.
- **Reference (FBS People):**
    - **Fortify:** two-step sign-in, passkeys and the `login` throttle (`config/fortify.php`). Passkeys are managed on Settings › Security (`components/manage-passkeys.tsx`).
    - **SSO:** SAML at `sso/{slug}` (`SamlController`) and SCIM (`ScimController`), behind the Enterprise Security feature.
    - **Tenant rules:** `EnforceCompanySecurity` applies each company's IP allow-list, two-step sign-in for admins, and idle timeout.
    - **Still to build:** the signed-in devices list with "Sign out everywhere else".
- **Check:** An admin without two-step sign-in can't reach admin pages. Repeated wrong passwords slow or lock the account. Deactivating a person ends their open sessions at once.

## Rule 30. Roles and permissions

- **Rule:** Access comes from roles made of named permissions, plus relationships (my own record, my team, my branch). Each person gets the least access their job needs, and every check runs on the server.
- **Why:** Ad-hoc checks ("is admin?") spread through the code and miss cases. Named permissions and relationship rules can be listed, reviewed and tested, and an owner can see exactly who can do what.
- **Do:**
    - **Permissions are verbs on areas:** `people.view`, `people.manage`, `payroll.run`. Code checks permissions, never role names. The one exception is the owner role, which code may name to keep the last owner (see "Guard the edges").
    - **Default roles** cover the common jobs (owner, HR, payroll, manager, employee, auditor). Tenants copy and edit them into custom roles.
    - **Relationships add access, not roles:** a manager sees their direct reports because of the reporting line, read only unless a permission gives more. A branch-scoped role sees only its branches.
    - **Least privilege:** new people get the employee role. Only an owner grants admin roles, and where the plan counts admin seats, each admin takes one.
    - **Show it:** a permission matrix (roles by permissions) the owner can read and edit, and a "Who can do this?" list per permission.
    - **Guard the edges:** a tenant always keeps at least one owner, and nobody grants more than they hold.
    - **Log every grant and removal** in the audit log (rule 31).
- **Don't:**
    - Check a role name in code (`if role == 'HR Admin'`), except the owner guard.
    - Treat a hidden menu item as access control (rule 5).
    - Let a custom role include permissions that only platform staff hold.
- **Reference (FBS People):**
    - **Package:** `spatie/laravel-permission`, with teams on `company_id` (`config/permission.php`).
    - **Default roles:** `App\Support\DefaultRoles` (Owner, Billing Admin, HR Admin, Payroll & Benefits, Recruiter, Auditor, Branch HR, Manager, Employee) and its custom-role permissions.
    - **Relationships:** in the policies, for example `EmployeePolicy` (a manager sees direct reports, read only).
    - **Screens:** owners edit roles at `company/roles`, and admin roles take seats from the plan's admin limit. The canvas draws the matrix (`matrix`, rule 9).
    - **Platform staff** use `PlatformRole` instead (rule 7).
- **Check:** A manager opens a direct report's record but not a peer's. A custom role without the payroll permission gets 403 from the payroll endpoints. Removing the last owner is refused.

## Rule 31. One audit log

- **Rule:** Every change and every sensitive read goes into one append-only audit log, with the same entry shape everywhere. Tenant owners read and export their own log; platform staff have their own.
- **Why:** Rules 5, 7, 12, 15, 18 and 20 all need a record of who did what. One log with one shape answers "what happened?" for support, security and the law, and nobody can change it afterwards.
- **Do:**
    - **One entry shape:**
        - who: a person, a platform staff member, or an AI agent and the person it acted for
        - what: an action name such as `employee.updated`, and the record it touched
        - when (UTC), from where (IP, device) and the request id (rule 28)
        - the reason, when one was asked for
    - **Names, not values:** record which fields changed, never the sensitive values themselves (rule 18).
    - **Append-only:** nothing in the app updates or deletes an entry. Each tenant keeps entries for a set period (at least what the law asks), then a scheduled job removes them.
    - **Write it with the change,** in the same transaction or from the same event, so a change can't happen without its entry.
    - **Tenant view:** owners and auditors search it by person, action, record and dates, and export it as CSV or JSON lines.
    - **Platform view:** staff actions (entering a tenant, rotating keys, money actions) go to the platform log, and a visit inside a tenant also shows in that tenant's log (rule 7).
    - **Stream it:** on plans that offer it, tenants send entries live to their own SIEM through webhooks (rule 32).
- **Don't:**
    - Keep a second, module-only history that disagrees with the log.
    - Put passwords, tokens, pay amounts or government IDs in an entry.
    - Let anyone, platform staff included, edit or delete an entry by hand.
- **Reference (FBS People):**
    - **The log:** `App\Models\AuditEvent` (`app_audit_events`, written with `AuditEvent::record()`). It has no `updated_at`, keeps field names and reasons in `meta`, and emits `audit.recorded` to webhook endpoints.
    - **Export and retention:** owners download `audit.csv` and `audit.jsonl` and set retention under `company/security` (Enterprise Security).
    - **Still to build:** the Company › Data › Audit log page (gap 19 in `docs/ui-redesign/NEXT.md`), and the request id on each entry (rule 28).
- **Check:** Changing an employee's pay adds one entry that names the field, not the amount. No route edits or deletes an entry. A console visit to a tenant shows in that tenant's log.

## Rule 32. Integrations: API, webhooks and AI clients

- **Rule:** Other systems connect through the public API, outbound webhooks and the AI tool server, each with scoped credentials the tenant controls. Every call and delivery is signed, logged and retryable, and an outside failure never blocks the work that caused it.
- **Why:** Tenants connect payroll, accounting, chat and AI tools. A loose integration leaks data or drops events without anyone noticing; a strict, visible one is something customers can build on.
- **Do:**
    - **Credentials:** the tenant owner makes API tokens and OAuth clients. They are scoped, shown once, stored hashed, expiring and revocable (rule 5), and each shows when it was last used.
    - **Outbound webhooks:**
        - The tenant registers an HTTPS endpoint and picks its events. Each endpoint has its own signing secret, shown once.
        - Sign every delivery with an HMAC of a timestamp and the raw body, and tell receivers to reject old timestamps.
        - Send from a queue with retries and backoff, record every attempt (status, response code, time), and let the owner retry a failed one.
        - Refuse private, loopback and reserved addresses, checked again on every send. Don't follow redirects, and keep timeouts short.
        - Payloads carry public ids, only the fields the event needs, and a version.
        - Turn off an endpoint after repeated failures and tell the owner.
    - **AI tool servers (MCP):** OAuth sign-in, the asker's scopes and permissions (rule 20), and the same audit log (rule 31).
    - **Inbound webhooks** (payment gateways, chat apps): verify the signature, store each event once by its id (rule 7), answer fast, and do the work in a queue.
    - **Document it:** an API reference, the event catalog with sample payloads, and how to verify a signature.
- **Don't:**
    - Send a webhook from inside the request that made the change.
    - Let a failing endpoint stop a hire, an approval or a payroll lock.
    - Send more personal data than the event needs.
- **Reference (FBS People):**
    - **API:** `/api/v1` with `api.token` bearer tokens (`app_access_tokens`, rule 5).
    - **Outbound webhooks:** `App\Webhooks\Webhooks` signs with `X-FBS-Signature: t=…,v1=…` and refuses unsafe URLs (`unsafe()`). `App\Jobs\DeliverWebhook` sends them (6 tries, no redirects, 10 s timeout) and logs each in `WebhookDelivery`. Owners manage endpoints, see deliveries and retry at `company/integrations` (`IntegrationController`).
    - **MCP:** `McpController` at `/mcp`, with OAuth through `OauthController`. It always lists and answers `whoami` (the company as tenant: `tenant_ref` = company id), even with the tools switched off, so the FBS Suites gateway can verify a pasted personal token; every tool carries `annotations.readOnlyHint` from `Tool::readOnly()` (false for proposals and memory writes).
    - **FBS Suites hub:** `Company::created` calls `App\Support\SuiteHub::signedUp()`, which queues `SendSuiteHubEvent` after commit: a signed `tenant.signed_up` v1 POST to `{FBS_HUB_URL}/hooks/people` (`services.fbs_hub`; no URL or secret = nothing sent; 6 tries on `[60, 300, 1800, 7200, 43200]`; a redirect is never followed and only the hub's JSON 2xx with `success: true` counts as sent), the contact read in the job (the company's Owner). Test Drive sandboxes never send (`Sandbox::start` runs inside `SuiteHub::quietly()`, and the job skips a company with `sandbox_expires_at`), nor do companies made from the console.
    - **Console:** failing tenant webhooks show on the console canvas.
- **Check:** A delivery's signature verifies with the endpoint's secret. A dead endpoint retries, then shows as failed with Retry, and the hire that raised it is still saved. An endpoint pointing at `127.0.0.1` or `169.254.169.254` is refused.

## Rule 33. Background jobs are safe to retry

- **Rule:** Every background job carries its tenant, can run twice without harm, retries with backoff, and is seen when it fails.
- **Why:** Queues retry, workers restart mid-job on deploy (rule 23), and schedules overlap. A job that isn't idempotent sends two emails or charges twice; a job that forgets its tenant writes into the wrong one.
- **Do:**
    - **Carry the tenant:** pass the tenant id and record ids (not whole models that go stale), and enter that tenant before doing anything (rule 7).
    - **Idempotent:** check the state before acting, use idempotency keys for outside calls (payments, notifications under rule 25), and mark the work done in the same transaction.
    - **Retries:** set tries, backoff and a timeout per job, with short timeouts on outside calls. Jobs that must not overlap are unique or run without overlapping.
    - **Failures:** a final failure is recorded with the tenant and request id (rule 28) and shows on the console's queues page. A spike alerts the platform, and the person who started the job (an import, an export) is told.
    - **Long work** reports progress (a toast or the bell, rule 27) and can be restarted safely.
    - **Schedules:** per-tenant work runs in the tenant's time zone (rule 21), and daily runs are safe to run twice (rule 15).
    - **Separate queues** for slow work (AI, exports), so it never holds up fast work (notifications, webhooks).
- **Don't:**
    - Rely on the current tenant or signed-in person being set inside a job.
    - Catch an exception in a job so it "succeeds" (rule 28).
    - Call a third party in a loop with no timeout.
- **Reference (FBS People):**
    - **Jobs:** `app/Jobs`, on the `database` queue. They enter the tenant with `Company::run($companyId, …)` (for example `DeliverWebhook` and `AnswerChatMessage`) and set `$tries` and `$timeout`.
    - **Queues:** AI jobs run on their own queue (`config('ai.queue')`).
    - **Schedules:** `subscriptions:sweep` runs `withoutOverlapping()` (`routes/console.php`).
    - **Failures:** failed jobs show on the console's Queues & jobs page (`Console\SystemController`, `failed_jobs`).
    - **Still to do:** alerts on failure spikes.
- **Check:** Run each job twice with the same input: the second run changes nothing. Stop a worker mid-job: the retry finishes the work once. A failed job shows on the console with its tenant.

## Rule 34. The URL holds the view

- **Rule:** What a person is looking at (search, filters, sort, page, tab, and the open record or sheet) lives in the URL. Copying the link, reloading or pressing Back shows the same view.
- **Why:** People share links to "the late list for Davao" and expect it to open as they saw it. Notifications link straight into records (rules 8 and 25). A Back button that loses the filters makes people redo their work.
- **Do:**
    - Put list state in the query string, with the same parameter names as the API (rule 5) and only allow-listed fields.
    - Put the active tab in the path or query, and an open sheet as `?open=<public id>`.
    - Replace the history entry as people type a search; add a new entry for a new page, tab or record, so Back steps through views, not keystrokes.
    - Leave default values out of the URL to keep links short.
    - Restore a remembered view (rule 8) only when the URL has no state of its own. The URL wins.
    - Keep personal data out of the URL: ids, never names, emails or government IDs (rule 18).
- **Don't:**
    - Keep filters only in component state or local storage.
    - Open a record in a sheet that has no URL of its own.
- **Reference (FBS People):**
    - **Lists:** `App\Support\ListQuery` reads a list's state from the query string (`?search=…&filter[status]=…&sort=-hired_at&per_page=25&page=2`) and ignores any column the page doesn't name.
    - **Records:** approvals open a request with `/approvals?open=leave-12` (rule 8).
    - **Names:** rule 5's names win (`filters[field]`, `sort_by`, `sort_dir`), which `lib/api.ts` and `v1\BaseController` already use. `ListQuery` still reads `filter` and `sort=-field`, and moves to them.
- **Check:** Filter, sort and page a list, open a record, and paste the link into a new window: the same view opens. Back closes the record, then undoes the filter.

## Rule 35. Dependencies are few, pinned and kept current

- **Rule:** Add a dependency only when it saves real work. Pin every version with a lockfile, check for known vulnerabilities on every build, and update on a schedule.
- **Why:** Each package is code you didn't write but must still secure and upgrade. Supply-chain attacks and abandoned packages are common, and a project that skips updates for a year faces one painful jump.
- **Do:**
    - **Before adding one,** check whether the framework or an installed package already does it. Prefer maintained packages with a clear permissive license (MIT, BSD, Apache), and note why it was added.
    - **Pin:** commit the lockfiles and install from them in builds (`composer install`, `npm ci`). Pin base images and build tools to a version, never `latest`.
    - **Scan every build:** `composer audit` and `npm audit --omit=dev --audit-level=high` fail the build on a high or critical issue.
    - **Update monthly:** minor and patch updates in one batch with the tests run. A major version gets its own planned change.
    - **Remove** packages nothing uses any more.
- **Don't:**
    - Add a package for what a few lines or the framework already does.
    - Run `npm install` or `composer update` in a deploy build.
    - Ignore an audit warning without a written reason and an expiry date.
- **Reference (FBS People):** `composer.lock` and `package-lock.json` are committed. The Dockerfile pins the Composer major ([../deployment/docker-image.md](../deployment/docker-image.md)). Still to do: add `composer audit` and `npm audit` to the Coolify build (rule 22).
- **Check:** A fresh build installs exactly the lockfile versions. A package with a known high-severity issue fails the build.

## Rule 36. Write down what was built, shortcuts included

- **Rule:** Every finished phase or module adds an "as built" note next to its requirements: what shipped, where it lives, and every shortcut taken, with its limit and how to lift it.
- **Why:** Requirements say what was wanted and the code says what exists, but neither says what was left out on purpose. Without that note, the next person or AI re-plans settled work, or trusts a feature further than it goes.
- **Do:**
    - **One note per phase,** under the requirement it delivers, titled "Phase N as built" with the month.
    - **Say what shipped:** routes, screens, jobs, settings and tables, with file paths, in a few bullets.
    - **List the shortcuts** under their own heading. Each says what was simplified, what breaks first, and what to do when it matters.
    - **Note deviations** from the requirement or the design, with the reason and who approved them (rule 16).
    - **Keep it current:** a later change that lifts a shortcut edits the note in the same commit.
    - **Track what's left** on the gap list or the requirement's open items, never only in a commit message.
- **Don't:**
    - Mark a requirement done when a shortcut quietly leaves part of it out.
    - Keep the only record of a decision in a chat or a commit message.
- **Reference (FBS People):** `docs/core-hr/REQUIREMENTS.md` (for example §7.32–7.34) and `docs/subscription/REQUIREMENTS.md` (§7.1 and §13.8–13.17) carry "as built" sections. The subscription ones list **Shortcuts**; the core-hr ones don't yet.
- **Check:** For any shipped feature, its "as built" note names its files and its shortcuts, and the code matches the note.

## Rule 37. Nothing jumps: a stable layout

- **Rule:** Content never moves unless the person moved it. Error messages, hints, loading states, images, banners and late data take space reserved for them, so nothing the person is reading, typing in or about to tap shifts or flickers.
- **Why:** A field that jumps when an error appears makes people tap the wrong thing and lose their place, and the app feels broken. Layout shift is also a Core Web Vital (CLS) and hurts most on phones (rule 24).
- **Do:**
    - **Form errors:**
        - Every field keeps one line under its input for a hint or an error, whether or not there is one. The error replaces the hint in that line; it never stacks under it.
        - Keep messages to one line ("Pick a date after the start date").
        - Validate on blur and on submit, not on every keystroke. Clearing an error leaves the line in place.
        - A form-level error shows in a slot that is already there (the dialog footer note) or as a toast, never by pushing the form down.
    - **Busy states:** skeletons match the final size (rule 3), and spinners sit over content, never between it. A button keeps its width when its label turns into a spinner or "Saving…".
    - **Media:** images, avatars, charts and iframes have a set size or aspect ratio before they load.
    - **Late content:** banners and alerts get a reserved slot or float over the page; toasts always float. Nothing is inserted above content that is already showing.
    - **Tables and lists:** column widths stay the same across pages and sorts, numbers use tabular figures, the pager stays put on a short page, and a refetch keeps the old rows on screen until the new ones arrive (rule 3).
    - **Fonts:** preload the main font and use a fallback with matching metrics, so text doesn't reflow when the font arrives.
    - **Scroll containers** use `scrollbar-gutter: stable`, so a scrollbar appearing doesn't push the page sideways.
- **Don't:**
    - Render an error element only when there is an error (`{error && <p>…</p>}`).
    - Insert a banner or message above content after it has rendered.
    - Let a button shrink or grow when its label changes.
    - Flash an empty state or a skeleton between two pages of the same list.
- **Reference (FBS People):** `resources/js/components/input-error.tsx` returns `null` when there is no message, so fields move when an error appears in the 12 files that use it; it has to always render its one-line slot. Field sizes are in design profile D5 and loading states in D9.
- **Check:**
    - Submit an empty form, then fix one field at a time: no field moves.
    - Lighthouse shows CLS under 0.1 on the top pages (rule 24).
    - With the network throttled, a list's rows replace its skeleton in place, and paging doesn't flash.

## Rule 38. One typed client layer: axios and TanStack Query

- **Rule:** Every client-rendered page (rule 1) talks to the server through one shared TypeScript API layer, reused by every module: one axios instance, one reusable resource factory, and TanStack Query for server data. Tables and forms come from the project's one UI kit (rule 6): kit A (shadcn + Radix) uses TanStack Table and zod schemas; kit B (the FBS Learn kit) uses its `CardGridPanel` list kit and `FormField`. No component calls axios or `fetch` itself.
- **Why:** With one layer, the CSRF token, errors, cancelling, retries and caching are solved once, and a new module's API is a few lines instead of a new copy (rule 17). One table and one form pattern per project means a list or form is never rebuilt by hand.
- **Do:**
    - **One axios instance** (`http`):
        - same-origin session cookie and the XSRF token (rule 5), `Accept: application/json` and a timeout
        - one response interceptor that turns every failure into one error type (`ApiError` with `status`, `message`, `errors`, rule 5)
        - 401 sends the person to sign in, 419 refreshes the token and retries once, and 429 waits and retries
    - **One reusable resource factory:** `createCrudResource<T, Input>()` gives each module its list, lookup, create, update, archive, restore and delete calls (plus `show` where a record has its own page), matching the server's base controller (rule 5). A module's API file is a few lines; extra actions sit beside it in the same file.
    - **URLs** come from the generated route helpers (Wayfinder) or the module's one `baseUrl`, never strings scattered through components.
    - **TanStack Query owns server data:**
        - one `QueryClient` with shared defaults (stale time, one retry for reads, no retries for writes)
        - one query-key factory per module (`employees.list(params)`, `employees.show(id)`), so invalidation is exact
        - shared hooks that every module uses; a newer request cancels the one it replaces (rule 2)
        - lists keep the previous page on screen while the next loads (rule 37)
        - a save updates or invalidates the keys it changed, and drives the busy wrapper (rule 3) and the toast
    - **Tables, kit A:** one shared `DataTable` on `@tanstack/react-table`, styled by design profile D6. Search, filters, sort and paging run on the server (`manualPagination`, `manualSorting`, `manualFiltering`) and are kept in the URL (rule 34). Column visibility, row selection for bulk actions (rule 8), `aria-sort` on sortable headers (rule 19), and rows as stacked cards on a phone (D11).
    - **Tables, kit B:** the `CardGridPanel` kit (`ListTable`, `Th`, `useSort`, `GridFooter`) with `TableToolbar` as the one filter bar, fed by `getPaginatedFilterSearch`, the same server-side rules and the same `aria-sort`.
    - **Forms, kit A:** one zod schema per form, with its type from `z.infer`, validating on blur and on submit through the kit's form library ([../structure/frontend-patterns.md](../structure/frontend-patterns.md)).
    - **Forms, kit B:** `FormField` and the kit's inputs, with the server's rules as the validation. Add a zod schema when a form must validate in the browser.
    - **Both kits:** the server's 422 errors land on the same fields, in the same error line (rule 37), and the server still validates everything (rule 5): client validation is for speed and clear messages, not security.
    - **Types end to end:** no `any` at the API boundary. Response types come from the zod schemas or the module's record types.
    - **Bundle size:** heavy table and form code loads only on the pages that use it, so each route stays within rule 24's budget.
- **Don't:**
    - Call `axios`, `fetch` or `XMLHttpRequest` from a component, page or hook outside the API layer.
    - Copy server data into `useState` or a global store; TanStack Query holds it.
    - Write a second client, wrapper or hook for one module.
    - Build a list table by hand on a page. Short fixed tables, such as a payslip's lines, may stay plain.
    - Mix kit A and kit B tables or forms in one project.
    - Treat client validation as the only check.
- **Reference (FBS People):**
    - **The pattern:** [../structure/frontend-patterns.md](../structure/frontend-patterns.md) (`client.ts` with the axios `http` and `ApiError`, `http.ts` with `createCrudResource`), taken from FBS Learn's `resources/js/api-service/`.
    - **Today:**
        - `resources/js/lib/api.ts` is a `fetch` wrapper (`api()`, `query()`, `upload()`) used by 92 files, and lists use `hooks/use-list.ts` and `hooks/use-crud-list.ts`.
        - `components/kit/data-table.tsx` is a hand-built `DataTable` (`Column<T>`) used by 22 files, and 33 pages still render their own `<table>`.
        - Some components still call `fetch()` directly (rule 5).
    - **To meet this rule:**
        1. Add the client libraries that are missing.
        2. Port `client.ts` and `createCrudResource`, and rebuild `lib/api.ts` on them with the same `api()`, `query()` and `upload()` signatures, so its callers keep working.
        3. Move `use-list` and `use-crud-list` onto TanStack Query.
        4. Rebuild `kit/data-table.tsx` on TanStack Table with the same `Column<T>` props, then move the pages' own list tables onto it.
        5. Give each module an API file with its resource, query keys and zod schemas, starting with new modules, then move the direct `fetch()` calls onto it.
    - **Kit B in practice:** FBS Learn's `api-service/client.ts`, `api-service/http.ts` and `components/settings/CardGridPanel.tsx`.
    - **Server-rendered public forms** (rule 1, such as apply) keep Inertia's form helpers and server validation.
- **Check:**
    - A search finds no `fetch(` or `axios.` outside the API layer.
    - A new module's API is one resource call plus its types or schemas, with no new client code.
    - Every list page renders the kit's shared table. Sorting a column sends `sort_by` and `sort_dir` to the server (rule 5) and updates the URL.
    - Send a value only the server rejects: its 422 message shows on the right field.

## Rule 39. File uploads show their progress

- **Rule:** Every file upload shows its progress from the moment a file is chosen or dropped: a progress bar per file with its percent, a way to cancel, and a clear result. Nothing uploads silently, and one failed file never loses the others.
- **Why:** Uploads are the slowest thing people do, often on mobile data. Without progress they tap again, leave the page or think it froze, and files end up doubled or lost.
- **Do:**
    - **One shared upload component and hook** for every file field and drop zone, built on the API layer (rule 38).
    - **Check before sending:**
        - type, size and count, against the same limits the server uses
        - show the limits next to the field ("PDF, JPG or PNG, up to 5 MB")
        - refuse a file with its reason ("scan.pdf is 12 MB; the limit is 5 MB")
    - **Start at once:** the file joins the list as soon as it is chosen or dropped and starts uploading, unless the form says it uploads on save.
    - **Progress per file:**
        - the name, the size and a progress bar with the percent
        - for large files, the amount sent and the time left ("3.2 of 12 MB · about 20 s")
        - several files: one row each, plus a total
        - the bar is a `role="progressbar"` with `aria-valuenow`, so screen readers hear it
    - **Name each phase:** Uploading, then Checking (the server's type check and virus scan, rule 10), then Done or Failed. The bar never sits at 100% without saying what's happening.
    - **Cancel and retry** each file on its own. A failure says why and leaves the other files alone.
    - **Keep working:** the rest of the form stays usable during uploads. Save waits for them ("Waiting for 2 uploads") instead of failing, and leaving the page asks first.
    - **Large files** (over about 10 MB) go straight to storage with a signed upload URL (rule 10), in parts where the storage supports it, so a dropped connection resumes instead of starting over.
    - **Images** show a thumbnail. Large photos, such as receipts from the camera, are shrunk in the browser before sending when the quality allows.
    - **Reserve the space:** the file list and its bars don't push the form around (rule 37).
    - **Finish:** a done file shows its name with View and Remove. Work that continues on the server, such as an import, hands over to the progress toast and the bell (rule 27).
- **Don't:**
    - Upload with `fetch`: it can't report upload progress. Use the axios `onUploadProgress` in the API layer.
    - Show a spinner with no percent for an upload.
    - Clear the form or the other files because one file failed.
    - Rely on the browser's checks alone: the server checks the size, the real type by content, and the scan (rule 10).
- **Reference (FBS People):**
    - **Today:** `upload()` in `resources/js/lib/api.ts` posts with `fetch`, so none of the 21 file inputs shows progress, and `components/ui` has no progress bar.
    - **Ready to use:** the catalog's `createCrudResource` already takes `opts.onUploadProgress(percent)` ([../structure/frontend-patterns.md](../structure/frontend-patterns.md), rule 38).
    - **Limits:** they live in the controllers' validation, for example a 5 MB proof of payment in `Company\BillingController` and a 100 MB zip in `Company\ImportController`. The client checks must use the same values.
    - **Design:** the upload dialogs are on the canvas (rule 11).
- **Check:**
    - Throttle the network and upload a 20 MB file: the bar moves, shows the time left, and Cancel stops it.
    - Upload three files, one too large: that one is refused with its reason, and the other two finish.
    - A screen reader announces the percent as it changes.

## Rule 40. Imports and exports run with a progress toast that survives

- **Rule:** Every import and export runs as a background job and shows a progress toast with a bar, a Cancel button and, when paused, Resume. Progress lives on the server, so a lost connection, a page refresh or a closed tab never loses the work: the toast comes back, and the job carries on from where it stopped.
- **Why:** Importing or exporting a few thousand rows takes minutes, often on unsteady mobile data. When the work is tied to one request, a drop or a refresh loses it or leaves it half done, and people try again and create duplicates.
- **Do:**
    - **The server owns the job:** starting one creates a job record (type, who, tenant, status, total, done, errors, checkpoint, file) and returns its id at once. The statuses are queued, running, paused, cancelling, cancelled, done and failed.
    - **Work in chunks with checkpoints:** process the rows in chunks (about 500), and save each chunk with its checkpoint in the same transaction. A retry or a resume starts after the last finished chunk and never repeats one (rule 33).
    - **The progress toast:**
        - appears when the job starts and stays until it ends, and never closes on its own
        - has a **Minimize** button that folds it into a small pill (name and percent, still a progress bar) and a **Maximize** button that opens it again; the pill sits at the same corner, clear of the phone tab bar and Back to top (rules 37 and 42)
        - remembers whether it was minimized across a refresh and other tabs, and opens itself again when the job fails, pauses or finishes, so the result is never missed
        - shows the name ("Importing employees.csv"), a bar with the percent (`role="progressbar"`), the counts ("1,240 of 3,000 · 12 errors") and the time left
        - offers Cancel while running and Resume while paused; when done, Download for an export, or View results and Undo for an import (rule 27)
        - several jobs stack in one toast, one row each
    - **Progress comes from the server,** by polling every 1–2 seconds or a push channel, never from a counter kept in the page.
    - **Refresh and other tabs:** on every page load, the app asks for the person's running jobs and brings their toasts back at the current percent. Every tab shows the same progress.
    - **When the connection drops:**
        - the server keeps working, and the toast says so ("Offline. The import keeps running on the server.") and catches up when the connection returns
        - the parts that run in the browser resume instead of starting over: the import file uploads in parts (rule 39), and an export downloads from storage with range requests, so a broken download continues
    - **Cancel:**
        - an import asks first and says what stays ("1,240 rows are already saved. You can undo them.")
        - the job stops after its current chunk and leaves a clean state: an import's saved rows undo as one batch (rule 27), and a cancelled export deletes its partial file
    - **Pause and resume:** a job paused by the person, a deploy (rule 23) or an outage resumes from its checkpoint, by Resume or on its own once a worker is back.
    - **Finish:** the toast shows the result ("118 new, 14 updated, 2 skipped"), and the bell keeps it with a link (rule 25). An export's file is a short-lived signed link (rule 10), kept for a set time such as 7 days.
    - **Limits:** one running import per record type per tenant. Exports follow permissions and are logged (rules 18 and 27).
- **Don't:**
    - Run an import or a large export inside a web request.
    - Keep progress only in the browser, where a refresh loses it.
    - Restart a job from the first row after a pause, a drop or a failure.
    - Leave half an import saved with no way to undo it.
- **Reference (FBS People):**
    - **Today:**
        - imports run inside the request: `Company\ImportController@store` saves all good rows in one transaction as an `ImportBatch` (`app_import_batches`), which can be undone within `ImportBatch::UNDO_HOURS`
        - exports stream inside the request (`App\Support\Csv::download`, `ListQuery::csv`)
        - toasts are sonner through `resources/js/lib/toast.ts`, which has no progress toast
        - so a refresh or a dropped connection loses an import or export in progress
    - **To meet this rule:**
        1. Add status, total, done and checkpoint columns to `app_import_batches`, or one shared jobs table for imports and exports, and run both as queued jobs in chunks (rule 33).
        2. Add a progress toast to `lib/toast.ts` and a hook that loads the person's running jobs on every page.
        3. Write finished exports to Spaces under the tenant's prefix and hand them out as signed links (rule 10).
- **Check:**
    - Start a 5,000-row import and refresh the page: the toast comes back at the same percent and keeps going.
    - Minimize the toast to the pill, refresh the page: it stays minimized with the percent still moving. Maximize it: it shows the counts, Cancel and the result again. When the job finishes while minimized, it opens by itself.
    - Turn the network off mid-import, wait, and turn it back on: the toast catches up, and no row is saved twice.
    - Cancel an import halfway: it stops after the current chunk, and Undo removes the rows it saved.
    - Stop the worker mid-export and start it again: the export resumes from its checkpoint and the file is complete.

## Rule 41. Floating layers render in a portal and stay on screen

- **Rule:** Every tooltip, hover card, popover, dropdown, menu, select list, date picker, toast and dialog renders in a portal at the end of `<body>` (React `createPortal`), placed next to its trigger with collision handling. No parent's overflow cuts it, no other layer covers it, and it never runs off the screen.
- **Why:** A floating layer drawn inside its card inherits the card's `overflow: hidden`, transforms and stacking context. It gets clipped, or sits under a sticky header, however high its z-index. Rendering it at the body level and placing it with collision checks fixes the cause, instead of raising z-index until something else breaks.
- **Do:**
    - **Portal every floating layer** to one layer root at the end of `<body>`, created only after the page mounts (rule 1).
    - **Place it dynamically** from the trigger's position, and recompute on scroll, resize and content change:
        - flip to the other side when there's no room (top and bottom, left and right)
        - shift along the edge to stay inside the screen, with an 8 px margin
        - limit its size to the space available and scroll inside it (a long select list)
        - close or hide it when the trigger scrolls out of view
        - keep its arrow, if it has one, pointing at the trigger
    - **One z-index scale,** as tokens, from low to high: sticky headers and the sidebar, then fixed bars and floating buttons (the phone tab bar, the save bar, Back to top), dropdowns and popovers, sheets and dialogs, toasts, tooltips, and the page tour. Components use no other z-index values.
    - **Nested layers:** a menu or select opened inside a dialog or sheet portals too and stacks above it. A click inside a portaled child doesn't close its parent.
    - **Tooltips:**
        - show on hover and on keyboard focus after a short delay (about 300 ms), and hide on Esc and when the pointer leaves
        - on touch, show on long-press; nothing important lives only in a tooltip (rule 19, nothing hover-only)
        - link to the trigger with `aria-describedby`, and never cover the trigger
        - hold one or two lines of text, never buttons or links (use a popover for those)
    - **Popovers and menus:** focus moves into them and returns to the trigger on close. Esc and a click outside close them (rule 19).
    - **Charts:** chart tooltips follow the same rules. They escape the chart's box and stay on screen.
    - **Use the shared primitives:** build every layer on the shared components (a headless library with portals and collision handling), never on `absolute` boxes placed by hand.
- **Don't:**
    - Place a popup with `absolute` inside a card, table or scroll area.
    - Fix clipping by raising z-index (`z-[9999]`) or by removing a parent's `overflow`.
    - Use the native `title` attribute as a tooltip for anything people need: it doesn't show on touch or keyboard focus, and it can't be styled.
    - Render a portal during server rendering.
- **Reference (FBS People):**
    - **Already portaled:** the Radix primitives behind `components/ui/dialog`, `sheet`, `dropdown-menu`, `select` and `tooltip` render in portals with collision handling. `components/help-menu.tsx` portals the page tour to `body` with `createPortal` for the same reason.
    - **Still to move:**
        - `pages/people/helpdesk.tsx:104` draws its panel as an `absolute z-10` box inside the page, where a card's overflow can cut it.
        - Native `title` attributes serve as hover text in places such as the heat map cells in `components/kit/charts.tsx`. Move them to `ui/tooltip`, which only 3 files use today.
        - The Recharts tooltips in `components/kit/charts-rich.tsx` draw inside the chart's box; let them escape it (`allowEscapeViewBox`) or portal them.
        - z-index values are ad hoc (`z-10`, `z-20`, `z-40`, `z-50`, `z-[60]`); replace them with the token scale.
    - **New pickers:** the async select (rule 2) and the date picker are built on the same portaled primitives.
- **Check:**
    - At 390 px and 1440 px, open each tooltip, menu and select near every edge of the screen: each flips or shifts to stay fully visible.
    - Open a select inside a dialog over a scrolling card: its list shows in full, above everything.
    - Tab to an icon button: its tooltip shows on focus and hides on Esc.
    - A search finds no hand-placed `absolute` popups in components and no z-index outside the scale.

## Rule 42. A Back to top button after 20% of the page

- **Rule:** Once a person scrolls past 20% of a long page, a "Back to top" button appears in the bottom corner. One tap takes them back to the top.
- **Why:** On a phone, long lists, reports and settings pages take many swipes to climb back to the tabs, toolbar and filters. One button saves that, and it stays out of the way until it's useful.
- **Do:**
    - **When:** show it once the scrolled distance reaches 20% of the scrollable height, and hide it again above that. Only pages taller than about two screens get it.
    - **What it watches:** the element that really scrolls (the window, or the page's scroll container). A sheet or a long dialog with its own scroll gets its own button inside it.
    - **Where:** bottom right, inside the safe area, above the phone's bottom tab bar, and clear of the unsaved-changes bar, toasts (rule 40) and Ask AI. The page keeps enough bottom padding that the button never covers the last row's action.
    - **What it does:** scrolls smoothly to the top (or jumps when "reduce motion" is on, rule 19), then moves focus to the page's main heading, so keyboard and screen-reader users land at the top too.
    - **Look:** a 44 px round secondary button (rule 19, D5) with an up-arrow icon and `aria-label="Back to top"`, fading in and out. While hidden, it leaves the tab order.
    - **One shared component** for every page, placed in the app shell, with its layer from the z-index scale (rule 41). It never shifts the layout (rule 37).
- **Don't:**
    - Show it on short pages or at the top of the page.
    - Leave the hidden button focusable.
    - Use it instead of a sticky toolbar on a list that needs one.
- **Reference (FBS People):** `BackToTop` in `resources/js/pages/welcome.tsx` already does this on the landing page: it shows after a fifth of the page, has `aria-label="Back to top"`, and leaves the tab order when hidden. Move it into a shared component and add it to the app shell (`components/app-shell.tsx`), above `components/mobile-tab-bar.tsx` (fixed, `z-40`) and clear of the unsaved-changes bar in `components/kit/settings-layout.tsx`.
- **Check:**
    - On a long list at 390 px and 1440 px, scroll down: the button appears at 20% and not before.
    - Tap it: the page returns to the top, and focus lands on the main heading.
    - It never covers the tab bar, the save bar or a toast. With "reduce motion" on, it jumps instead of scrolling.

## Rule 43. Edits never overwrite each other silently

- **Rule:** When two people edit the same record, the second save never quietly replaces the first. The server notices the conflict, and the person sees both versions and chooses.
- **Why:** HR teams work on the same employees, schedules and cutoffs at the same time. "Last save wins" loses someone's change without anyone knowing, and in payroll that becomes a wrong payslip.
- **Do:**
    - **Send a version with every edit:** the record's version number or `updated_at`, loaded with the form and sent back on save.
    - **Check it on the server** in the same update (`where version = ?`). If it changed, answer 409 with the current values and who changed them.
    - **Show the conflict in place:** keep what the person typed, mark each field that changed underneath them with the other value ("Ramona changed this to Davao Store at 2:14 PM"), and offer Keep mine, Use theirs, or merge field by field.
    - **Merge what doesn't overlap:** when the two edits touched different fields, save both without asking.
    - **Lists and bulk actions:** an approval or a bulk change on a record that has moved on (already approved, already locked) is refused with the reason, not applied twice.
    - **Optional presence:** on busy records, show who else has the record open.
- **Don't:**
    - Save a whole record from a stale form without a version check.
    - Clear the person's input when a conflict happens.
- **Reference (FBS People):** Nothing detects conflicts yet: no endpoint returns 409. Add the version check to `update()` in `v1\BaseController` so every module gets it, and send the version from the shared form hook (rule 38).
- **Check:** Open the same employee in two browsers, save a change in each: the second shows the conflict with both values, and nothing is lost.

## Rule 44. Archive before delete

- **Rule:** Records are archived before they are deleted. An archived record leaves the everyday lists but can be found and restored. Deleting for good happens only after it is archived, isn't used by other records, and its retention period allows it.
- **Why:** Deleting the wrong branch, position or employee by mistake is easy, and the records that point to it (payslips, attendance, audit) break. Archiving keeps history intact and makes mistakes reversible.
- **Do:**
    - **Archive first:** an active record can't be deleted. Archive hides it from lists and pickers (rule 2) but keeps it on old records and reports.
    - **Find and restore:** every list has an Archived filter, and archived records can be restored with one action and logged (rule 31).
    - **Delete only when safe:** a record that other records use can't be deleted, and the message names what uses it ("Used by 14 employees").
    - **Undo:** archiving shows Undo in the toast (rule 8).
    - **Retention:** records the law requires to be kept can't be deleted until their retention ends (rule 18). Personal data past retention is anonymised by a scheduled job.
    - **Same model everywhere:** one status pattern (active, archived) and one restore action, from the shared base controller (rule 5).
- **Don't:**
    - Hard-delete an active record from a list row.
    - Delete a record that history points to.
    - Build a separate "trash" for one module.
- **Reference (FBS People):**
    - **Built:** `v1\BaseController` blocks deleting a record in an undeletable status (`$requireInactiveBeforeDelete`, `$undeletableStatuses`, active by default) and a record still in use (`$inUseRelations`). KPI templates and policies have archive routes.
    - **Still to do:** deletes are permanent (no model uses `SoftDeletes`), so there is no restore for records. Add an Archived filter and a restore action to the base controller and the shared `DataTable` (rule 38). Files already have a recycle bin (rule 11).
- **Check:** Try to delete an active branch: it's refused until it's archived. Archive it: it leaves the list and the pickers but still shows on old records. Restore it from the Archived filter.

## Rule 45. Forms never lose work

- **Rule:** Nothing a person types is lost to a closed tab, a dropped connection, an expired session or a stray click. Long forms save drafts as people type, and leaving with unsaved changes asks first.
- **Why:** HR forms are long (a new hire, a payroll adjustment, a review), and they're often filled in between other work. Losing ten minutes of typing to a timeout is the fastest way to make people hate a system.
- **Do:**
    - **Warn before leaving:** navigating away or closing a dialog or sheet with unsaved changes asks first, in the app's own modal (rule 60). A form with no changes closes without asking. Closing the tab doesn't ask (the browser's box is banned, rule 60); the draft keeps the work.
    - **Show what's unsaved:** a bar or footer note counts the unsaved changes ("3 unsaved changes") with Save and Discard (rule 8, "Saved as a draft as you type").
    - **Drafts:** long forms save a draft as people type, to the server where the record needs one, otherwise to the browser, and offer to restore it next time ("Restore your draft from 2:14 PM?").
    - **Session expiry:** warn a few minutes before an idle timeout (rule 29) with "Stay signed in". If the session ends anyway, keep the draft, ask the person to sign in again, and return them to the form with their input.
    - **Offline:** a save while offline keeps the input and retries, or says clearly that it wasn't saved (rule 26). It never fails silently.
    - **Errors keep input:** a failed save shows the error in place and keeps every value (rules 3 and 37).
- **Don't:**
    - Clear a form after a failed save.
    - Ask "Leave page?" when nothing changed.
    - Keep drafts with sensitive values (pay, IDs) in browser storage; those drafts live on the server or not at all (rule 18).
- **Reference (FBS People):** `components/kit/settings-layout.tsx` already shows an unsaved-changes bar on settings pages. Still to do: the same guard on every form, dialog and sheet; drafts for long forms; and a warning before the idle timeout that `EnforceCompanySecurity` applies.
- **Check:** Type into a long form and click another page: the app's modal asks first. Close the tab and reopen the form: the draft is offered. Let the session expire mid-form and sign in again: the form comes back with the input.

## Rule 46. Changes that affect pay are effective-dated

- **Rule:** Pay, position, branch, manager, schedule and benefit changes take an effective date and are kept as history, never overwritten. Every calculation reads the value that was in effect on the day it calculates for.
- **Why:** Raises are backdated, transfers start next month, and corrections arrive after payroll. If a change overwrites the old value, past payslips can't be explained or recomputed, and a backdated raise is either missed or applied twice.
- **Do:**
    - **Store each change as a dated row:** the value, its effective date, who made it, why, and the approval (rule 47). The current value is simply the latest row in effect today.
    - **Future-dated changes** wait and take effect on their date; the record shows "Changes on Dec 1" until then.
    - **Backdated changes** into a locked pay period create an adjustment in the next open period, with the difference shown, instead of reopening the locked one.
    - **Read by date:** payroll, attendance and reports ask "what was in effect on this date" (rule 21), never "what is it now".
    - **Show the timeline:** each employee's record has a history of these changes in order (rule 9, `tl`).
    - **Corrections** add a new row that replaces the wrong one from its date, with a reason. The wrong row stays in the history, marked as corrected.
- **Don't:**
    - Update a salary, position or manager in place.
    - Recompute a locked payslip with today's values.
- **Reference (FBS People):** `effective_on` already dates `app_pay_records`, `app_employee_movements`, `app_benefit_enrollments`, `app_merit_cycles` and `app_policy_versions`. New fields that affect pay or reporting lines follow the same pattern.
- **Check:** Backdate a raise into a locked cutoff: the next open cutoff shows an adjustment for the difference, and the locked payslip is unchanged. A report for last March shows last March's positions.

## Rule 47. One approval engine

- **Rule:** Every request that needs a decision (leave, overtime, time corrections, claims, offers, refunds, plan changes) goes through one approval engine. It decides who approves, in which order, who stands in when someone is away, and what happens when a request waits too long.
- **Why:** Approval rules are where companies differ most, and where one-off code breeds bugs: a request nobody can approve, or one approved by the wrong person. One engine means one place to configure, test and audit.
- **Do:**
    - **Steps from rules, not code:** each request type has steps (manager, then HR above an amount, then finance) set per tenant in settings, with conditions such as amount, days, branch or type.
    - **Delegation:** an approver away on leave hands their approvals to a named person for set dates, and the request shows "Approving for Anton Cruz".
    - **Overrides:** a role such as HR may decide at any step, and the record says so.
    - **Deadlines:** each step has a due time. Reminders go out before it (rule 25), and overdue requests escalate to the next approver or HR.
    - **One inbox:** every approver sees all their requests in one approvals inbox with bulk approve (rule 8, D8).
    - **Every decision is logged** with who, when, the step and the note (rule 31). A decision on a request that already moved on is refused (rule 43).
    - **Reusable:** the engine knows nothing about leave or claims. A request type plugs in with its fields, its amount and what happens when it is approved (rule 17).
- **Don't:**
    - Hard-code who approves a request type.
    - Build a second approval flow for a new module.
- **Reference (FBS People):** `App\Approvals\Chain` decides leave and time requests: the manager or their delegate (`ApprovalDelegation`), then HR when the company's `ApprovalRule` asks for it at that size, with HR able to decide at any step. Rules are set in `ApprovalSettingsController`, and requests are decided in `ApprovalsController`. Still to do: claims, offers and refunds (rule 15) on the same engine, and escalation of overdue steps.
- **Check:** Set a rule "HR approves leave over 3 days": a 4-day request goes manager then HR, a 2-day one stops at the manager. With the manager on leave and a delegate set, the delegate approves.

## Rule 48. Documents and PDFs come from one template engine

- **Rule:** Every document the product produces (payslips, certificates of employment, contracts, offer letters, invoices, reports) is made from a template by one document service, as a PDF whose numbers match the screen exactly, stored and shared like any other file.
- **Why:** Documents leave the system: employees show payslips to banks, and invoices go to accountants. A document that disagrees with the screen, or looks different every time, breaks trust and can be a legal problem.
- **Do:**
    - **Templates in data:** each document type has a template the tenant can brand (logo, signatory, footer) with named fields (`{employee.name}`, `{today}`), filled in from the record.
    - **Same numbers as the screen:** the PDF is built from the same calculation the page shows (rule 21), never recomputed separately.
    - **Made on the server,** in a queue for batches (all payslips for a cutoff, rule 33), as a real PDF with selectable text and the right page size (A4 or Letter per tenant).
    - **Frozen once issued:** an issued payslip or invoice is stored as a file (rule 10) and never regenerated with newer data. A correction issues a new version (rules 15 and 46).
    - **Shared safely:** opened through signed links and logged (rules 10 and 18). Documents with pay or IDs can be password-protected when emailed.
    - **Accessible:** documents have a title, a language and real text, and tables have headers (rule 19).
    - **Print view:** pages people print (a roster, a report) also have a print stylesheet, but anything official is a PDF.
- **Don't:**
    - Rely on the browser's print dialog for official documents.
    - Regenerate an issued document with today's data.
    - Copy the document layout into each module.
- **Reference (FBS People):** `App\Models\DocumentTemplate` fills hiring documents (contract, NDA, privacy consent) with `{fields}` from the record. The one PDF library is `barryvdh/laravel-dompdf` (owner, Nov 2, 2026): signed hiring documents are issued once by `App\Signatures\SignedPdf` from `resources/views/pdf/signed-document.blade.php`, stored in the 201 file with their SHA-256, and never regenerated (B4). Every other document shares one letterhead and page: `App\Documents\Letterhead` with `resources/views/pdf/partials/` (owner, Nov 7, 2026). The Certificate of Employment is made once when HR (or the Offboarding agent) issues it without a signed scan, from the record that day, and stored as the COE's file, never remade (`App\Documents\CoeLetter`). The hiring packet (blank forms to print and sign) and the training bond (the assignment's stored, agreed terms) are made fresh each time and never stored (`App\Documents\HiringPacket`, `App\Documents\BondAgreement`). An invoice is made once right after it is issued (`App\Documents\InvoicePdf`, `app_invoices.pdf_path`) and carries no payment state. No document is printed through the browser. Still to do: payslips and certificates on the same library, a tenant's letterhead and page size (A4 or Letter), and batches in a queue.
- **Check:** A payslip PDF matches the payslip page to the centavo. Changing the employee's pay afterwards doesn't change the issued PDF. A batch of 500 payslips runs in the background with a progress toast (rule 40).

## Rule 49. Static checks and tests gate every build, inside the Docker build

- **Rule:** Every image build runs the formatter check, the linters, the type checkers, the tests and the dependency audit, inside the Docker build, before the image can be deployed. A build that fails any of them doesn't deploy.
- **Why:** Checks that only run when someone remembers don't run, and a hosted CI the deploy doesn't wait for can't stop a broken release. Running them in the image build ties the check to the exact code that ships, at no extra cost to anyone's attention.
- **Do:**
    - **Run in the build stage** of the `Dockerfile`, before the final stage is assembled: formatting check, lint, type checks (strict TypeScript, Larastan level 7), unit and feature tests, and `composer audit` plus `npm audit --omit=dev --audit-level=high` (rule 35). The build stage installs dev dependencies to run them; the final stage still gets only `composer install --no-dev` output ([../deployment/docker-image.md](../deployment/docker-image.md)).
    - **One command** runs them all, `composer ci:check`, the same on a laptop and in the build, so a green run locally means a green build ([../quality/testing-and-ci.md](../quality/testing-and-ci.md)).
    - **Test on your PC, not on the server.** Run `composer ci:check` before each commit, then `php artisan deploy`, which builds the image, and so runs the gate, on your PC. Don't use a server build (Coolify's Redeploy of the app) to find out whether the tests pass: it takes CPU and memory from the live apps on the same VPS, and Coolify puts the app's production variables into the build ([docker-image.md](../deployment/docker-image.md)). A server build still runs the gate as the fallback (rule 67), but it should only ever see code that already passed on a PC.
    - **Fail closed:** any failure stops the deploy and the running version stays live. A check can be skipped only in code, with a comment saying why and a date to remove it.
    - **Keep it fast:** run checks in parallel and keep the whole run under about 10 minutes, so nobody is tempted to skip it.
    - **Raise the bar over time:**
        - A new project starts with no baseline file of any kind.
        - An existing project may keep baseline files (`phpstan-baseline.neon`, `max-lines-baseline.json`, `php-max-lines-baseline.json`) for errors that existed when the check was added. They only shrink: a new error fails the build, and a fixed entry is deleted in the same commit.
        - No `@phpstan-ignore` comments: fix the type at its cause.
    - **AI evals** run when agents, prompts or models change (rule 20), where the API key is available.
- **Don't:**
    - Deploy when a check fails.
    - Turn off a check, or add a baseline entry, to make a build pass.
    - Treat GitHub Actions (or any hosted CI) as the gate. A workflow may stay for quick feedback on a push, but the deploy never depends on it, and it watches the deploy branch (`master`).
- **Reference (FBS People):** `composer ci:check` already runs everything: `npm run check` (`vp check`), `npm run types:check` (`tsc --noEmit`, strict), Pint (`--test`), PHPStan with Larastan, and `php artisan test`. PHPStan runs at level 7 with `phpstan-baseline.neon` holding the 3,489 errors that existed on Oct 31, 2026 (owner decision): the baseline only shrinks. Still to do: call it from the image build, add `composer audit` and `npm audit` (rule 35), and remove the GitHub workflows (rule 22).
- **Also see (FBS CRM):** the most complete gate today. `composer ci:check` runs `vp lint` (0 warnings), `npm audit --omit=dev`, `composer audit`, `tsc --noEmit`, then Pint `--test`, Larastan level 7 with no baseline file, and Pest.
- **Known differences today:**
    - No project calls `ci:check` from its image build yet. FBS Learn's build stage runs only its demo checks.
    - FBS CRM, Connect, Pay, People, FCA-SMS and FBS Suites run GitHub Actions; People's and FBS Suites' workflows trigger on `main` while they deploy from `master`.
    - FBS Suites' `ci:check` calls PHPStan, which it doesn't install. FBS Learn's and FCA-SMS's `ci:check` call npm scripts (`lint:check`, `format:check`) that don't exist.
    - FBS Stock and FBS Work have no `ci:check` yet: `composer test` runs only their tests, with no Larastan and no JS linter (Work also has `npm run types:check`).
- **Check:** Push a commit with a type error and run `php artisan deploy`: the image build fails and the running version stays live. A clean commit deploys.

## Rule 50. Each project keeps its files under one root folder

- **Rule:** Everything a project writes to object storage lives under one root folder, named by a single setting: `AWS_BUCKET_FOLDER` in `.env`. Each tenant's files live inside that folder, in the tenant's own folder. Nothing is written outside it.
- **Why:** One DigitalOcean Spaces bucket can serve several products (FBS People, FBS Learn, FBS Pay) and environments. A root folder per project keeps their files apart, makes one project's data easy to find, back up, move or delete, and lets a project move to another bucket or folder by changing one setting, not code.
- **Do:**
    - **One setting:** read the root folder once, from `AWS_BUCKET_FOLDER`, set per project and per environment (`laravel-fbs-people`, `laravel-fbs-people-staging`). It isn't a secret, so it stays in `.env` (rule 12).
    - **Build every key in one place** from the root folder:
        - `{root}/uploads/companies/{tenant id}/…` for each tenant's private files
        - `{root}/public/companies/{tenant id}/…` for public images such as logos
        - `{root}/uploads/platform/…` for files that belong to no tenant
        - `{root}/build/…` for the app's built files
        - backups in the same layout (rule 11)
    - **One folder per tenant,** created with the tenant and removed only under the retention rules (rules 11 and 18). A folder with no tenant record is flagged as orphaned on the console's Files page and reviewed before it is deleted.
    - **Limit keys** to the project's bucket, and to its root folder where the provider allows it (rule 12).
    - **Show the root folder** on the console's Storage pages, so staff know where a project's files are.
- **Don't:**
    - Hard-code a bucket path, or write a key outside the root folder.
    - Share one root folder between two projects or two environments.
    - Change the root folder of a live project without moving its files first: copy them, switch the setting, check, then delete the old folder.
- **Reference (FBS People):**
    - **The setting:** `.env.example` sets `AWS_BUCKET_FOLDER=laravel-fbs-people`.
    - **The disks:** `config/filesystems.php` puts the private `uploads` disk at `{AWS_BUCKET_FOLDER}/uploads` and `uploads_public` at `{AWS_BUCKET_FOLDER}/public`.
    - **Tenant folders:** `App\Traits\HandlesFileUploads` stores each company's files under `companies/{id}/`, or `platform/` when no company is current.
    - **Built files:** `deploy/coolify-deploy.sh` uploads them to `{AWS_URL}/{AWS_BUCKET_FOLDER}/build` (`config/app.php`).
    - **Design:** Console › Storage › Files on the canvas browses the root folder by company.
- **Check:** Every object this project writes starts with `{AWS_BUCKET_FOLDER}/`. A company's private files are all under `uploads/companies/{id}/`. Changing the setting in staging sends new files to the new folder only.

## Rule 51. Every component is reusable, and no file is over 300 lines

- **Rule:** Build every component, hook, helper and class so another screen or project can use it as it is, and keep every source file to 300 code lines or fewer. When a file grows past 300, split it. The build enforces the limit.
- **Why:** A short file is read, reviewed and tested in one sitting. A component that takes its data and options as props can be used again; one that is tied to a single page gets copied and edited, and the copies drift apart (rule 17). A 2,000-line page hides bugs, slows every review and can't be reused.
- **Do:**
    - **Props in, events out.** A component gets everything it needs through props, reports changes through callbacks, and reads no page, route, tenant or global state itself. It names what it is, not where it is used (`DataTable`, not `EmployeesTable`).
    - **One job per file.** A page file only wires things together: it fetches data (rule 38), lays out sections and passes props. The sections, tables, forms and dialogs it uses are their own components.
    - **Split by purpose, not by line count:** the data hook, the schema (rule 38), the columns, the dialog and each tab of a page are separate files in the page's folder.
    - **Style through design tokens and props** (variants, sizes), never by copying the component and changing it (rules 16 and 17).
    - **Backend too:** controllers stay thin. Rules, calculations and queries live in their own action, service or query classes; validation in request classes or the base controller's rule hooks; shape in resources.
    - **Count code lines** in each source file: TypeScript, TSX, PHP, CSS and Blade. Blank lines and comments don't count. Generated files (route helpers, migrations, lockfiles) and data files (fixtures, translations) are exempt; say so at the top of the file.
    - **Enforce it in the build (rule 49):**
        - JavaScript and TypeScript: the linter's `max-lines` rule at 300, blank lines and comments skipped.
        - PHP: a Pest test that counts code lines the same way in `app`, `routes` and `database` (FBS CRM's test counts migrations too, which is fine: none needs 300 lines).
        - Files already over when the check is added go into one baseline file per language with their current size (`max-lines-baseline.json`, `php-max-lines-baseline.json`). A listed file may not grow, and its entry is deleted once it is split to 300 or less (the check fails while a split file is still listed). A new project starts with both baselines empty.
- **Don't:**
    - Add to a file that is already near 300 lines. Split it first, in its own commit (rule 57).
    - Pass a whole page's state down through many levels. Use a small context for a real shared concern, or lift the data into a hook.
    - Turn the line limit off for a file to get a build through, or list a file as an `override` with the rule off. An exception needs a baseline entry with its size, or a comment with the reason and a date to remove it.
    - Copy a component into a new folder to change it.
- **Reference (FBS People):** The limit isn't enforced yet, and the code is far over it: 80 of 580 files in `resources/js` and 15 of 360 in `app` pass 300 lines (not counting generated route helpers). The largest is `resources/js/pages/people/recruitment.tsx` at over 2,100 lines. The 3.0 screens (`resources/js/screens`, `components/kit3`) start under the limit; old pages are split or replaced as each hub moves to 3.0 (`tasks/plan.md`).
- **Also see (FBS CRM):** the model to copy. `vite.config.ts` sets `max-lines` (300, blank lines and comments skipped) with per-file caps from `max-lines-baseline.json`; `tests/Unit/PhpFileSizeTest.php` checks `app`, `routes` and `database` against `php-max-lines-baseline.json`, which has been empty since 2026-11-04.
- **Known differences today:** FBS Pay lists its over-limit files as lint `overrides` with the rule turned off and has no PHP check; move them to a size-capped baseline. People, Connect, Learn, FCA-SMS, Stock, Work and FBS Suites don't enforce the limit yet.
- **Check:** The build fails when a file outside the baselines passes 300 code lines, or a listed file grows. Each component works when given only its props, in a test. A new page file is mostly imports and layout.

## Rule 52. Every page and tab has its own route

- **Rule:** Every page, and every tab and sub-tab on it, has its own URL. The menu, the tabs and a typed address all go to the same route, so a link, a reload, the Back button and a bookmark open exactly the place the person was in.
- **Why:** Tabs held only in component state can't be linked to, bookmarked or reached from a notification (rules 8 and 25), they reset on reload, and they can't be permission-checked or measured on their own. A route per place also keeps each page small (rule 51) and lets the server check access before anything loads (rules 5 and 30).
- **Do:**
    - **Pages follow the menu:** one URL per page, in the order hub, tab, sub-tab, for example `/people/employees`, `/time/attendance/roster`, `/me/pay/salary-history`. Use lower-case words with hyphens, and public ids for records (`/people/employees/{id}`).
    - **Tabs are routes, not state.** A tab link is a real link (`<a href>`) to its route; opening it loads that tab's data. The active tab comes from the URL.
    - **Records open on a route too:** a full page at `/people/employees/{id}`, and a side sheet at `?open={id}` on its list (rules 8 and 34). Its own tabs are routes under it (`/people/employees/{id}/pay`).
    - **One name per route,** and one generated helper for it (rule 38). Components link through the helper, never a hand-written path.
    - **The server guards each route** with its permission and plan check (rules 7 and 30), so a person without access gets the 403 page (rule 28) for that tab only, and a plan-locked tab opens read-only with the plan that unlocks it (rule 63).
    - **Old addresses keep working:** when a route moves, the old one redirects with a 301.
    - **Title, breadcrumb and menu state come from the route,** so the active hub, tab and sub-tab are highlighted from the address alone.
    - **State inside a page** (filters, sort, page, an open sheet) stays in the query string (rule 34); it never replaces a tab.
    - **List the routes** in one place per hub, with the page component, the permission and the plan that each needs.
- **Don't:**
    - Switch tabs with local state only (`useState('tab')`) or a hash, so the address doesn't change.
    - Put a tab's content in a different tab's route, or load every tab's data on the first tab.
    - Build a URL from strings in a component.
    - Change a route without a redirect from the old one.
- **Reference (FBS People):** Pages already have routes in `routes/web.php` and `routes/settings.php` (for example `me/pay`, `me/claims`, `people/announcements`, `team/performance`, `kpi/templates`), shared through Wayfinder. The 3.0 sub-tabs (for example Time › Attendance › Roster, Company › Security › Custom domain) are not all separate routes yet, and `pages/console/ai.tsx` keeps its tab in `?tab=`, which should become a route. The 3.0 route table is built with each hub (`tasks/todo.md`, phases 2, 3 and 5).
- **Check:** For each tab in the menu, copy its address into a new window: the same page and tab open, highlighted. Reload, then press Back: nothing resets. A person without the permission gets the 403 page for that tab only. A search finds no `useState` that holds an active tab.

## Rule 53. Filters take one record, several, or any

- **Rule:** Every filter on a list lets the person pick one value, several values, or Any (no filter). Filters that pick records use the same search-as-you-type picker as forms, so a filter works the same with 5 options or 5,000.
- **Why:** People narrow a list to "Cebu and Davao", or to three managers, as often as to one thing. A filter that takes only one value, or that has no way back to "everything", makes them filter one at a time or lose the full list.
- **Do:**
    - **Pick any number:** a filter chip opens a list with a checkbox per option. Nothing ticked is **Any**, and Any is also a button that clears the filter.
    - **Show what is picked:** the chip names one choice ("Branch: Davao Store") or counts several ("Branch: 2"), and turns to the accent color while it filters.
    - **Fixed lists and records:** a short fixed list (status, type) is checkboxes. A filter over records (people, branches, departments, positions) is the async select in multiple mode (rule 2), with tokens and Clear.
    - **One rule for matching:** several values in one filter mean "this or that" (Cebu or Davao); different filters together mean "and" (Cebu or Davao, and Regular).
    - **In the address and the API:** a filter is `filters[branch]=a,b` in the URL (rule 34) and in the list call (rule 5). The server splits the list, checks each value against the allow-list, and scopes to the tenant. A value the person may not see is ignored.
    - **Clear** removes every filter at once, and each chip can be cleared on its own.
    - **Counts and empty state:** the footer shows the filtered total ("1–25 of 39"). A filter that matches nothing says so and offers Clear.
- **Don't:**
    - Build a filter that takes one value only, or one with no Any.
    - Use a different filter control for one list.
    - Load every record into a filter dropdown (rule 2).
- **Reference (FBS People):** `components/kit3/table/toolbar.tsx` is the shared filter bar. `url-state.ts` and `lib/api/sample-collection.ts` read comma-separated values. The old lists' filters take one value and are replaced as each hub moves to 3.0 (`tasks/plan.md`). `ListQuery` and `v1\BaseController::paginationSearch()` must accept comma-separated values (phase 4, task 4.1).
- **Check:** On any list, tick two options of one filter: rows from both show, the chip counts 2, and the address holds both. Untick them (or press Any): the full list returns. Combine two filters: only rows matching both show.

---

## Rule 54. Bars and strips never show a scrollbar

- **Rule:** Tab bars, sub-tab pills, filter chip rows, toolbars and other strips never show a scrollbar of their own, and never scroll up or down. The page scrolls, not the strip.
- **Why:** A scrollbar on a 44px strip looks broken, steals height, and moves the layout (rule 37). It usually appears by accident: `overflow-x: auto` makes the other axis scroll too when an item pokes out by 1px, such as an active-tab underline.
- **Do:**
    - **Fit first:** on desktop the strip fits. If it cannot, wrap it onto a second line, or fold the extras into a "More" menu.
    - **Phone:** when a strip must slide sideways, swipe with the scrollbar hidden (`no-scrollbar`), `overflow-y: hidden`, and a faded edge to show there is more.
    - **Keep the underline inside:** an active-tab underline sits inside the strip's height, not outside it.
- **Don't:**
    - Leave `overflow-x: auto` alone on a strip: always pair it with `overflow-y-hidden`.
    - Show a native scrollbar, vertical or horizontal, on a tab bar, chip row or toolbar.
    - Let a strip scroll vertically.
- **Reference (FBS People):** `.no-scrollbar` in `resources/css/ui3.css` hides it. `kit3/shell/hub-tabs.tsx` uses it for tabs and sub-tabs.
- **Check:** Open any page at desktop and phone width. No scrollbar or scroll arrows show on the tab bar, sub-tabs or toolbar, and the mouse wheel over them scrolls the page.

## Rule 55. Every plan cites these rules and breaks none

- **Rule:** Every plan, task list, spec and review names the rules from this file that apply to it, and nothing in it may break one. A step that would break a rule is changed, or the rule is changed in this repo first with the owner's approval (rules 16 and 66).
- **Why:** Rules that are read only after the code is written get found broken in review, when fixing them costs the most. Naming them while planning puts each rule's checks into the work from the start, and an AI assistant that plans against the file can't drift from it.
- **Do:**
    - **Read this file before planning,** every time, including the design profile (D1–D12) for any UI work, and the docs in this repo that the task touches (the [README](../README.md) says which).
    - **Plan from the current design profile:** UI plans name the design they build from: the design profile, the app's own D12 and any approved pictures. No Claude Design or canvas check is needed (owner, Nov 6, 2026).
    - **Every phase names its rules:** a "Rules" line under each phase or module lists the rule numbers it must meet, and each task adds the ones specific to it ([../docs-and-ai/docs-and-specs.md](../docs-and-ai/docs-and-specs.md) has the plan template).
    - **Acceptance criteria come from the rules' Check lines:** a task is not done until the checks of its rules pass (rule 22).
    - **Conflicts stop the plan:** when a requirement, a design or a request would break a rule, say so, propose a fix that keeps the rule, and wait for the owner before going on.
    - **Reviews cite rule numbers** for every finding ("breaks rule 52: tab kept in `useState`").
    - **New rule, same day:** when a rule is added or changed, update the open plans that it touches.
- **Don't:**
    - Write a plan or task with no rules named.
    - Ship a known break of a rule as a shortcut without the owner's approval and a note (rule 36).
    - Treat a rule as optional because a deadline is near.
- **Reference (FBS People):** the design source is the design profile and the approved pictures in `docs/design-system/` (rule 6); the canvas in `docs/ui-redesign/canvas` is an archive. `tasks/plan.md` lists the rules for each phase, and `tasks/todo.md` names them per task. `CLAUDE.md` tells every AI session to read the rules before planning, building or reviewing, and that plans cannot break them.
- **Check:** Each phase and task in the open plan names its rules and the design it builds from. A review of a finished task finds no break of the rules it named, and any break found elsewhere has an owner-approved note.

## Rule 56. Long AI work stops at 93% of the usage limit

- **Rule:** When an AI assistant works through phases or a task list on its own, it checks the plan's usage limit between tasks and stops once the 5-hour limit reaches 93%. Only the 5-hour limit counts; the weekly limit is not a stop. It commits as it goes but does not push after each commit: the one push happens at that 93% stop (or when the owner asks for it). Before stopping it leaves the work safe: the current task finished or set aside cleanly, committed, pushed, and the resume point written down.
- **Why:** Running into the hard limit cuts the work off mid-change, leaving uncommitted edits, a broken build or a task half ticked. Stopping at 93% keeps a margin to finish cleanly, and the next session starts from a known point instead of guessing.
- **Do:**
    - **Check usage** before starting each task, and again during a long task (after each large step).
    - **At 93% or more:** start no new task. Finish the current one if it is small; otherwise bring it to a state that builds and passes the checks, or set the unfinished edits aside (`git stash`) with a note.
    - **Push once, at the stop:** commit after each task or part, but keep the commits local. Push when usage reaches 93% of the 5-hour limit, or when the owner asks, so the remote gets one tidy push instead of one per commit.
    - **Leave it safe:** run the checks (rule 49), commit with a message that says what is done and what is not, and push (this is the one push).
    - **Write the resume point** in the project's task list (FBS People uses `tasks/todo.md`): the last finished task, what is half done, and the next step.
    - **Report** to the owner: what was finished, what is left, and the usage it stopped at.
    - **Spend the budget on the work, not the waiting:**
        - Run a dev server with hot reload instead of rebuilding to check each change.
        - Check pages with one script against the dev server, not one iframe at a time in a hidden pane.
        - Format before counting lines, so long files get split on the first pass (rule 51).
        - Check whether a file already exists before writing a new one.
- **Don't:**
    - Start a new task above 93%.
    - Stop with uncommitted work, a failing build, or a task ticked that isn't done.
    - Keep going past 93% because a task is "almost done" when it is not small.
- **Reference (FBS People):** the assistant reads the 5-hour limit with the app's usage tool (`get_usage`) between tasks. Commits go straight to master and stay local until the 93% stop, when they are pushed together; the resume point goes in `tasks/todo.md` and the session memory.
- **Check:** After a long run, the last commit is pushed, the build passes, `tasks/todo.md` names the next step, and the run stopped at or below about 93% of the 5-hour limit.

## Rule 57. Clean up what a feature touches before adding the feature

- **Rule:** Before adding a feature, tidy the code it will touch: fix what is already broken, split what is over its size limit, remove what is dead, and give the code the types and names it lacks. Then add the feature on the clean base, in its own commit.
- **Why:** A feature built on a mess adds to the mess: the next change is slower, the review hides the real change among old problems, and the shortcut you work around today becomes the bug someone else finds. Cleaning first, in a separate commit, keeps both diffs small and lets a reviewer check each on its own.
- **Do:**
    - **Look first:** open every file the feature will change and the ones that call it. List what is wrong there: failing or missing tests, an over-long file (rule 51), duplicated code that a shared piece could replace (rule 17), dead or commented-out code, vague names, missing types, lint and static-analysis warnings, a stale comment.
    - **Clean what the feature touches, not the whole repo.** If the clean-up is bigger than the feature, stop and tell the owner, with the list; do not grow the task silently.
    - **Clean in its own commit** ("Tidy X before adding Y"), with the checks passing (rule 49) and no change in behaviour. Then add the feature in the next commit.
    - **Pay down the baseline:** when the feature touches a file with static-analysis baseline entries, fix them by typing the code and let the baseline shrink; never let a file's count grow (rule 49).
    - **Record it:** the task's "Done" note (rule 36) says what was cleaned, and what was left and why.
    - **Fix what you find on the way** if it is small and in the touched files; write down what is bigger as its own task.
- **Don't:**
    - Mix the clean-up and the feature in one commit, so neither can be reviewed or reverted alone.
    - Rewrite code the feature does not touch, or change behaviour while tidying.
    - Add a feature around a known problem and note "fix later".
    - Suppress a warning or add a baseline entry to get past one the feature exposed.
- **Reference (FBS People):** the "Before every task" checklist at the top of `tasks/todo.md` has the step. `composer ci:check` is the gate for both commits (rule 49).
- **Check:** For any feature, `git log` shows a tidy commit before the feature commit (or the Done note says nothing needed tidying). Every file the feature changed is under 300 lines, passes the linters and types, and has no baseline entry it did not have before.

---

## Rule 58. Frontend first, then its backend

- **Rule:** Build a module's frontend, following the design profile and any approved pictures, before writing its backend. Shape the frontend's data layer (the resource, its fields, its endpoints) as if the real backend already existed, on sample data, so the switch to real data later is a swap, not a rewrite. Only once every screen a module needs is built does that module's backend get built, in full.
- **Why:** Building the screens first, before a backend exists to compromise them, is the surest way to ship the design the owner wants. Backend work done per screen, interleaved with frontend work, tends to shape the frontend around whatever the backend made easy instead of the other way around, and leaves both half-done for longer. Finishing a module's frontend first also surfaces every field, state and action the backend must support before a line of backend code is written, so the backend is built once, to a known shape.
- **Do:**
    - **Match the design first:** before marking a screen done, check it against the design profile, the app's D12 and any approved pictures: every panel, label, field, state and action the module needs is there. An app with no approved pictures uses the owner's approved screen as "the design".
    - **Design the real shape on paper, build on sample data:** name the endpoint, its request and response shape, and which permission gates it, in a comment or a short data-layer file, then implement the screen against sample data in that exact shape (rule 38's client layer and `createCrudResource`).
    - **Finish the module's frontend** (every tab, state, dialog and action it needs) before starting its backend.
    - **Then build the whole backend in one pass:** the endpoints, permissions, validation and tests the frontend was already shaped for, swapping the sample adapter for the real one.
- **Don't:**
    - Build a backend endpoint for one screen, then shape that screen's frontend around what the endpoint happens to return.
    - Leave a screen visibly unfinished while moving on to its backend.
    - Guess a backend shape with no frontend already built against it; the frontend is what proves the shape works.
- **Applies to:** new modules. Modules built earlier with backend and frontend together need no rework because of this rule (FBS CRM's note); they follow it the next time a module is rebuilt.
- **Reference (FBS People):** this reorders phase 5 of `tasks/plan.md`: finish and re-check every hub's frontend first, then build each hub's backend in full. There is no Claude Design or canvas step (owner, Nov 6, 2026). `docs/ui-redesign/NEXT.md` and `tasks/todo.md` record which stage a hub is in.
- **Check:** Every screen of a module is built and follows the design before its backend work starts. No task's Done note shows backend work for a screen whose frontend isn't finished.

---

## Rule 59. *(Retired 2026-11-06)* One UI from the Claude Design canvas

- **Retired:** 2026-11-06, by the owner, when the rules were moved into this repo. The Claude Design canvas is no longer a required design source or step for any project; the design profile, each app's D12 and its approved pictures are the source (rules 6, 16 and 58). The number stays so that older plans and reviews citing "rule 59" still resolve. Never reuse it.
- **Known differences today:** FBS CRM's and FBS Pay's copies of the rules still name the canvas in rules 58 and 59. They drop that wording when they move to linking this repo (rule 66).

---

## Rule 60. No browser dialogs: every question is the app's own modal

- **Rule:** The product never shows the browser's own dialogs: no `alert()`, `confirm()`, `prompt()`, and no "Leave site?" box on closing the tab. A question that needs an answer before something happens is asked in the app's own modal, in the D8 dialog frame.
- **Why:** The browser's boxes say "localhost:8000 says", can't be styled, themed or translated, ignore dark mode and the design, block the whole tab, and look like a scam warning to the people who use HR software least often. The owner banned them on Oct 31, 2026.
- **Do:**
    - **Ask in the app:** `askConfirm({ title, description, confirmLabel, destructive })` returns a promise; it renders the D8 confirmation (`ConfirmDialog`) from one host mounted at the app root, so any page, old or new, can use it.
    - **Say what happens:** the title is the question ("Delete this webhook?"), the description names what changes, and the button names the action ("Delete webhook"), never "OK" (D8, rule 8).
    - **Unsaved changes:** closing a dialog, a sheet or moving to another page with unsaved changes asks with `askDiscard()` (rule 45). Closing the tab asks nothing; the form's draft keeps the work and offers it back next time (rule 45).
    - **Messages are toasts or inline errors,** never `alert()` (rules 28 and 37).
- **Don't:**
    - Call `window.alert`, `window.confirm` or `window.prompt`, or the bare `alert`, `confirm` or `prompt`.
    - Add a `beforeunload` handler that makes the browser ask.
- **Reference (FBS People):** `components/kit3/confirm-host.tsx` (`askConfirm`, `askDiscard`, `ConfirmHost` in `app.tsx`). `FormFrame` and `useUnsavedGuard` use it, and every 2.x page that called `window.confirm` was moved to it on Oct 31. The older `components/kit/confirm-dialog.tsx` (`useConfirm`) is an in-app modal too and may stay until its pages move to 3.0. The PWA install prompt (`lib/install.ts`) is the browser's install sheet, opened only when the person taps Install, and is allowed.
- **Also:** kit B uses `useConfirm` with `components/settings/ConfirmDialog.tsx` (FBS Learn); FBS Suites uses `components/fbs/confirm-dialog.tsx` (`ConfirmDialog`, `tone="danger"` for destructive actions).
- **Check:** A search of `resources/js` for `confirm(`, `alert(`, `prompt(` outside `askConfirm`/`useConfirm`, and for `beforeunload`, finds nothing. Close a dialog with unsaved changes: the app's modal asks, in the current theme.

---

## Rule 61. A dialog over a dialog keeps one dim and sits above it

- **Rule:** When a confirmation or any dialog opens over an open dialog or sheet, it reuses the backdrop that is already there: the page gets no second, darker layer. The sheet or dialog underneath takes the same dim as the page, so it reads as background, and the new dialog sits one step higher in the stack, so it is clearly the thing to answer first.
- **Why:** Two backdrops stacked on each other blacked out the page and hid the form being closed, so people could not see what they were about to discard. The owner asked for this on Nov 1, 2026, from the "Close New role?" confirmation over the New role sheet, and on the same day asked for the dialog underneath to take the page's dim ("Close Export directory?" over Export directory).
- **Do:**
    - Use `DialogContent` (`components/ui/dialog.tsx`), which checks for an open overlay when it opens: if there is one, its own overlay is transparent, both the overlay and the dialog use `--z-stacked`, and the open dialog or sheet underneath gets `data-dimmed` (`brightness(.55)`, the same as the 45% backdrop) until it closes.
    - Keep the z-index scale in `resources/css/ui3.css` (rule 41): `--z-dialog` 40, `--z-stacked` 44, `--z-nested` 45 (so a dropdown inside a stacked dialog still shows above it).
- **Don't:**
    - Give a nested dialog its own `bg-black/…` overlay, or a z-index outside the scale.
- **Reference (FBS People):** `askConfirm` and `askDiscard` (rule 60) render `ConfirmDialog`, which uses `DialogContent`, so every confirmation over a sheet or dialog follows this rule.
- **Check:** Open a dialog or sheet, type something and close it: the "Close …?" confirmation shows above it, the sheet underneath is dimmed like the page, and the page behind is no darker than it was with the sheet alone.

## Rule 62. A card's content fills the card

- **Rule:** What a card shows uses the card's full width and height. Cards in a row are as tall as the tallest, and each one's chart, bars or rows grow or spread to fill it, so no card has an empty band under its content. A big gap in a card is a defect, never tolerated: fill it, or change the layout so it is gone.
- **Why:** Cards in a row take the height of the tallest. A short chart in a tall card left white space under it, and a short card left a gap under itself. The owner asked for this on Nov 1, 2026, from the People overview (By type, Joiners and leavers, Hiring funnel, By branch) and Me › Settings.
- **Do:**
    - A card fills its grid cell: a grid item stretches by default, so put the card itself in the grid, or give a wrapper's card `h-full` (the board grid does this with `[&>section]:h-full`).
    - `Panel` is a flex column, so its chart takes what is left under the title with `flex-1`.
    - Charts grow with the card: bar lists and funnels spread their rows (`justify-around`), column charts stretch their columns, and a donut is centred in the space left, with its unit under the number inside the ring.
    - Plan the row so its cards hold about the same amount (rule 4); if one is much shorter, give it more to show or a narrower span, never padding.
- **Don't:**
    - Give a chart a fixed small height inside a card that is taller.
    - Leave a caption on its own line under a chart when it belongs inside it.
    - Accept a big gap in a card, inside it or under it, as "good enough" (owner, Nov 1).
- **Reference (FBS People):** `components/kit3/panel.tsx` (flex column), `HBars` and `Donut` (`charts-basic`, `charts-parts`), `Funnel` and `Diverging`, and `BoardScreen`'s grid.
- **Check:** At 1280 px, every card in a row ends at the same line, and no card has more than one row's height of empty space under its content.

## Rule 63. A locked module shows read-only, with the plan that unlocks it

- **Applies to:** multi-tenant subscription products (rule 7).
- **Rule:** A module the tenant's plan leaves out, or that needs an add-on it hasn't bought, stays in the menu with a badge naming what unlocks it ("Starter", "Growth", "Enterprise" or "Add-on"). Opening it shows the page read-only: people can look around, but can't create, change, approve, import or run anything there. Each action they try opens the app's own upgrade sheet instead.
- **Why:** A hidden module, or a bare upgrade page, gives people nothing to judge whether it's worth paying for. The real screens, read-only, sell it honestly. Read-only is also how rule 7 keeps its promise that a downgrade never hides a company's own records. The owner asked for this on Nov 3, 2026.
- **Do, for the badge:**
    - **Name the cheapest way in:** "Add-on" when an add-on for the module can be bought on the current plan, else the lowest plan that includes it. Read both from the plan and add-on catalogs (data, rule 15), never from a label written in the frontend.
    - **Show it wherever the module is reached:** the sidebar and phone menu, hub tabs and sub-tabs, the command palette, and the page title.
    - **Text, not colour alone (rule 19):** a small pill with a lock icon and the plan's name, whose accessible name says "Needs the Growth plan" or "Needs an add-on".
- **Do, for the page:**
    - **One banner under the title,** always in the same place and height (rule 37): what the module does in one line, the badge, and its buttons:
        1. **Upgrade** for the owner and billing admins, opening Company › Billing at that plan or add-on.
        2. **Ask the owner** for everyone else. It sends the owner one notification, at most once a week per module and person (rule 25).
        3. **See it with sample data,** opening the same page in the Test Drive (rule 14) in a new tab.
    - **What it shows:** the company's own records, read-only. A company that never had the module sees its real screens with their empty states, never sample data (owner, Oct 31: outside the Test Drive a real company never sees a sample; rules 13, 18 and 59).
    - **Every action stays visible but inert:** header buttons, row menus, row buttons, form fields, drag and drop, imports and AI actions. They are `aria-disabled` and still focusable, so a screen reader says why, and pressing one opens the upgrade sheet (a sheet, rule 60, at `?upgrade={module}`, rule 34).
    - **Exports of the company's own records still work** (rule 7: a tenant can export all its data at any time).
    - **Roles still apply (rule 30):** read-only never shows more than the person's permissions allow. A person who couldn't open the module on a paid plan gets the 403 page, not a read-only one.
- **Do, on the server:**
    - **The module gate lets reads through** (GET and HEAD) and refuses every write with 403 and the error code `plan_locked`, naming the plan or add-on (rule 28). The server decides; inert buttons are only a courtesy (rule 5).
    - **A read with a side effect counts as a write:** recomputing scores, running AI, sending anything or spending credits stays refused, or is skipped while locked.
    - **The page's response says it is locked and what unlocks it,** so the frontend never guesses.
- **Don't:**
    - Hide a locked module from the menu, or send people to a page with no way to see or unlock it.
    - Write a record, file, audit entry or AI credit from a locked module. The only write is the "Ask the owner" notification.
    - Hard-code which plan unlocks which module in the frontend.
    - Show sample data inside a real company's app (see What it shows).
- **Reference (FBS People):**
    - **Plans and modules:** `App\Support\Feature`, `Plan` (`plans.features`) and `CompanyAddon::catalog()`. One server helper returns, for a module and the current company, the plan and add-on that unlock it and the badge label.
    - **The gate today:** `EnsureFeature` (the `feature:` middleware) and `Ui3Controller::FEATURES` refuse every request, and the page shows "This is not on your plan" (`screens/error-in-app.tsx`). This rule replaces that with the read-only page.
    - **The frontend:** one locked frame (badge, banner, read-only context) used by every hub frame, and a read-only context the shared buttons, row menus, table button cells and forms read to go inert. The 3.0 menu (`components/kit3/shell/nav.ts`) learns each item's module; the 2.x menu's hard-coded labels (`lib/nav.ts`) leave with the 2.x pages (rule 59).
    - **The plan:** `tasks/plan-locked-modules.md`.
- **Check:**
    - On a Growth company, Talent › Succession shows "Enterprise" in the menu and opens read-only with the banner. Every button opens the upgrade sheet, and a POST to any of its write endpoints returns 403 `plan_locked`.
    - Move a company from Growth to Starter: every performance review is still readable, nothing in Performance can be changed, and its export still downloads.
    - A person without `talent.view` gets 403 on Talent pages whatever the plan.

## Rule 64. Labels never wrap: they move to a menu, or become an icon

- **Rule:** A button, tab, link, chip, pill, badge or menu item shows its label on one line. When a bar runs out of room, its least-used controls move into a menu first, then labels become their icons. Text never breaks onto a second line, and nothing spills out of the bar.
- **Why:** On Nov 3, 2026 the owner showed the top bar beside the sidebar at about 1100 px: "Search people, pages, actions", "Ctrl K", "Ask AI", "Ask HR" and "Page tour" had each broken onto two lines. A wrapped label doubles a control's height, breaks the bar's line and looks broken (rule 37). The room a bar has depends on what sits beside it (the sidebar, a sheet, a split view), not on the window's width alone.
- **Do:**
    - **One line, always:** every control in a bar is `whitespace-nowrap` and `shrink-0`. The bar's one flexible text, such as the breadcrumb or a name, is `min-w-0` and truncates with an ellipsis, with the full text in a tooltip.
    - **Give way by the bar's own width:** make the bar a container (`@container`) and hide or shorten its controls with container queries (`@max-5xl:hidden`), not viewport breakpoints.
    - **Give way in this order:**
        1. Secondary actions move into an overflow or Help menu that still offers them.
        2. Wide controls become their icon (the search box becomes a magnifier).
        3. Labels become icons, keeping their accessible name and a tooltip (rule 19).
        4. The page's main action keeps its label longest.
    - **Check it in the app** at the narrowest desktop width (1024 px with the sidebar) and at 375 px (D11).
- **Don't:**
    - Let a label wrap, or let a bar scroll sideways to hide the problem (rule 54).
    - Hide a control at some width without another way to reach it.
    - Use viewport breakpoints for anything inside the main column.
- **Reference (FBS People):**
    - **The model:** `components/kit3/shell/topbar.tsx`. The bar is a container: Ask HR and Page tour move to the Help menu below `@5xl`, search becomes an icon below `@3xl`, Ask AI loses its label below `@xl`, and Help goes below `@lg`.
    - **Shared controls carry `whitespace-nowrap`:** `ui/button.tsx`, the table toolbar's filter chips, the hub tabs and the phone tab bar.
    - **The canvas renderer** truncates a sidebar hub's name when a plan badge sits next to it (rule 63).
- **Check:** At 1024, 1100, 1280, 1440 and 375 px, on any page, no button, tab, link or chip has its text on more than one line (a walk over its text nodes finds one line box), and the top bar's `scrollWidth` equals its `clientWidth`.

## Rule 65. A card's content fits it: nothing scrolls sideways, nothing is cut off, nothing is stretched thin

- **Rule:** On the desktop, what a card holds fits inside it: a table never scrolls sideways, and no button, pill or label is cut off at its edge. A card is never stretched far past its own content to match a taller neighbour. When a short card sits beside a tall one, the layout changes: the tall card spans rows and the short cards stack beside it.
- **Why:** On Nov 3, 2026 the owner showed two cases. An uploads table in a half-width card scrolled sideways with "Remove" cut off. "What we read" beside a long application form was stretched to the form's height, with its five rows spread far apart. Both look broken, and both waste the space rule 4 says every element must earn. Rule 62 asks cards to fill their space; this rule says how, without stretching or overflow.
- **Do:**
    - **Tables fit their card:**
        - Text columns shrink (`minmax(0, 1fr)`) and truncate with an ellipsis, with the full text in a tooltip.
        - A column of buttons or row menus is as wide as its widest button (`max-content`).
        - Pills and progress labels shorten rather than spill.
        - A table's minimum width never passes its card's width (`min-width: min(…, 100%)`).
    - **Too many columns for the card?** Give the card more width, move the less important columns into the row's detail (a side sheet or an expanding row), or show the rows as cards. Never hide the problem behind a scrollbar (rule 54).
    - **Pair cards of about the same height** in a row (within about a third). When one is much taller, such as a long form, it spans rows (`row-span-*` in the app), and the cards beside it stack: each useful on its own, together about its height.
    - **Phones are the exception:** at 375 px a wide table becomes cards (D11), and a list may scroll inside its own box when it says so.
- **Don't:**
    - Set a table's minimum width wider than the card it sits in.
    - Give a column of buttons a fixed width narrower than its label.
    - Fill a stretched card with `space-around` gaps and call it filled (rule 62).
- **Reference (FBS People):**
    - **The canvas renderer** (`docs/ui-redesign/canvas/project/Page.dc.html`) applies the table rules itself: `colW()`, the card-width cap, truncating pills and bar labels. A panel's `rspan` spans rows.
    - **The sweep:** in the app (Playwright) or, for the archived canvas, its board preview (`tools/measure.cjs`): for every desktop page, flag any `overflow-x: auto` box whose `scrollWidth` passes its `clientWidth`, and any card whose height is over 1.5× its natural height (`align-self: start`) and more than 160 px taller. Both counts are 0 since Nov 3.
    - **In the app (not yet audited, Nov 3):** `DataTable` (`components/kit3/table`) and the board table (`screens/boards/board-table.tsx`) must follow the same column rules. A page with a long form and short side cards uses a row-spanning grid.
- **Check:** A Playwright sweep of the desktop pages reports 0 overflowing boxes and 0 stretched cards. On any live page at 1280 and 1440 px, no table's scroll box is wider than its card, and every row button shows its whole label.

---

## Rule 66. This repo is the source of truth

- **Rule:** The conventions shared by every FBS project live in one place: `https://github.com/FrontlineBusinessDev/Web-Development-Architecture`. Every project links it from its `CLAUDE.md`, people and AI assistants read the relevant file in it before structural work, and a change to a shared convention updates this repo in the same piece of work.
- **Why:** Before this repo, each app kept its own copy of the rules and of the reusable-components report, and the copies drifted: seven copies of the report in four versions, and three rules files with different rule sets and different rule 58 and 59. One source keeps the apps aligned, and a link can't go stale the way a copy does.
- **Do:**
    - **Link it** from every project's `CLAUDE.md`, with the instruction to read the matching file before adding a CRUD module, a shared component, a migration, a Docker or deploy change, a dependency or a new project ([../docs-and-ai/claude-md-template.md](../docs-and-ai/claude-md-template.md)).
    - **Read the file for the task,** found from the [README](../README.md) index, not the whole repo every time.
    - **Update it in the same task:** when a project changes a shared convention (a rule, a stack version, a deploy step, a template, a pattern), commit the change here too, in its own commit, update the file's `Last updated` line, and add a changelog line below for rule changes.
    - **Keep the split clean:** facts about one project (its paths, modules, what is built, its brand) stay in that project's `CLAUDE.md` and `docs/`. Only what applies to every project lives here.
    - **Bring projects in line:** after this repo changes, the next task in each project either follows the change or records the gap (rule 36).
    - **Link, don't copy:** a project may keep a short pointer file, but not a full copy. An old local copy (such as `reusable-components-in-all-projects.md` or `SYSTEM-RULES.md`) says at its top that this repo wins when they disagree, until it is removed.
- **Don't:**
    - Change a shared rule in one project's copy only.
    - Paste this repo's files into a project and let them drift.
    - Put secrets, keys, server addresses, customer data or real people's details in this repo.
- **Reference:** this repo's [README](../README.md) and [../docs-and-ai/claude-md-template.md](../docs-and-ai/claude-md-template.md).
- **Check:** Every project's `CLAUDE.md` links this repo. A search of the projects finds no copied rule text that disagrees with it. The latest change to a shared convention in any project has a matching commit here.

## Rule 67. Every Coolify resource can be redeployed

- **Rule:** Clicking **Deploy** or **Redeploy** in Coolify on any resource of an app (the app, the deployer, a worker) succeeds at any time, on any commit, with or without a PC having sent an image first. `php artisan deploy` is the fast and zero-downtime path; Coolify's buttons are the always-working path.
- **Why:** A Redeploy is what people reach for when something looks wrong, and Docker cleanup can remove a PC-sent image at any time. On 2026-11-07 a Redeploy failed in two apps: FBS Connect's app built a one-line `FROM <slug>-pc:latest` that wasn't on the server ("pull access denied"), and FBS People's app refused to build without the `ASSET_URL` / `ASSET_HASH` that only the PC passes. A button that fails leaves no way back except a PC.
- **Do:**
    - **Dockerfile app with Coolify's rolling update** (no deployer; FBS Pay, FBS Connect, FBS CRM): the app builds the full `Dockerfile` (Dockerfile location `/Dockerfile`). `scripts/publish.sh` builds the image as `<app uuid>:<full commit>`, the tag Coolify gives its own build, so Coolify finds it and skips its build ("Build step skipped"); with no such image, Coolify builds the same `Dockerfile` on the server. Leave the app's registry image name empty and "Disable build cache" off. Build variables the bundle reads (`VITE_*`) are declared as `ARG` and the PC reads the same values from Coolify, so both builds match.
    - **Compose app with a deployer** (FBS People, FBS Learn, FBS Suites; [the template](../templates/deploy-kit/)): the `Dockerfile` builds a working image with no build args. When the static files normally come from the bucket, a plain build makes them inside the image, marks it (`public/build/.in-image`) and serves them itself; only the deployer and the PC say the files are in the bucket (the template: `ASSETS_UPLOADED=1`; FBS People: `ASSETS=bucket` with `ASSET_URL` and `ASSET_HASH`). A Redeploy of the app works but restarts every container at once: use it to repair the stack, not for releases.
    - **Deployer:** uses `<slug>-pc:<commit>` when a PC sent it, else the image it built last time for this commit, else builds on the server (`build-image.sh`). It runs once per **container**, not per image: a reboot or restart keeps the container and doesn't deploy again, while every Deploy, Redeploy or rollback starts a new container and rolls out.
    - **Workers on their own resource** build from source and redeploy on their own.
    - Say in the app's `DEPLOY.md` what a Redeploy of each resource does and which log lines show it.
- **Don't:**
    - Point a Coolify resource at an image only a PC can provide (`FROM <slug>-pc:latest`, a "Docker Image" resource with no registry).
    - Make a build refuse to run without values only `scripts/publish.sh` passes.
    - Guard the deployer per image, so a Redeploy of the same commit silently does nothing.
- **Reference:** FBS Pay (`laravel-fbs-pay/DEPLOY.md`, `scripts/publish.sh`) for a Dockerfile app; FBS People (`Dockerfile` `ASSETS`, `config/app.php` `asset_url`, `deploy/coolify-deploy.sh`) for a compose app with a deployer. The runbook: [../deployment/publish-and-rollout.md](../deployment/publish-and-rollout.md#redeploy-in-coolify).
- **Check:** With no PC involved, click Redeploy on each resource of the app: each deployment ends healthy, and the site answers `/up` with its pages and assets. Then run `php artisan deploy`: the log shows the PC image used ("Build step skipped", or "already on this server").

## Rule 68. People's names are stored as first_name and last_name

- **Rule:** Every table that holds a person (users, employees, contacts, students, guardians, applicants, leads, a company's contact person) stores the name as `first_name` and `last_name` (required, 80 characters), with `middle_name` optional. A person never gets a single `name`, `full_name` or `contact_name` column.
- **Why:** A single name column can't be sorted by surname, greeted by first name ("Hi Maria"), matched against another app or the CRM (hub events carry `contact.first_name` / `last_name`), or split reliably later ("Dela Cruz, Juan Jr."). Every app that started with `name` had to migrate later (FBS CRM, FBS Stock, FBS People, FBS Learn), and the ones that kept both a whole name and the parts now keep two copies that drift.
- **Do:**
    - **Columns:** `first_name`, `last_name`, optional `middle_name`. The model's `name` is a read-only accessor (`trim("{$this->first_name} {$this->last_name}")`), never a column and never in `$fillable`.
    - **A person inside another record** gets prefixed parts: `contact_first_name` / `contact_last_name`, `admin_first_name` (FBS Pay's access requests), `prospect_first_name` (FBS CRM's deals).
    - **Forms** ask First name and Last name in two fields; **imports and exports** carry two columns; **tables of people** show the parts in separate columns, sorted by `last_name`, then `first_name`; **search** matches either part and "first last".
    - **Factories and seeders** fill `fake()->firstName()` and `fake()->lastName()`.
    - **Moving off a single column** follows rule 23: add the parts and backfill them with a splitter that knows "Last, First", suffixes and particles (FBS Learn's `App\Support\PersonName::split`), switch the code, then drop the old column in a later release.
- **Don't:**
    - Store a whole name next to the parts.
    - Split a name at display time, or join the parts into a stored column.
    - Use `fake()->name()` for a person.
- **Allowed as one column:** a label that is not a person record: an email's display "from" name, a name typed onto a signature line, a copy of a name kept in an audit row for history, a free-text customer note.
- **Reference:** FBS Suites `users` (`first_name` / `last_name`, `User::name` accessor, the factory); FBS CRM's `2026_10_04_000001_drop_name_from_users_table` as the way off a `name` column.
- **Check:** A new person table has `first_name` and `last_name` and no `name` column; the model has no `name` in `$fillable`; `grep -rn "string('name')\|full_name" database/migrations` finds no person table.

---

## Design profile

D1–D11 are the shared design system of every FBS app: layout, sizes, controls and behaviour, taken from FBS People UI 3.0 (approved by the owner on Nov 6, 2026). They hold no brand values. Colour token names, the light and dark values each app sets, and the kit components that draw them are in [../structure/design-system.md](../structure/design-system.md). D12 is each app's own product profile and lives in that app's repo.

A project that uses this design follows D1–D11 as written. A project with a truly different design copies these headings and replaces the values in its own D12, so its profile stays just as specific.

### D1. Shell

- **Navigation:** a sidebar with the app's mark, a tenant switcher when the person belongs to several tenants, and the hub menu (count badges where something waits). An app may use a narrow icon rail instead (FBS Suites uses a 72 px rail). The platform console groups items under small-caps section labels. The sidebar footer has the plan card (tenant owners only) and the signed-in person with their role.
- **Top bar:** 56 px (52 px on a phone). Breadcrumb on the left; on the right: search with `Ctrl K`, Ask AI where the app has AI, the page tour, notifications, help, and a theme switch.
- **Page:** tabs under the top bar, then pill-style sub-tabs when a tab has them. Title 26 px (22 px on a phone), one description line, actions on the right with the primary last. Content fills the main area with no max-width, with 32 px side padding (16 px on a phone).
- **Who sees what:** the menu shows only what the person's role reaches (rule 30). On a phone, a bottom tab bar holds the main hubs and More.

### D2. Grid and cards

- A 12-column grid with a 16 px gap. Cards span 4, 5, 6, 7, 8 or 12 columns, and each row adds up to 12.
- **Cards:** the surface colour on the page colour, a faint 1 px border, the app's card radius token and a soft shadow. Padding is 16–18 px.
- An app may tint cards by subject with an ultra-soft gradient (its D12). No tint on tables, cards inside a card, dialogs, sheets and print.
- The card title is 14–15 px semibold, with an optional grey subtitle and a link on the right ("All", "Approvals ›"). A card title never repeats the page title.

### D3. Type

- One sans-serif font for the interface (the app's brand font, D12) and one monospace font for ids, keys and codes.
- Sizes: page title 26 px; dialog and drawer titles 15–17 px; card titles 14–15 px; body and table text 13.5–14 px; labels and buttons 12.5–13 px; meta lines 12 px. No text below 12 px.
- Numbers in stat cards are 26–28 px bold with the unit small beside them. Tables use tabular figures.

### D4. Color

- **Roles, not values:** page, surface, sidebar, inset, line, ink (main, secondary, meta), chip, primary, accent, and the status tones (success, warning, danger, info, neutral). The token names and each app's values for light and dark are in [../structure/design-system.md](../structure/design-system.md).
- **One primary action per area.** The accent marks the active menu item, links, focus and chart highlights.
- **Accent text sits only on cards and panels,** never straight on a tinted page background, where it can fall under 4.5:1 (rule 19).
- **Status pills and notes** have a text colour, a background and a dot per tone.
- **Avatars:** initials on one of six pastel pairs, chosen from a hash of the name, so a person always gets the same colour.
- Colour never carries meaning alone: pair it with text, an icon or a pattern.

### D5. Controls

- **Buttons:** 36 px tall. The primary is filled, secondary buttons are the surface colour with a border, and destructive buttons use the danger tone. Small buttons are 30 px. Icons are 15–16 px stroke icons (`lucide-react`).
- **Inputs and selects:** 38 px tall, with the label above in 12.5 px semibold.
- **Segmented control:** on a chip background, with the selected option on the surface colour with a light shadow.
- **Toggle:** 40 × 24 px. **Pill:** 22 px tall, fully round, with a coloured dot. **Touch targets:** at least 44 px on a phone.

### D6. Lists and tables

- **Toolbar:** a search box whose placeholder names the list ("Search my questions"), filter chips (rule 53), Clear, View (columns) and Export. Dated lists add a second toolbar line with presets (This year / Last 12 months / All time) and From and To. A view switch sits in the toolbar.
- **Footer:** the range ("1–8 of 162"), rows per page and a numbered pager.
- **Rows:** 52 px. A person cell is an avatar, the name and a grey second line. Status is a pill, money is right-aligned, and row actions sit at the far right (one button or ⋯). Tables fit their card (rule 65).
- Any list that can reach 10 rows gets the full toolbar and pager. Short reference lists skip the toolbar.

### D7. Stats and charts

- **Metric cards:** a row of 3–5 separate cards, each with an uppercase label, a big number with its unit, an optional delta pill, a one-line hint and a "View … →" link.
- **No ring, sparkline or mini chart inside a metric card or any other card.** A trend or split gets its own chart card from rule 9's catalog.
- **No plain bar charts:** trends are capsule columns or lollipops, money over time a smooth area, splits a donut with a legend, counts and percentages, breakdowns horizontal bars, daily data a calendar by month, and attendance the people-by-day grid.
- Every chart gets a one-line summary and a "Show data" table (rule 9).

### D8. Steppers, inboxes, dialogs

- **Stepper:** numbered circles joined by a line. Done steps are filled with a check in the accent and the current step is outlined. A side box with the next action runs the full height of the card.
- **Inbox** (approvals, helpdesk, support): the list on the left at 360 px, with count tabs and checkboxes; the detail on the right with a two-column key-value grid and the action row. Ticking several shows a bulk action bar.
- **Dialog:** 520–600 px, centred. The header is an icon tile in a tone, the title, one subtitle line and Close. The body is a two-column grid, with half-width fields where they pair (From and To). The footer is Cancel plus one primary, with a note on the left when useful ("Saved as a draft as you type").
- **Sheet:** slides in from the right, 520–600 px wide and full height, with the same header and footer. Long forms, records and Ask AI use sheets.
- A destructive confirm says what will change, shows the numbers affected and, where it matters, asks for a reason (rule 60).

### D9. Pickers, loading and empty states

- **Async select (rule 2):** selected items are 28 px round tokens with an avatar or icon, the name and a remove ×, with Clear at the end of the field. The open list has 44 px rows with a checkbox (multiple) or a check (single), and a "found for …" or "Searching…" header. Audience pickers add tabs (Everyone, groups, People) and an "N people get it" line.
- **Loading (rule 3):** skeletons shaped like the final cards and rows. A save dims the container to about 0.5 opacity with a spinner over it.
- **Page change (rule 3):** the page area (not the menu or top bar) blurs, with the app's mark in the centre and an accent arc orbiting it, until the new page arrives; the new page's body then fades in with a 6 px rise over about 220 ms. Off for "reduce motion".
- **Empty state:** an icon tile, a title, one sentence and one action.

### D10. Copy, AI and tours

- **Words:** plain words in sentence case, and "they" for people in sample text. Dates carry a computed weekday ("Fri, Oct 30").
- **Money:** pesos with two decimals on payslips, invoices and receipts; "k" only in charts and stats.
- **Ask AI,** where the app has it, is on every page. Anything it would change shows as an approval card ("Nothing is filed until you approve", rule 20).
- **Page tours:** every page has a tour, and the tour catalog is tabbed.

### D11. Dark mode and phone

- **Dark:** the same layout with each token's dark value. Subject tints never apply in dark mode. Check every screen in both modes. Print and exported documents stay light.
- **Phone (390 px):** one column with cards at full width; tables become stacked cards; dialogs and sheets are full width; the bottom tab bar replaces the sidebar.

### D12. Each app's product profile

Each app writes its own D12 in its own repo, never here. It holds:

- the brand accent (and any subject accents) for light and dark, the brand font, the logo and mark, and the card radius
- any subjects that tint pages or cards (FBS People: HR in coral and Time & Pay in green)
- what the public pages (landing, sign-in, mail) look like

FBS People's D12 is the model: `laravel-fbs-people/SYSTEM-RULES.md`, section "D12. FBS People product profile".

---

## Adding a rule

Add it in this repo first (rule 66). Copy this block to the end of the rules, give it the next number, fill every line, and add its title to [Rules at a glance](#rules-at-a-glance):

```md
## Rule N. Short name

- **Rule:** the decision, in one or two sentences.
- **Why:** the reason.
- **Do:**
    - …
- **Don't:**
    - …
- **Reference (FBS People):** how the reference project applies it, with file paths. Name another project instead when it shows the rule better.
- **Check:** how a reviewer confirms it.
```

Keep rules general enough for every FBS project; put anything specific to one project in its **Reference** line or that project's own docs. Design specifics (sizes, components) go in the design profile as a new **D** section; brand values go in each app's D12.

## Changelog

- **2026-10-31 to 2026-11-06:** rules 1–65 and the design profile written in FBS People; see the changelog in `laravel-fbs-people/SYSTEM-RULES.md` for each change.
- **2026-11-06:** Moved into this repo as the shared rules for every FBS project, by owner decision:
    - "In this repo" lines renamed **Reference (FBS People)**, kept as examples; the requirement is the Rule, Do and Don't lines.
    - Rule 6: FBS People and this repo are the starting point; each project picks one UI kit (A: shadcn + Radix, B: the FBS Learn kit) and writes its own D12.
    - Rules 22 and 49: the checks run inside the Docker image build; GitHub Actions are never the gate. New projects start with no baselines; existing baselines only shrink; no `@phpstan-ignore`.
    - Rule 38: the client layer (axios, `createCrudResource`, TanStack Query) is required everywhere; tables and forms follow the project's kit.
    - Rule 51: the 300-line limit is enforced in the build for JS/TS and PHP, with size-capped baselines (from FBS CRM).
    - Rule 58: keeps FBS People's wording, plus FBS CRM's note that it applies to new modules.
    - Rule 59 retired: no Claude Design canvas step or source.
    - Rule 66 added: this repo is the source of truth, linked from every project's `CLAUDE.md`.
    - Design profile: brand values moved out; D1–D11 are brand-neutral, and D12 is each app's own profile.
- **2026-11-07:** Rule 67 added: every Coolify resource can be redeployed (after Redeploy failed in FBS Connect and FBS People). The runbook drops "never press Redeploy on the app"; FBS Connect moves to FBS Pay's `<app uuid>:<commit>` pattern and FBS People's image builds its own static files when it gets no bucket args. The same day FBS CRM moved to Pay's pattern, and FBS Learn's and FBS Suites' deployers run once per container, so every app follows rule 67.
- **2026-11-07:** Rule 68 added: people's names are stored as `first_name` and `last_name`, never one `name` column; each app's gaps are listed in [../structure/database.md](../structure/database.md#known-differences-today).
