# Git workflow

How every FBS project names branches, writes commits, reviews changes, keeps secrets out of git and gets code from a laptop to production.

- **Applies to:** every FrontlineBusinessDev repo, including this one.
- **Sources:** `laravel-fbs-people` (rules 22, 56, 57; `scripts/publish.sh`), `laravel-fbs-crm` (`HANDOFF.md` §7), `laravel-fbs-learn` (commit history, plan template), the `.gitignore` and `.gitattributes` of all nine apps, and the owner's decisions of 2026-11-06.
- **Last updated:** 2026-11-06

## 1. Branches

| Branch | Use |
|---|---|
| `master` | The default branch **and** the deploy branch of every project. Always passes `composer ci:check`. `php artisan deploy` refuses any other branch. |
| `claude/<adjective>-<name>-<id>` | An AI session's branch, named by the tool. One per session. |
| `<type>/<short-name>` | A person's branch when the work needs review first: `feat/locker-requests`, `fix/payslip-rounding`. Same types as commits. |

- Work lands on `master` in one of two ways: committed straight to `master` (the owner's own sessions, as FBS People does, rule 56), or through a pull request from a branch. Either way every commit on `master` passes the checks.
- Keep branches short-lived. Merge `master` into your branch, or rebase your own unshared branch onto it, before asking for review.
- Delete a branch once it is merged. A merged pull request is finished: follow-up work starts a new branch from the latest `master`.
- CI or tooling that names a branch (a GitHub workflow, a deploy script, `DEPLOY_BRANCH`) names `master`.

## 2. Commits

### Message format

Conventional Commits, with the rules that drove the change at the end (rule 55):

```
type(scope): what changed, in plain words (rules 51, 57)
```

| Type | For |
|---|---|
| `feat` | a new feature or screen |
| `fix` | a bug fix |
| `refactor` | a tidy commit with no change in behaviour (rule 57) |
| `perf` | a speed-up with no change in behaviour |
| `test` | tests only |
| `docs` | docs, plans, specs, handoffs |
| `style` | formatter-only changes (Pint, `vp fmt`) |
| `build` | Dockerfile, compose, deploy scripts, dependencies |
| `chore` | anything else that isn't code behaviour (generated files, config housekeeping) |

- **Scope** is the module or area in lower case: `feat(billing)`, `fix(signup)`, `build(deploy)`, `docs(handoff)`.
- **Subject** says what changed for the person using it, in the present tense, with no full stop: `fix(payments): a refund no longer counts twice in the cashier total`.
- **Body** (optional) says why, what was left out and how it was checked. Name shortcuts (rule 36).
- Breaking changes for an API client add `!`: `feat(api)!: …`, and say what breaks in the body.

### What goes in one commit

- **One change per commit.** A feature, a fix or a tidy-up, never mixed (rule 57).
- **Tidy first:** before a feature, clean the code it touches in its own `refactor(…)` commit with no change in behaviour, then add the feature in the next commit.
- **Formatting alone** goes in its own `style(…)` commit, never inside a feature diff.
- **Each commit builds and passes `composer ci:check`** (rule 49). Don't commit a known-broken state "to fix in the next commit".
- **Shared convention changed?** Commit the matching update to this repo in the same piece of work (rule 66).

### AI-assisted commits

- The trailer lines the tool adds (`Co-Authored-By:`, `Claude-Session:`) stay as the tool writes them. Add nothing else about the assistant.
- No model names or versions in commit subjects, bodies, PR titles or code comments.
- **Long AI runs** commit after each task but push once, when the 5-hour usage limit reaches 93% or when the owner asks (rule 56). Before stopping, the work builds, is committed and pushed, and the resume point is written in the task list.

## 3. Pull requests

- **Small:** one feature or fix. A tidy commit and its feature commit may share one PR.
- **The description** says what changed and why, names the rules that apply (rule 55), ticks the definition of done (rule 22) or names what is missing, and states that `composer ci:check` passed locally (there is no CI gate to rely on, rule 49).
- **The owner reviews and merges.** An AI session never approves or merges its own pull request.
- **Merge with a merge commit,** not a squash, so the tidy and feature commits stay apart and each can be reverted alone.
- Reviews cite rule numbers for every finding ("breaks rule 52: tab kept in `useState`").
- Check for a PR template in the repo (`.github/pull_request_template.md`) and fill its sections.

## 4. What never goes into git

| Never commit | Instead |
|---|---|
| `.env`, `.env.*` (except `.env.example`, `.env.production.example`, `.env.deploy.example`) | Keep real values on the machine or in Coolify. Example files hold names and placeholders only. |
| Passwords, API keys, tokens, webhook secrets, SSH keys | The platform console (rule 12) or Coolify's environment. |
| Real server IP addresses, host names of private servers, SSH user names | `DEPLOY_SSH=user@your-server` style placeholders in examples. |
| Databases and dumps: `*.sqlite`, `*.sql`, `*.dump`, backup folders | Object storage backups (rule 10); a seeder for test data. |
| Customer or personal data, real names, emails and phone numbers in fixtures | Fictional sample data (rule 14). |
| `Claude outputs/`, scratch screenshots, ad-hoc reports | Keep them local, or attach them to the issue or PR. |
| `graphify-out/` | Git-ignore it. Rebuild locally with `graphify update .` (AST-only, no API cost); a fresh clone or a cloud session runs it first when it needs the graph. |
| `vendor/`, `node_modules/`, `public/build`, `public/hot`, `storage/` contents, `bootstrap/cache/*.php` | Built by Composer, npm and the image build. |

- **Line endings are LF.** Every repo has `.gitattributes` with `* text=auto eol=lf`; keep it. On Windows, files in the working tree may be CRLF (`core.autocrlf=true`); git still stores LF.
- **Found a secret or personal data in history?** Rotate the secret first (it is already exposed). Removing it from history rewrites shared history, so only the owner decides it (section 5).

## 5. History is shared: don't rewrite it

- Never force-push `master` or someone else's branch, and never rebase commits others have pulled.
- `git push --force-with-lease` is only for your own branch that nobody else uses, for example after rebasing it onto `master` before review.
- Never commit conflict markers. Search for `<<<<<<<` before committing a merge (a `GRAPH_REPORT.md` in an older sibling project shipped with them).
- To undo a change on `master`, `git revert` it.
- Purging a file from history (`git filter-repo`) is an owner decision, done once, with everyone told to re-clone.

## 6. From commit to production

1. Commit on `master` (or merge the reviewed PR into it), with a clean working tree.
2. Run `php artisan deploy` on the developer's PC. It runs `scripts/publish.sh`, which refuses any branch but `master` and any uncommitted change, builds the image from the committed `HEAD` (the checks run inside that build, rule 49; no project has wired this in yet), sends it to the server over SSH, pushes `master`, and asks Coolify's API to start the deployer.
3. The deployer replaces the web containers one at a time ([../deployment/publish-and-rollout.md](../deployment/publish-and-rollout.md)).

- A plain `git push` must deploy nothing: keep Coolify's app and deployer resources on manual deploys, so only `php artisan deploy` starts one.
- Never deploy from a branch, a dirty tree or a commit that isn't on `origin/master`.
- `.env.deploy` (SSH target, Coolify URL, UUIDs, token) is git-ignored; its example file holds placeholders only.

## Known differences today

- **Default branch:** FBS CRM, FBS Work and FCA-SMS use `main`, and FBS Connect deploys from `feat/multi-tenant`. They move to `master` when the owner schedules it: rename the branch on GitHub, then update `publish.sh`, `DEPLOY.md`, Coolify's branch setting and any workflow file in the same change.
- **Workflows on the wrong branch:** FBS People's and FBS Suites' `.github/workflows/tests.yml` trigger on `main` while they deploy from `master` (rule 49 says workflows are not the gate anyway).
- **Databases in git:** FBS Stock tracks three SQLite files (`database_backup.sqlite`, `database/database.sqlite`, `database/database.backup-before-purge.sqlite`) and FBS Work tracks sixteen (`database-backup/**` and `database/database.sqlite`). They may hold real people's data: remove them from the tree, add `*.sqlite` to `.gitignore`, and let the owner decide whether to purge history.
- **A real server address in git:** FBS Stock's `.env.example.deploy` names its server in a comment. Replace it with a placeholder; the example file's name should also become `.env.deploy.example`, as in FBS Work and FBS Suites.
- **`Claude outputs/` is tracked** in FBS CRM (13 files), Connect (10) and Pay (15), although CRM's `HANDOFF.md` says not to stage it. Untrack it and add it to `.gitignore`.
- **`graphify-out/` is tracked** in FBS Learn, CRM, Connect, Pay, Stock and FCA-SMS (only FBS Work ignores it). Untrack it in its own `chore(graphify)` commit.
- **Commit style:** FBS People writes `Area: subject (rules 51, 57)`, and FCA-SMS mostly free-form lower-case subjects. New commits use section 2's format; old history stays as it is.
