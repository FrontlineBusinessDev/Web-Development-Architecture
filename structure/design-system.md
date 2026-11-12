# Design system

The shared design token names and layout rules every FBS app uses. Each app keeps its own brand colours and font.

- **Applies to:** every FBS app's `resources/css/app.css` and components, with either UI kit ([frontend patterns](frontend-patterns.md)).
- **Sources:** each repo's `resources/css/app.css` (Pay `ops.css`, People `ui3.css`); People `SYSTEM-RULES.md` rules 19, 37, 41, 54, 60–62, 64, 65 and design profile D1–D11; MCP `CLAUDE.md` ("style B"); Learn `docs/reusable-components-in-all-projects.md` section 9.
- **Last updated:** 2026-11-06

## The idea

- **Shared:** the token **names**, the type scale floor, control sizes, the z-index scale, dialog and card behaviour, dark mode and accessibility rules.
- **Per app:** the token **values**: brand colours, fonts, the exact radius and shadow.

A component written against the shared names works in any FBS app and picks up that app's brand.

## Token names

Define them as Tailwind 4 tokens in `@theme` in `resources/css/app.css` (no `tailwind.config`), with light values on `:root` and dark values on `.dark`.

| Token (`--color-<name>`) | Use | Kit A (shadcn) variable it feeds |
|---|---|---|
| `page` | page background | `--background` |
| `surface` | cards, dialogs, sheets, menus | `--card`, `--popover` |
| `sub` | insets, toolbars, table footers | `--muted` |
| `side` | sidebar or icon rail | `--sidebar` |
| `line` | borders, dividers | `--border`, `--input` |
| `ink` | main text | `--foreground` |
| `ink-2` | secondary text | `--muted-foreground` |
| `ink-3` | meta text, placeholders | |
| `primary`, `primary-hover` | the one primary button per area | `--primary` |
| `accent`, `accent-soft` | brand accent: active nav, links, focus ring, chart series; its pale background | `--ring`; shadcn's own `--accent` (hover tint) = `accent-soft` |
| `success`, `warning`, `danger`, `info` | status text and dots | `--destructive` = `danger` |
| `success-soft`, `warning-soft`, `danger-soft`, `info-soft` | status pill and notice backgrounds | |

Other shared tokens:

| Token | Use |
|---|---|
| `--font-sans`, `--font-display`, `--font-mono` | body, wordmark / headings, ids and keys |
| `--radius-control`, `--radius-card` | inputs and buttons; cards and dialogs |
| `--shadow-card`, `--shadow-popover`, `--shadow-modal` | elevation |
| `--z-*` | the z-index scale below |

Rules:

- Components use token classes (`bg-surface`, `text-ink-2`, `border-line`). A raw hex or a default palette colour (`bg-amber-100`) in a component is a bug.
- Every token has a dark value. Check every screen in both modes.
- Colour never carries meaning alone: pair it with text, an icon or a pattern.
- Accent text sits on `surface`, never on a tinted page background, so contrast stays at 4.5:1.

## Each app's brand today

Values from each app's CSS on 2026-11-06. Keep them, but move them onto the shared names as screens are touched.

| App | Brand / primary | Page | Font (body / display) | Card radius |
|---|---|---|---|---|
| People | ink primary `#16191f`; one accent, coral `#c43a24`, on every page (owner, Nov 12); logo `#a8362b` | `#f4f6f8` | Inter / Lexend Exa (wordmark) | 14 px (D2 says 16) |
| CRM | blue `#2f6be0`, gold `#d9a62e`, text `#162141` | white, tint `#ebf5ff` | Instrument Sans / Space Grotesk (D3 says Inter) | 8 px |
| Connect | blue `#2f6be0`, gold `#d9a62e`, text `#162141` | white, tint `#ebf5ff` | Inter / Space Grotesk | 8 px |
| Pay | green `#2d9450` | `#f6fbf8` | Plus Jakarta Sans / Poppins | soft cards |
| Learn | orange `#bd4f17` (overridden per school at runtime) | neutral | Inter / Lexend | 10 px |
| FCA-SMS | green `#38663f` | neutral | Inter / Lexend | 10 px |
| Stock | gold `#b07f12`, espresso text `#2f2113` | cream `#efe6d2` | Poppins | |
| Work | slate `#3a4a62`, magenta accent `#c42a93` | `#f4f6f8` | Instrument Sans | |
| MCP (FBS Suites) | green `#15803d`, rail `#0e2a22`, soft accent `#ecfdf3` | `#f2f6f3` | Inter / Geist Mono | 14 px |

## Type

