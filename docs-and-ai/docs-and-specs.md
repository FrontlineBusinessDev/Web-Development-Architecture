# Docs, specs and plans

Where a project's written docs live, how they are named, and the templates for specs and plans.

- **Applies to:** every FBS project.
- **Sources:** `docs/` and `docs/superpowers/` in Learn, FCA-SMS, People and MCP; People `SYSTEM-RULES.md` rules 36 and 55; the `DEPLOY.md`, `HANDOFF.md` and `PROJECT_STATUS.md` files across the repos.
- **Last updated:** 2026-11-06

## 1. Files in the repo root

| File | What it holds | Notes |
|---|---|---|
| `README.md` | What the app is, setup, how to run it, demo logins for local only. | No real credentials, ever. |
| `CLAUDE.md` | Guidance an AI assistant loads every session. | Committed. See [claude-md-template.md](claude-md-template.md). |
| `DEPLOY.md` | The deploy runbook for a person at a PC. | Fixed headings, section 5. |
| `HANDOFF.md` | Where the work stands for the next person or session. | Optional. Section 6. |
| `PROJECT_STATUS.md` | A generated audit of the whole app. | Optional. Section 7. |
| `AGENTS.md` | Laravel Boost's file for other AI tools. | Optional. Keep it short and point to `CLAUDE.md`. |
| `.env.example` | Every key the config reads, with safe defaults. | Names and dummy values only. |
| `.env.deploy.example` | The keys `scripts/publish.sh` reads. | This exact name. Stock's `.env.example.deploy` and FCA's `.envdeploy.example` are drift. |

Shared rules are **not** copied into a project. The project links them (the pointer block in its `CLAUDE.md`) and lists only its own exceptions.

## 2. The `docs/` folder

```text
docs/
  deployment/                 coolify-single-vps.md and other runbooks
  superpowers/
    specs/                    YYYY-MM-DD-<topic>-design.md
    plans/                    YYYY-MM-DD-<topic>.md
  design-system/              the design profile and the approved pictures
  <module>/                   REQUIREMENTS.md with "as built" notes (rule 36), mockups/
  reports/                    dated reports: QA, audits, evals
  implementation/             task prompts still to do
  implementation-done/        the same prompts, moved here when done
  <topic>.md                  single-file guides: setup guides, codemaps
```

Naming:

- **Lowercase kebab-case** for files and folders: `google-classroom-setup.md`, `ai-agent-codemap.md`.
- Upper case only for the well-known names: `README.md`, `REQUIREMENTS.md`, `PLAN.md`, `HANDOFF.md`.
- **Dated reports** end with the date: `ai-agent-eval-report-2026-10-07.md`.
- **No spaces and no typos in names.** FCA's `import data/` and `excel-file-templte/` are what not to do.
- **Images and PDFs sit next to the doc that uses them**, in that topic's folder (`docs/subscription/mockups/`), never loose at the `docs/` root or the repo root.
- Dates are absolute (`2026-11-06`), never "today" or "last week".

## 3. Specs

A spec says **what** to build and **why**, before any plan. Name it `docs/superpowers/specs/YYYY-MM-DD-<topic>-design.md`.

Template: [templates/spec-template.md](../templates/spec-template.md). Its sections:

1. Title, then a `Date: … Status: … Builds on: …` line.
2. **Why**: the problem, today's state.
3. **Who**: a table of roles and what each gets.
4. **Goals**, numbered.
5. **Non-goals**: what is left out on purpose.
6. **Anti-goals**: outcomes that are a failure even if the code works (a secret reaching the browser, money double-spent).
7. **Constraints (from the repo)**: Octane-safe, backward-compatible migrations, tenant scope, plan gates.
8. **Rules**: the numbers from [rules/system-rules.md](../rules/system-rules.md) that apply (rule 55).
9. **Design**: numbered `###` subsections.
10. **Suggested build order**, **Testing**, **Left to the builder**, **Open questions**.

Status values: `Draft`, `Approved`, `Building`, `Done`, `Superseded by <link>`.

## 4. Plans

A plan says **how** to build an approved spec, task by task. Name it `docs/superpowers/plans/YYYY-MM-DD-<topic>.md` (same date and topic as its spec where there is one).

