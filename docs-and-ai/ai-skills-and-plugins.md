# AI skills and plugins

The Claude Code skills and plugins every FBS project uses, and the ones to add when a project needs them. Install these per project; keep each project's rules (its `CLAUDE.md`, these docs) above anything a skill says.

- **Applies to:** every FBS project, web and mobile.
- **Sources:**
  - the Anthropic plugin directory;
  - [anthropics/skills](https://github.com/anthropics/skills), [anthropics/claude-plugins-official](https://github.com/anthropics/claude-plugins-official), [anthropics/claude-for-legal](https://github.com/anthropics/claude-for-legal);
  - the [awesome-claude-code](https://github.com/hesreallyhim/awesome-claude-code) list;
  - install and star ranks from Skillselion (Nov 6, 2026), Walma (Oct 12, 2026) and AGNT's "100 best";
  - People's `tasks/plan-ai-tools.md` (the full top-100 list, marked).
- **Last updated:** 2026-11-07

## 1. How to install

| Where it comes from | How |
|---|---|
| Anthropic plugin directory | Install from claude.ai (Settings › Plugins), or `/plugin install <name>@claude-plugins-official` |
| A GitHub marketplace | `/plugin marketplace add <owner>/<repo>`, then `/plugin install <plugin>@<marketplace>` |
| A single skill | Read its `SKILL.md` first, then copy the folder into the project's `.claude/skills/` |
| Laravel Boost | `composer require laravel/boost --dev`, then `php artisan boost:install`. Our own skills go in `.ai/skills/<name>/SKILL.md` |

**Before installing:**
- A skill runs with the permissions you give the agent. Prefer Anthropic and partner tiers, and read a community skill before installing it.
- Use the original repo, never a copy.

## 2. Every project (install at the start)

| Skill / plugin | Source | Why |
|---|---|---|
| Karpathy guidelines | `forrestchang/andrej-karpathy-skills` | Think before coding, keep it simple, make surgical changes, work toward a checked goal |
| Ponytail | `DietrichGebert/ponytail` | Reuse existing code and platform features before writing new code; `/ponytail-review`, `/ponytail-audit`, `/ponytail-debt` |
| Superpowers | `obra/superpowers` | Plans, test-driven development, systematic debugging, verification before saying "done" |
| Laravel Boost | `laravel/boost` (dev) | Routes, schema, logs and version-matched Laravel, Inertia and Pest docs for the agent |
| php-lsp, typescript-lsp | claude-plugins-official | Code intelligence; fewer PHPStan and tsc surprises |
| code-review, security-guidance, claude-security | claude-plugins-official | Review and security checks before every push |
| claude-md-management, hookify | claude-plugins-official | Keep `CLAUDE.md` lean, and turn its rules into hooks (e.g. the 300-line limit) |
| Bundled: `/run`, `/code-review`, `/simplify`, `/security-review`, `/fewer-permission-prompts` | Claude Code | Already installed: use them |

## 3. By area (add when the project needs it)

**Web, frontend and design**
- frontend-design, `web-design-guidelines` and `react-best-practices` (vercel-labs/agent-skills).
- Senior Frontend (alirezarezvani/claude-skills).
- Axe Accessibility (Deque).
- brand-guidelines (anthropics/skills), so the agent applies the project's tokens.

**Backend and APIs**
- Senior Backend (alirezarezvani/claude-skills).
- `api-and-interface-design`, `incremental-implementation`, `source-driven-development` and `observability-and-instrumentation` (addyosmani/agent-skills).
- postgres-best-practices.
- mcp-builder (anthropics/skills), for an app's MCP server.

**Planning and debugging**
- grill-me, diagnosing-bugs, handoff and writing-for-agents (mattpocock/skills).

**Documents and data**
- pdf, xlsx and docx (anthropics/skills); dataviz (bundled).

**Legal and compliance**
- privacy-legal (data privacy acts, PIAs, DPAs, data subject requests) and employment-legal (claude-for-legal).
- Legal (contracts, NDAs, compliance; knowledge-work-plugins).
- employment-legal leans on US law. For the Philippine Labor Code and DOLE, treat it as a checklist, not advice.

**Security**
- Trail of Bits skills (`trailofbits/skills`) and Semgrep code-security (`semgrep/skills`).
- OWASP Security Audit and Security Audit Loop.
- Docker Image Scan (Trivy).

**Deployment**
- docker-skills (Docker), docker-optimize, deploy-checklist.
- sentry (only if the project uses Sentry); Cloudflare (only for Cloudflare DNS, the edge or Turnstile).

**Mobile (React Native)**
- expo (official Expo skills: build, EAS update, store submission).
- react-native-best-practices.
- Mobile Release Check (store readiness).
- Skills for React/React Native Engineers (animations and gestures; large, so add it only while building the app).

**Optional**
- Caveman (`JuliusBrussee/caveman`): shorter replies, turned on per session with `/caveman`. Real savings are small, about 1–25%.
- humanizer, for help and marketing text.
- marketingskills, for marketing sites.

## 4. Leave off (they break the FBS rules)

| Skill | Rule it breaks |
|---|---|
| Worktree and branch skills (e.g. Superpowers' `using-git-worktrees`, `finishing-a-development-branch`) | Commit straight to `master`, no branches ([git-workflow.md](../rules/git-workflow.md)) |
| Pull-request toolkits | No PRs in the FBS flow |
| GitHub Actions generators (e.g. CodeRocket) | No new workflows unless the owner asks |
| `caveman-compress` on `CLAUDE.md` or the rules | Rules stay in plain, readable language |
| Senior Fullstack | Aimed at Next.js, FastAPI, MERN and Django, not Laravel + Inertia |
| Any skill that adds a second UI kit, router, store or toast library | [tech-stack.md, "Packages not to add"](../stack/tech-stack.md) |

## 5. When a project adds or drops one

- Record it in the project's `CLAUDE.md` (or its `tasks/` notes).
- If it should become standard for every project, update this page on `master` in the same piece of work (rule 66).
