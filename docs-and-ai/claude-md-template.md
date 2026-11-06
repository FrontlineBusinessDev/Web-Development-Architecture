# CLAUDE.md: what every project's file holds

What goes in a project's `CLAUDE.md`, what stays out, and how AI assistants use these shared docs.

- **Applies to:** every FBS project, and every AI assistant working in one (Claude Code, other tools through `AGENTS.md`).
- **Sources:** the `CLAUDE.md` files of Learn, FCA-SMS, MCP, Stock and Work; People `SYSTEM-RULES.md` rules 55–57; section 16 of the old `reusable-components-in-all-projects.md`.
- **Last updated:** 2026-11-06

The fill-in file is [templates/CLAUDE.md](../templates/CLAUDE.md).

## 1. Why it matters

- Claude Code loads `CLAUDE.md` at the start of **every** session. It does not open `docs/*.md` unless told to.
- So `CLAUDE.md` must hold the project's hard rules, and must say **which shared file to read before which kind of work**.
- Everything else is linked, not pasted.

## 2. Commit it

- `CLAUDE.md` is committed, so every developer and every session reads the same rules.
- **Known difference:** CRM, Connect, Pay and People list `/CLAUDE.md` in `.gitignore` (People commits an empty `CLAUDE.md.txt`). Remove that line and commit the file.
- Personal preferences go in `CLAUDE.local.md`, which Claude Code reads and which stays git-ignored.

## 3. The shared-architecture pointer (required)

Every project pastes this block, unchanged, near the top of its `CLAUDE.md`:

```markdown
## Shared architecture (read first)

This project follows the FBS shared architecture:
https://github.com/FrontlineBusinessDev/Web-Development-Architecture
It is the source of truth for the rules, tech stack, folder structure, deployment and the FBS Suites hub contract.

- Keep a clone next to this project (`../Web-Development-Architecture`) and `git pull` it before you rely on it.
- Before you add a CRUD module, a shared component, a migration, a Docker or deploy change, a PHP or npm dependency, or a hub event, read the matching file there:
  - Rules: `rules/system-rules.md`, `rules/coding-standards.md`, `rules/git-workflow.md`
  - Code: `structure/backend-patterns.md`, `structure/frontend-patterns.md`, `structure/database.md`, `structure/tenancy-and-auth.md`, `structure/design-system.md`
  - Stack: `stack/tech-stack.md`, `stack/environment-setup.md`
  - Deploy: `deployment/architecture.md`, `deployment/docker-image.md`, `deployment/publish-and-rollout.md`
  - Checks: `quality/testing-and-ci.md`
  - Hub events: `integrations/fbs-suites-hub.md`
- Every plan, spec and review names the rule numbers it follows (rule 55).
- When this project changes a shared convention, update Web-Development-Architecture in the same piece of work (on its `master` branch). Never change a shared rule in one project only.
- This project's own exceptions are under "Project rules" below. Everything else follows the shared docs.
```

## 4. Sections, in order

Keep the file short: about 150 lines is a good target. Learn's `CLAUDE.md` is several hundred dense lines, and that costs every session. Move module detail to `docs/` and link it.

1. **Title and one line:** what the app is and its stack (`Laravel 13 + Inertia v3 + React 19 (TypeScript) + Tailwind v4`).
2. **Shared architecture:** the pointer block above.
3. **Project docs:** links to this app's design spec, current plan, `DEPLOY.md`, `HANDOFF.md`, codemaps.
4. **Project rules:** only what differs from, or adds to, the shared docs. For example:
   - the tenant model and its scope helpers (Learn's "Multi-school rules", People's `Company` scope);
   - which UI kit the project uses (kit A or kit B; never both);
   - the brand values (colours, font) behind the shared token names;
   - naming exceptions (an unprefixed legacy table), prop names that must not be used;
   - plan and feature gates.
5. **Project status:** what is real and what is still mock or sample data, and the quick test to tell them apart.
6. **Architecture:** routing, layouts, auth, permissions, in a few bullets, plus a **Key files** table.
7. **CRUD module pattern:** one line linking `structure/backend-patterns.md`, plus this project's reference pair (controller and page to copy).
8. **Commands:** dev, build, test, `composer ci:check`, seed, deploy.
9. **Working in this repo:** loading states, dialogs, exports, theme, anything an assistant gets wrong without being told.
10. **Code map (optional):** graphify rules if the project uses it (section 6).

## 5. What stays out

- Secrets, tokens, passwords, seeded logins, server addresses, SSH hosts.
- Personal notes and stray settings. (A copied `CLAUDE.md` once ended with a leftover settings snippet and a note to self; do not copy it.)
- Change history and "what I did today". That goes in `HANDOFF.md`, the "as built" notes or git.
- Long copies of shared rules. Link them.
- Model names or IDs in anything committed.

## 6. graphify (optional code map)

Projects that use graphify add this section:

```markdown
## graphify

This project has a knowledge graph at graphify-out/ (rebuilt locally, not committed).

- For codebase questions, first run `graphify query "<question>"` when graphify-out/graph.json exists. Use `graphify path "<A>" "<B>"` and `graphify explain "<concept>"` for focused questions.
- Read graphify-out/GRAPH_REPORT.md only for broad architecture reviews.
- After modifying code, run `graphify update .` (AST only, no API cost).
```

Markdown is not in the graph. Read docs directly.

## 7. How assistants work with these docs

- **Read before building.** Open the shared file named in the pointer block for the kind of work at hand, and the full text and Check lines of every rule the task names.
- **Plan against the rules** (rule 55). A request that would break a rule stops the work: say which rule, propose a fix that keeps it, and wait for the owner.
- **Tidy first** (rule 57): clean what the feature touches in its own commit, then add the feature.
- **Frontend first** (rule 58): finish the screens on sample data shaped like the real API, then build the backend in one pass.
- **Long runs stop at 93%** of the 5-hour usage limit (rule 56): commit as you go, push once at the stop, and write the resume point.
- **Keep docs true.** Update the project's docs in the same commit as the code. Update Web-Development-Architecture when a shared convention changes.
- **Other tools:** attach or `@`-mention the specific shared file. Do not load the whole repo every time. If the project has `AGENTS.md`, it points to `CLAUDE.md` and this repo.
