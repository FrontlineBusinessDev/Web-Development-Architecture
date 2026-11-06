<!--
Spec template. Copy to docs/superpowers/specs/YYYY-MM-DD-<topic>-design.md and fill in every <...>.
Delete a section only if it truly does not apply, and say so in one line instead.
Conventions: Web-Development-Architecture/docs-and-ai/docs-and-specs.md
-->

# <Topic>: <one-line summary>

Date: <YYYY-MM-DD>. Status: <Draft | Approved | Building | Done | Superseded by <link>>. Builds on: <earlier specs, or "nothing">.

## Why

<The problem in two to four sentences. What happens today, and why that is not good enough.>

## Who

| Who | Gets |
| --- | --- |
| <Role> | <What this role can see and do after this change> |
| Every other role | Unchanged. |

## Goals

1. <Goal>
2. <Goal>

## Non-goals

- <Left out on purpose, and when it may come back>

## Anti-goals (a failure even if it works)

- <An outcome that must never happen, e.g. a secret reaches the browser, a log or an export>

## Constraints (from the repo)

- Octane-safe: no per-request state in statics or singletons.
- Migrations are backward compatible (add first, drop in a later deploy).
- <Tenant scope, plan gates, permission names, existing services this must reuse>

## Rules

<The numbers from Web-Development-Architecture/rules/system-rules.md that apply, e.g. 3, 5, 7, 19, 22, 38, 51, 52.>

## Design

### 1. <Part>

<Data model (tables with `app_` names, columns, indexes), endpoints (method, path, request, response), screens (tabs, dialogs, empty / loading / error states), jobs.>

### 2. <Part>

<...>

## Suggested build order

1. <Step>

## Testing

- Feature tests: <endpoints, tenant isolation, permissions, validation, happy path>
- Unit tests: <calculations and rules>
- Browser check: <the top tasks, at desktop and phone width, light and dark>

## Left to the builder

- <Small decisions the plan may make without asking>

## Open questions

- <Question for the owner> — <options, and the recommendation>
