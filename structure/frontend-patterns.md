# Frontend patterns

How the React side of an FBS app talks to the server, shows data and asks questions, and the two UI kits a project may choose between.

- **Applies to:** every FBS Laravel app's `resources/js`.
- **Sources:** People `resources/js/lib/api/*`, `components/ui`, `components/kit3`, `SYSTEM-RULES.md` rules 2, 3, 34, 37, 38, 41, 45, 51, 52 and 60; Pay `resources/js/ops`; MCP `components/fbs`; Learn `docs/reusable-components-in-all-projects.md` sections 4, 5, 8 and 9.
- **Last updated:** 2026-11-06

## Rules for every project, whatever the kit

| Rule | Do | Don't |
|---|---|---|
| TypeScript | `.ts` / `.tsx`, strict mode, the `@/` alias for `resources/js` | `.jsx`, `any` at the API boundary, deep relative imports |
| Navigation | Inertia visits and real links; Wayfinder route helpers for URLs | A client router, hand-written URL strings scattered in components |
| One client layer | One axios instance + `createCrudResource` for every module | `fetch()` or `axios` in a component, page or hook outside the layer |
| Server data | TanStack Query (one `QueryClient`, one key factory per module) | Copy server data into `useState` or a global store |
| Questions | The app's own modal (confirm, discard changes) | `window.confirm`, `alert`, `prompt`, or a "Leave site?" box |
| Loading | A skeleton shaped like the content on first load; a contained spinner over the area on a change | An empty or "No records" state while loading; a full-page spinner |
| Floating layers | Portaled to `<body>` with collision handling, one z-index scale | `absolute` popups inside cards, `z-[9999]` |
| Routes | Every page, tab and sub-tab has its own URL | Tabs held only in `useState` or a hash |
| The URL holds the view | Search, filters, sort, page and an open record (`?open=<id>`) in the query string, with the API's names | Filters kept only in component state or localStorage |
| File size | 300 code lines at most per file; a page file only wires parts together | Adding to a file near the limit |
| Stable layout | Every field reserves its one error line; buttons keep their width when busy | `{error && <p>…</p>}` that pushes the form down |
| Forms | Keep unsaved work: warn through the app's modal before leaving | Losing a half-filled form on Esc or navigation |

## The client layer

One folder holds it (`resources/js/lib/api/` in People; `api-service/` in Learn, FCA-SMS and MCP). One project, one layer.

### `client.ts`

- `http`: one axios instance, `baseURL: ''`, `withCredentials: true`, a timeout, `Accept: application/json`, and the XSRF token (axios reads the `XSRF-TOKEN` cookie and sends `X-XSRF-TOKEN`).
- One response interceptor turns every failure into `ApiError(message, status, errors?, data?)`. `error.field('name')` gives a field's first message; on a 409 `data` holds the current record.
- 401 sends the person to sign in, 419 refreshes the token and retries once, 429 waits and retries.
- `unwrap(response)` returns `body.data` when the body has a `data` key, `null` for 204.

### `createCrudResource<T, Input>({ baseUrl })`

Mirrors the server's `crudModule` routes ([backend patterns](backend-patterns.md)):

| Call | HTTP |
|---|---|
| `getAll(params)` | `GET /` |
| `list(params)` (Learn: `getPaginatedFilterSearch`) | `GET /pagination-search` |
| `searchActive(params)` | `GET /search-active` |
| `lookup(q, extra)` | `GET /lookup?status=active&q=` → `{ value, label }[]` |
| `inUse(id)` | `GET /{id}/in-use` |
| `create(data, { onUploadProgress })` | `POST /` |
| `update(id, data, { onUploadProgress })` | `POST /{id}` |
| `archive(id)` / `restore(id)` | `PATCH /{id}/archive` / `/restore` |
| `remove(id)` (Learn: `delete`) | `DELETE /{id}` |

A body holding a `File` or `Blob` is sent as `FormData` automatically; query strings use bracketed keys (`filters[status]=active`).

A module's API file is a few lines:

```ts
export const invoices = createCrudResource<Invoice, InvoiceInput>({ baseUrl: '/billing/invoices' });
export const invoiceKeys = {
    all: ['invoices'] as const,
    list: (p: CrudParams) => ['invoices', 'list', p] as const,
    show: (id: number) => ['invoices', 'show', id] as const,
};
export const invoiceSchema = z.object({ number: z.string().min(1).max(40), issued_at: z.string() });
```

Put it next to the module's pages (People) or in `api-service/<module>/` (Learn); the project picks one place.

### TanStack Query defaults

- Reads retry once; writes never retry.
- Lists keep the previous page on screen while the next loads (`placeholderData: keepPreviousData`).
- A newer request cancels the one it replaces (pass the `signal`).
- A save invalidates exactly the keys it changed, then shows one toast.

### Error helpers

Use the project's shared helpers, never local copies: `apiErrorMessage(err, fallback)` (first field error, else the API message, else the fallback) and a `downloadCsv` / Excel helper. A server 422 lands on the same field line the client validation uses.

## Choose one UI kit

A project uses **one** kit and says which in its `CLAUDE.md`. Never mix primitives from both.

### Kit A: shadcn + Radix (People, Pay, MCP)

