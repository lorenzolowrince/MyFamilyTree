# CLAUDE.md: instructions for working on MyFamilyTree

This is the permanent instruction file for every Claude model working on this repository.
The evolving progress record is [DEVELOPMENT_STATUS.md](DEVELOPMENT_STATUS.md). The design lives in
[docs/architecture/](docs/architecture/), and each phase's decisions in [docs/adr/](docs/adr/).

**This is an ongoing project, not a new one.** Phases 1–7 are built and Phase 8 is in progress.
Never restart, rebuild or rewrite working code. Read the relevant code before changing it.

---

## 1. Hybrid model strategy

| Model                                 | Use it for                                                                                                                                                                                                                                                                                                                                                                 |
| ------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Claude Opus 5.5**: primary, default | Routine implementation within the established architecture: backend (Laravel/PHP), frontend (React/TS), PostgreSQL work, routes and controllers, UI, the PWA, feature work, routine debugging and bug fixing, tests, optimisation, routine refactoring, documentation, deployment preparation.                                                                             |
| **Claude Fable 5.2**: specialist      | Architecture assessment and major architectural changes, database design and complex relationships, authentication and authorisation design, security and threat assessment, privacy and data protection, complex root-cause debugging, performance and scalability, major refactoring decisions, critical code reviews, choosing between alternative technical solutions. |

At the start of every significant task, state which model fits it.

- If the current model fits, continue without switching.
- If the other model fits better: explain why, write a handover (section 2), and ask the developer to change the model in VS Code.
- **A model cannot switch itself.** Never say a switch has happened; only the developer can make it.
- Don't use Fable 5.2 for routine coding.

## 2. Handover between models

Every handover has these five parts. The incoming model reads the actual code before modifying anything.

1. **Project status**: stage, done, in progress, outstanding.
2. **Current implementation**: relevant files and modules, database changes, route/API changes, dependencies.
3. **Technical decisions**: architecture, conventions, database decisions, security considerations.
4. **Outstanding issues**: known bugs, errors, limitations, unresolved problems.
5. **Next actions**: specific tasks, expected implementation, relevant files, tests to run or write.

Update [DEVELOPMENT_STATUS.md](DEVELOPMENT_STATUS.md) as part of any handover.

## 3. Stack and how the code is organised

