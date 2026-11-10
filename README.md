# Web Development Architecture

The shared rules, folder structure, tech stack and deployment architecture for every FBS Laravel app.

- **Applies to:** FBS People (the reference project), FBS CRM, FBS Connect, FBS Pay, FBS Learn, FBS Stock, FBS Work, FCA-SMS, FBS Suites (MCP and webhook hub), and every new project.
- **Repo:** https://github.com/FrontlineBusinessDev/Web-Development-Architecture
- **Last updated:** 2026-11-06

This repo is the source of truth. Every project's `CLAUDE.md` links here. When a shared convention changes, update this repo in the same piece of work (system rule 66).

## Which file answers what

| Question | File |
|---|---|
| What are the rules every app follows? | [rules/system-rules.md](rules/system-rules.md) |
| How do I name, size and comment code? | [rules/coding-standards.md](rules/coding-standards.md) |
| Which branch, commit style, what never goes in git? | [rules/git-workflow.md](rules/git-workflow.md) |
| Which versions and packages do we use? | [stack/tech-stack.md](stack/tech-stack.md) |
| How do I set up PHP, extensions and `.env` on my machine? | [stack/environment-setup.md](stack/environment-setup.md) |
| Where does each file go? | [structure/folder-structure.md](structure/folder-structure.md) |
| How do I name tables and write migrations? | [structure/database.md](structure/database.md) |
| How do I build a CRUD module on the backend? | [structure/backend-patterns.md](structure/backend-patterns.md) |
| How do pages fetch data, show forms, toasts and skeletons? | [structure/frontend-patterns.md](structure/frontend-patterns.md) |
| Which colours, fonts and tokens? | [structure/design-system.md](structure/design-system.md) |
| How do tenants, roles and sign-in work? | [structure/tenancy-and-auth.md](structure/tenancy-and-auth.md) |
| What runs on the server? | [deployment/architecture.md](deployment/architecture.md) |
| What goes in the Docker image? | [deployment/docker-image.md](deployment/docker-image.md) |
| How do I deploy and roll back? | [deployment/publish-and-rollout.md](deployment/publish-and-rollout.md) |
| Which checks must pass before a deploy? | [quality/testing-and-ci.md](quality/testing-and-ci.md) |
| How does an app talk to the FBS Suites hub and the CRM, and where are its AI (MCP) keys made? | [integrations/fbs-suites-hub.md](integrations/fbs-suites-hub.md) |
| How does an app have an upload checked for viruses before it stores it? | [integrations/fbs-suites-scanner.md](integrations/fbs-suites-scanner.md) |
| How do FBS People and FBS Pay share people, time, money items and payslips? | [integrations/fbs-people-pay-connector.md](integrations/fbs-people-pay-connector.md) |
| How are `docs/`, specs and plans organised? | [docs-and-ai/docs-and-specs.md](docs-and-ai/docs-and-specs.md) |
| What goes in a project's `CLAUDE.md`? | [docs-and-ai/claude-md-template.md](docs-and-ai/claude-md-template.md) |
| How do I start a new project or onboard a developer? | [docs-and-ai/new-project-checklist.md](docs-and-ai/new-project-checklist.md) |
| Which AI skills and plugins does a project use? | [docs-and-ai/ai-skills-and-plugins.md](docs-and-ai/ai-skills-and-plugins.md) |

## Templates

Copy these into a project and replace the placeholders.

| Folder / file | What it is |
|---|---|
| [templates/CLAUDE.md](templates/CLAUDE.md) | Starting `CLAUDE.md`, with the pointer back to this repo |
| [templates/spec-template.md](templates/spec-template.md), [templates/plan-template.md](templates/plan-template.md) | Design spec and implementation plan |
| [templates/deploy-kit/](templates/deploy-kit/) | `Dockerfile`, `.dockerignore`, `docker-compose.yml`, `scripts/`, `deploy/`, `DEPLOY.md`, `.env.deploy.example` |
| [templates/hub/](templates/hub/) | The FBS Suites hub sender (`SuiteHub`, `SendSuiteHubEvent`) and its connection check (`SuiteHubCheckController`) |
| [templates/scan/](templates/scan/) | The upload virus check against FBS Suites' scanner (`VirusScan`, the `Scanned` rule, `config/scan.php`, the async result callback `SuiteScanResultController`) |

## Decisions behind these docs (2026-11-06)

Agreed with the owner when the docs were first consolidated:

| Topic | Decision |
|---|---|
| Reference project | FBS People. Start new work from it. |
| Table names | Literal `app_` names; `DB_PREFIX` stays empty; framework and Spatie tables stay unprefixed. |
| Branch | `master` is the default and the deploy branch. |
| UI kit | Each project picks one kit (shadcn + Radix, or the Learn kit) and never mixes them. |
| Checks gate | `composer ci:check` runs in the Docker build. A failing build never deploys. GitHub Actions are not the gate. |
| Strictness | Larastan level 7; 300-line file limit enforced by lint; baseline files only shrink; new projects start with none. |
| Working rules | Keep rules 56 (stop at 93%), 57 (tidy first), 58 (frontend first). Rule 59 (Claude Design canvas) is retired. |
| Design | Same token names everywhere; each app keeps its own brand colours and font. |
| Runtime and rollout | FrankenPHP + Octane, a pinned image tag, and a Coolify deployer that replaces web containers one at a time. |
| Existing apps | Left as they are; their problems are recorded, not fixed. New applications follow these docs and avoid them ([checklist, section 11](docs-and-ai/new-project-checklist.md)). |

## How to use these docs

- **People:** read the file for the task in front of you, not the whole repo.
- **AI assistants:** a project's `CLAUDE.md` points here. Before structural work (a CRUD module, shared component, migration, Docker or deploy change, PHP dependency, hub event), read the matching file above.
- **Apps that differ today:** most files end with "Known differences today". They record how existing apps differ. Existing apps are left as they are (owner's decision, 2026-11-06); new applications follow the standard and must not repeat them.
- **Changing a rule:** edit the file here first, in its own commit, then change the apps. Never renumber system rules; add new ones at the end.