- Minimum text size 12 px. Body and table text 13.5–14 px; labels and buttons 12.5–13 px; card titles 14–15 px; page titles 22–26 px (22 on a phone).
- Numbers in tables and stats use tabular figures.
- Preload the main font and give it a fallback with matching metrics, so nothing reflows when it loads.

## Controls and touch

| Element | Size |
|---|---|
| Button | 36 px tall (small 30 px) |
| Input, select | 36–38 px tall, label above, one reserved hint / error line below |
| Toggle | `role="switch"`, about 40 × 24 px |
| Pill | about 22 px, fully round, with a dot |
| Touch target on a phone | at least 44 px |

Interactive elements show `cursor-pointer` and a visible `:focus-visible` ring in the accent.

## Z-index scale

One scale, as tokens. Components use no other z-index value. (People `resources/css/ui3.css`.)

| Token | Value | Layer |
|---|---|---|
| `--z-sticky` | 10 | sticky headers, sidebar |
| `--z-bar` | 20 | fixed bars: phone tab bar, save bar, Back to top |
| `--z-popover` | 30 | dropdowns, popovers, select lists |
| `--z-dialog` | 40 | dialogs and sheets |
| `--z-stacked` | 44 | a dialog opened over an open dialog or sheet |
| `--z-nested` | 45 | a dropdown inside a stacked dialog |
| `--z-toast` | 50 | toasts |
| `--z-tooltip` | 60 | tooltips |
| `--z-tour` | 70 | page tour |

Every floating layer renders in a portal at the end of `<body>`, flips and shifts to stay on screen with an 8 px margin, and closes on Esc.

## Cards and layout

- Cards: `surface` on `page`, a 1 px `line` border, `--radius-card`, `--shadow-card`, 16–18 px padding.
- Cards in a row are as tall as the tallest, and their content fills them. No empty band under a chart.
- A card's content fits it: nothing scrolls sideways, nothing is cut off. Wide tables scroll inside their own box.
- Bars, tab strips and chip rows never show a scrollbar; labels that don't fit move into a "More" menu or become an icon with a tooltip. Labels never wrap.
- No dead whitespace: every space has a purpose.
- Phone (390 px): one column, full-width cards, tables become stacked cards, dialogs and sheets full width.

## Dialogs and sheets

- **Dialog:** 520–600 px, centred. Header: icon tile in a tone, title, one subtitle line, Close. Footer: Cancel plus one primary.
- **Sheet:** slides in from the right, 520–600 px, full height, same header and footer. Long forms and records use sheets.
- **Destructive confirm:** says what will change and how many records, uses the danger tone, and may ask for a reason.
- **Dialog over a dialog:** reuses the open backdrop (no second dark layer); the one underneath dims to match the page; the new one sits at `--z-stacked`.
- Never the browser's `confirm`, `alert` or `prompt`.

## Lists, stats and charts

- A list that can reach 10 rows gets the full toolbar (search, filters with one / several / any, Clear, export) and a pager showing the range ("1–25 of 162").
- Status is a pill; money is right-aligned; row actions sit at the far right.
- Every chart gets a one-line summary and a "Show data" table. Prefer infographics to stock bar charts (People rule 9).

## Dark mode and motion

- Dark mode is a `.dark` class on `<html>` (`@custom-variant dark (&:where(.dark, .dark *))`). The person picks light, dark or system in the app; the CSS never uses `prefers-color-scheme` directly.
- Print and exported documents stay light.
- Honour `prefers-reduced-motion`: turn off non-essential animation.

## Accessibility

WCAG 2.2 AA: contrast 4.5:1 for text, keyboard reachable everything (Esc closes, arrows move in tab lists and listboxes), `aria-sort` on sortable headers, `role="alert"` on error toasts, nothing important only on hover.

## Known differences today

> Existing apps are left as they are (owner's decision, 2026-11-06). This list is a record, not a to-do: new applications follow the standard and must not repeat these. See [the new-project checklist](../docs-and-ai/new-project-checklist.md), section 11.

| App | Differs from the standard |
|---|---|
| People | Token names `bg`, `panel`, `side`, `sub`, `line2`, `ink2`, `ink3`, `chip`; card radius 14 px in CSS vs 16 px in D2 |
| Stock | Names `page`, `surface`, `line`, `ink`, `muted`, `soft` (close to the standard) |
| Work | Names `canvas`, `surface`, `elevated`, `ink`, `ink-2`, `muted` (close to the standard) |
| CRM, Connect | `--color-fbs-*` names, card radius 8 px, font differs from its own design profile |
| Learn, FCA-SMS | `brand-*` / `neutral-*` scales and `ink`; z-index modals 50, tooltips 60, popovers 100 (not the scale above) |
| MCP | Dark theme is still shadcn's default neutral |