Template: [templates/plan-template.md](../templates/plan-template.md). Its parts:

- **Header:** `# <Topic> Implementation Plan`, then **Goal**, **Architecture**, **Tech Stack**, **Spec** (link) and **Rules** (numbers).
- **Global Constraints:** what every task must respect.
- **Review Focus:** the risky cases a reviewer must test, each tied to a task.
- **Phases** (`## Phase A: …`), each with a **Rules** line.
- **Tasks** (`### Task N: …`), each with:
  - **Files:** Create / Modify (with line references) / Test.
  - **Interfaces:** what it produces (signatures, columns).
  - **Steps** as checkboxes, test first: write the failing test → run it (FAIL) → implement → run it (PASS) → commit.
  - The commit message, in the format of [rules/git-workflow.md](../rules/git-workflow.md).

Rules for every plan and spec (rule 55):

- Name the rules that apply. Acceptance criteria come from those rules' **Check** lines.
- A step that would break a rule stops the plan until the owner decides.
- When a rule changes, update the open plans it touches.

## 5. `DEPLOY.md`

Every project's `DEPLOY.md` uses these headings, in this order (People, Learn, FCA, Stock and Work already do):

1. `# Deploy <App>` and a short "how it works" paragraph.
2. `## One-time setup`: SSH key, `authorized_keys` (append with `>>`), connect once, `.env.deploy`, the Coolify deployer.
3. `## Every deploy`: `php artisan deploy`.
4. `## Check it worked`.
5. `## Troubleshooting`: a table of symptom and fix.
6. `## Security`.
7. `## Turn it off`.
8. `## Files`: a table of every deploy file and what it does.

The shared reasoning lives in [deployment/publish-and-rollout.md](../deployment/publish-and-rollout.md). `DEPLOY.md` holds only this app's steps and names. Delete a runbook when the pipeline it describes is gone (Learn's `docs/deployment/deploy-runbook.tsv` still describes a retired GitHub Actions pipeline).

## 6. `HANDOFF.md`

Write it when work stops mid-way or changes hands.

- **Date** at the top.
- **Done:** what shipped, with commit hashes and branches.
- **Open:** what is half done, and why.
- **Resume here:** the next step, as one sentence the next session can start from.
- **How to check:** the commands and screens that prove the state.
- **Rules for this work:** anything the owner decided that is not yet in a rule.

Keep one current `HANDOFF.md`. Old ones go to git history, not to new files beside it.

## 7. `PROJECT_STATUS.md`

- Generated by the `/audit-status` command. Do not hand-edit it.
- The date of the snapshot goes at the top. Regenerate it before relying on it: Learn's is from 2026-08 and stale.

## 8. "As built" notes (rule 36)

Every finished phase or module adds a note under its requirement, titled `Phase N as built (Month YYYY)`:

- What shipped: routes, screens, jobs, settings, tables, with file paths.
- **Shortcuts:** what was simplified, what breaks first, how to lift it.
- Deviations from the requirement or design, with who approved them.

A later change that lifts a shortcut edits the note in the same commit.

## 9. What does not go in git

- `Claude outputs/` (scratch PDFs, trackers, benchmark sheets). Add it to `.gitignore`.
- `graphify-out/`. It is rebuilt locally with `graphify update .`, and it makes huge diffs. Keep it ignored, as Work and the CRM do.
- `.env`, `.env.deploy`, any `.env.*` except the `.example` files.
- Database copies: `*.sqlite` backups, dumps, `database-backup/`. Stock and Work have committed some; remove them and treat their contents as exposed.
- Screenshots and one-off images at the repo root (Pay has several `print-*.png`).
- Seeded passwords, real server addresses, tokens, personal notes.

## 10. Writing style

- Short, plain sentences. Say why, not only what.
- File paths, classes and commands in backticks.
- One topic per file. Link instead of repeating.
- Update the doc in the same commit as the code it describes. A doc that disagrees with the code is a bug.
- When a shared convention changes, update [Web-Development-Architecture](https://github.com/FrontlineBusinessDev/Web-Development-Architecture) in the same piece of work.
