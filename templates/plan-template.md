<!--
Plan template. Copy to docs/superpowers/plans/YYYY-MM-DD-<topic>.md (same date and topic as its spec) and fill in every <...>.
Conventions: Web-Development-Architecture/docs-and-ai/docs-and-specs.md
-->

# <Topic> Implementation Plan

> **For agentic workers:** work task by task, in order. Steps use checkboxes (`- [ ]`); tick one only when it is committed. Stop at 93% of the 5-hour usage limit (rule 56).

**Goal:** <One sentence: what is true when this plan is done.>

**Architecture:** <Two or three sentences: where the code lives and how the parts talk.>

**Tech Stack:** Laravel 13, Pest, PostgreSQL, Inertia v3 + React 19 + TanStack Query, Tailwind v4<, plus anything specific>.

**Spec:** `docs/superpowers/specs/<YYYY-MM-DD-topic-design.md>`.

**Rules:** <numbers from Web-Development-Architecture/rules/system-rules.md that apply to the whole plan>.

## Global Constraints

- Octane-safe: no per-request state in statics or singletons. Cache arrays, never models.
- Migrations are additive and backward compatible. New migrations are named `<YYYY_MM_DD_0001NN>_*`, in task order.
- New tables use literal `app_` names, `protected $table`, and `->constrained('app_<table>')`.
- New tenant-owned tables carry the tenant id, an index led by it, the fail-closed scope, and an isolation test.
- Loading regions show skeletons; questions use the app's modal, never `alert` / `confirm` / `prompt`.
- No file over 300 lines.
- <Project-specific constraints>

## Review Focus

- <A risky case a reviewer must check> Test in Task <N>.

---

## Phase A: <Name>

**Rules:** <numbers>

### Task 1: <Name>

**Files:**
- Create: `<path>`
- Modify: `<path>:<line>` (<what changes>)
- Test: `tests/Feature/<Name>Test.php`

**Interfaces:**
- Produces: `<Class::method(Type $arg): ReturnType>`, <what it returns>.
- Columns: `<app_table>.<column>` (<type>, <nullable / default>).

- [ ] **Step 1: Write the failing tests.**
  - `it('<behaviour>')`: <arrange, act, assert>.
- [ ] **Step 2:** Run `php artisan test --filter=<Name>Test`. Expected: FAIL (<why>).
- [ ] **Step 3:** <Implement: the smallest change that makes it pass.>
- [ ] **Step 4:** Run `php artisan test --filter=<Name>Test`. Expected: PASS. Run `composer ci:check`.
- [ ] **Step 5:** Commit: `<type>(<scope>): <subject>`.

### Task 2: <Name>

<...>

---

## Done

- [ ] Every task ticked and committed.
- [ ] `composer ci:check` passes.
- [ ] The "as built" note is written under the requirement, with its shortcuts (rule 36).
- [ ] Rule 22's definition of done is met, or each gap is named with where it is tracked.