- **Backend:** Laravel 13 (PHP 8.5), Fortify (auth, 2FA, passkeys), Horizon (queues).
- **Frontend:** React 19 + TypeScript, Inertia 3, Tailwind 4, shadcn/ui, and Wayfinder for typed routes in `resources/js/routes`, which are generated (don't edit them).
- **Data:** PostgreSQL 18 (triggers, CHECK constraints, composite foreign keys, `citext`, `pg_trgm`, `unaccent`) and Redis 8.
- **Files:** S3-compatible object storage (RustFS locally).
- **Other local services:** ClamAV (virus scanning), Gotenberg (Chromium, for PDF reports) and Mailpit.

Where things live:

- **Actions** (`app/Actions`) hold business logic once; controllers and imports call them.
- **Policies** authorise every request. Outsiders to a family get **404**; members without the permission get **403**.
- **Presenters** (`MemberPresenter`, `MediaPresenter`) are the only way personal data leaves the server. Every list, tree, search, report and export goes through `MemberPrivacy`/`MemberViewer`.
- **Shape changes to the tree** go through `GraphChange` (one change at a time per family). Relationship rules are enforced in PHP _and_ by database triggers.
- **History:** models with `RecordsHistory` write the append-only `audit_logs` table (protected by a trigger) and `activity_logs`.
- **Queued work:** slow jobs use the `media`, `imports` and `reports` queues, which have their own Horizon supervisor.
- **Contributor and Viewer edits** become `change_requests` (via `ChangeMode`/`ChangeRequests`), and approval runs the same Action a direct edit would.

## 4. Change management

1. Preserve the architecture. Reuse existing services, actions, components and conventions. Don't duplicate functionality.
2. Keep changes to the task. Don't refactor neighbouring code without a reason.
3. Review any new dependency (need, maintenance, licence, security) before installing it. Prefer what is already in `composer.json`/`package.json`.
4. Protect data integrity:
    - Migrations are additive and backwards-compatible.
    - Never drop or rewrite user data without approval.
    - Keep the database constraints and triggers.
5. **Major architectural changes need the developer's approval first.** That covers new services or containers, a new storage or auth approach, schema redesigns and replacing libraries.
6. Record significant decisions in the current phase's ADR (`docs/adr/`) and differences from the plan in that ADR's "Differences" table.

## 5. Git

- **Branches:** one branch per phase or feature, `feature/<module>-<name>`, stacked on the previous phase. `main` is production and `develop` is integration (see [docs/architecture/08-github-workflow.md](docs/architecture/08-github-workflow.md)).
- **Commits:** Conventional Commits (`feat: …`, `fix(tree): …`).
- **Before major changes:** check `git status`, keep uncommitted work, and make a checkpoint commit when appropriate.
- **After changes:** review the diff.
- **Never** discard, reset, force-push or overwrite work without explicit approval.
- `gh` is not installed. Open pull requests in the GitHub web interface.

## 6. Security and privacy (non-negotiable)

The app holds personal information about whole families, including living people. Never weaken a control for convenience.

- Every controller action calls `$this->authorize(...)`. Family-scoped routes use `scopeBindings()`, and a new route model parameter needs the matching relation on `Family`.
- Validate on the server. Use Eloquent or bound parameters only; never interpolate input into SQL. Escape `LIKE` input with `addcslashes`. Check UUIDs with `Str::isUuid` before querying a `uuid` column.
- No raw HTML from users. React escapes by default, and Blade report templates use `{{ }}`.
- Living-person privacy: Contributors and Viewers do not see living people's exact dates, places or notes. Filters and searches must not reveal them either.
- Files:
    - Detect the type from content.
    - Store under a server-generated key in quarantine, scan with ClamAV, re-encode, and strip metadata (GPS) before serving.
    - Serve only `ready` files, after a permission check.
    - Downloads use `Content-Disposition: attachment` with a sandbox policy.
- Secrets never reach logs or the audit log (`HistoryRecorder::IGNORED`). Store hashes, not tokens.
- Downloads of personal data (documents, GEDCOM, reports) are written to the audit log.

## 7. Testing and quality

Do not call a feature done because the code exists. Verify it.

- Run the relevant tests, then the full suite before every commit:
  `docker compose exec -T laravel.test php artisan test --compact`
- Run these before committing:
    - in the container: `vendor/bin/pint --dirty` and `vendor/bin/phpstan analyse --memory-limit=1G` (level 7, must be clean);
    - on the host: `npm run check:fix` and `npm run types:check`.
- Run `npm run build` before running tests that render a **new** Inertia page. Without it the test stalls for minutes instead of failing.
- Every new route gets tests for:
    - outsiders (404);
    - each role (403/allowed);
    - privacy: hidden people stay hidden.
- After UI changes, check the real page in a browser (headless Edge screenshots work) in light and dark mode and at phone width.
- After changing code that queued jobs run, restart Horizon (`php artisan horizon:terminate`, then `docker compose restart horizon`).

## 8. Local environment notes (this Windows PC)

- **Running commands:** use `docker compose exec -T laravel.test <command>`; `sail` does not run from Git Bash. If Docker Desktop is stopped, start it first.
- **Ports:** host ports 5433 (PostgreSQL) and 6380 (Redis), because another project holds the defaults.
- **Frontend tools:** `npm run build/check/types:check` run on the **host** (node_modules holds Windows binaries) with PHP on PATH:
  `export PATH="/c/Users/user/Downloads/php-8.5.1-nts-Win32-vs17-x64:$PATH"`.
- **Local sign-in:** `test@example.com` / `password-for-local-dev`. The demo family is `/families/keluarga-lidau-demo`.
- **Editing PHP files:** Bash heredocs mangle PHP namespaces (backslashes). Use the editor tools, or a Python patch script written to a file.

## 9. Documentation

- `CLAUDE.md`: permanent instructions (this file).
- `DEVELOPMENT_STATUS.md`: progress, decisions, open issues and latest activity. Update it after each milestone.
- `README.md`: setup for people.
- `docs/architecture/`: the design.
- `docs/adr/`: per-phase decisions. Each phase ends with an ADR.