| Piece | Where / what |
|---|---|
| Primitives | `components/ui/*` from shadcn (style **new-york**, `components.json`): button, input, select, dialog, sheet, dropdown-menu, tooltip, checkbox, toggle, skeleton, sonner. Radix gives portals, focus and collision handling |
| Class helper | `lib/utils.ts` `cn()` = `clsx` + `tailwind-merge` |
| Toasts | `sonner` through the `ui/sonner` `Toaster` (Pay's ops app uses Radix Toast; one per app) |
| Forms | `zod` schema per form + **one** form library: TanStack Form (People) or react-hook-form (Pay) |
| Tables | One shared `DataTable` on `@tanstack/react-table` with server-side search, filter, sort and paging, fed by a list hook and kept in the URL (People `components/kit3/table/*`) |
| Confirm | One host mounted once: `askConfirm({...})` / `askDiscard()` (People `kit3/confirm-host.tsx`), or a `ConfirmDialog` with `tone="danger"` (MCP `components/fbs/confirm-dialog.tsx`) |
| Pickers | Async select over `lookup` (People `kit3/async-select.tsx`) for any list that can grow |
| Extras | People `kit3`: progress toast for imports and exports, nav loader, busy wrapper, empty state, panel |

### Kit B: Learn's own kit (Learn, FCA-SMS)

| Piece | Where / what |
|---|---|
| Primitives | `Button` (`primary`, `secondary`, `ghost`, `danger`; `loading`), `FormField`, `Select` (portaled, searchable), `DatePicker`, `MultiSelect`, `Toggle` (`role="switch"`), `InstantTooltip`, `InlineEditableField` |
| One import | `components/ui/index.ts` re-exports the kit plus `apiErrorMessage` and `downloadCsv` |
| Lists | The `CardGridPanel` kit (`PanelHeader`, `ListTable`, `Th`, `useSort`, `StatusPill`, `GridFooter`, `ViewToggle`) and `TableToolbar` for every filter bar |
| Dialogs | `Modal` (`sm`–`xl`, Esc and backdrop close) and `ConfirmDialog` + `useConfirm()` (`tone`, `requireTypedConfirmation`) |
| Toasts | `ToastProvider` / `useToast().showToast(message, { tone })`, and `FlashToastBridge` for Inertia flash messages |
| Skeletons | `AppSkeleton` with `useSkeletonData(loading, rows, FIXTURE)`; `boneyard-js` generated bones (`npm run bones:build`, commit `resources/js/bones/*`) |
| Forms | Hand-built `FormField` + local state; add zod for validation in new forms |

Kit B's full catalog, tiers and copy rules: Learn `docs/reusable-components-in-all-projects.md` sections 3–5 and 9.

### Picking a kit

| Choose A when | Choose B when |
|---|---|
| A new product, or the product follows People's design profile | The product is a Learn / FCA-SMS sibling or a port of one |
| You want accessible primitives off the shelf | You need the CardGridPanel list pages exactly as Learn has them |

## Loading, busy and empty states

- **First load:** a skeleton shaped like the real cards and rows, same height (kit A: `ui/skeleton`; kit B: `AppSkeleton` with a unique `<module>-<page>-<region>` name). Mark parts that stay real (table headers) so they don't pulse.
- **A change** (save, refetch): dim the area to about half opacity with a spinner over it; the old rows stay until new ones arrive.
- **Empty:** an icon, a title, one sentence and one action. Only after loading finished.
- **Page change:** the page area (not the menu) shows the app's nav loader.

## Feedback and questions

- Success and info: a toast. Errors from a save: the field line for field errors, a toast for the rest.
- Destructive actions: the app's confirm modal, saying what changes and how many records; ask for a reason when the audit log should keep one.
- A dialog over a dialog reuses the open backdrop and sits one step higher in the z-index scale.
- Never `window.confirm`, `alert` or `prompt`.

## Pages, tabs and links

- One URL per page in hub / tab / sub-tab order: `/people/employees`, `/time/attendance/roster`. Lower-case words with hyphens.
- A record opens at its own route, or as a side sheet at `?open=<id>` on its list.
- Links use Wayfinder helpers (or the router-compat shim in Learn / FCA-SMS), never strings built in a component.
- Never name an Inertia page prop the same as a shared prop (`auth`, `flash`, the tenant key): page props override shared ones.

## Markdown

Not installed by default. When a screen needs it: `react-markdown` + `remark-gfm` + `@tailwindcss/typography`, links open with `rel="noopener noreferrer"`, no `rehype-raw`, never `dangerouslySetInnerHTML` with user text. On the server, `Str::markdown($text, ['html_input' => 'strip', 'allow_unsafe_links' => false])`.

## Known differences today

| App | Differs from the standard |
|---|---|
| Stock | Plain JavaScript, relative imports, one-file kit `components/ui.jsx`, no TanStack Query |
| CRM, Connect | Own PascalCase kit in `components/ui`, a `fetch` wrapper (`lib/api/client.ts`) instead of axios, custom `ToastContext`, `useState` forms (formik and zod installed but unused), nivo charts |
| Work | Own kit (one file per component, clsx + tailwind-merge), no TanStack Query |
| People | `lib/api.ts` `fetch` wrapper still used by about 90 files while `lib/api/*` (axios) is adopted; `InputError` renders nothing when empty (rule 37) |
| Pay | Two Inertia apps (`app.tsx` and `ops-app.tsx`); sonner in one, Radix Toast in the other |
| Learn, FCA-SMS | `router-compat` shim over Inertia; no `cn()` helper (template strings) |
