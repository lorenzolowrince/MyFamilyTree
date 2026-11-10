# ADR 0001: Phase 2 foundation decisions

Date: 2026-11-11 · Status: Accepted

Decisions taken while setting up the project, including where the implementation differs from the Phase 1 architecture documents and why.

## Approved Phase 1 choices (recorded)

Hostinger VPS for staging/production; AWS S3 `ap-southeast-5` for files; Docker + Sail for local development; Google login first, Apple later; Chromium (Browsershot) for PDFs.

## Versions pinned

Laravel 13.41, PHP 8.5 (container), Inertia 3, React 19, TypeScript 5, Tailwind 4, Fortify 1.37 (includes passkeys), Horizon 5, Pest 4, PostgreSQL 18, Redis 8.

## Differences from the architecture documents

| Planned                                        | Implemented                                             | Reason                                                                                                                                                                                    |
| ---------------------------------------------- | ------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| MinIO for local object storage                 | **RustFS**                                              | Laravel Sail now ships RustFS instead of MinIO. Both are S3-compatible; it is only used locally, so production is unaffected.                                                             |
| ESLint + Prettier + Vitest                     | **Vite+** (`vp check`: Oxlint + Oxfmt; Vitest built in) | The current starter kit uses Vite+, which covers the same checks faster. Adding ESLint would duplicate it (rule 7: no unnecessary dependencies).                                          |
| Larastan level 8                               | **Level 7**                                             | The starter kit's code passes level 7. Raising to 8 is planned once the first domain code lands (Phase 4), so new code is written to it rather than retrofitted.                          |
| ClamAV container in Phase 2                    | **Phase 7**                                             | Nothing uploads files until Phase 7. ClamAV needs ~1 GB RAM and would slow every local start for no benefit yet.                                                                          |
| i18n structure (EN/BM) in Phase 2              | **Phase 5**                                             | There is almost no app-specific UI text yet. The translation mechanism is built when profile and timeline screens add real text, so it is designed around actual needs.                   |
| Nonce-based Content-Security-Policy in Phase 2 | **Phase 3**                                             | Baseline headers (nosniff, frame denial, referrer, permissions, COOP, HSTS on HTTPS) are live now. The CSP needs Vite nonce wiring and is tested alongside the auth hardening in Phase 3. |
| `./vendor/bin/sail` commands                   | `docker compose exec laravel.test ...` on Windows       | The Sail script refuses to run in Git Bash; it only supports WSL 2. Both use the same containers.                                                                                         |

## Other decisions

- **Users use UUIDv7 primary keys** (changed from the starter kit's integer IDs before any data existed). Laravel's internal tables (`jobs`, `cache`, `passkeys`) keep their default key types because their packages expect them.
- **Email is `citext`**: case-insensitive uniqueness is enforced by the database (tested).
- **Tests run on PostgreSQL**, not SQLite, locally and in CI.
- **Navigation shows all ten sections**; sections not built yet are disabled with "Soon" and a tooltip naming the delivering phase. No link leads to a non-working page.
- Removed the starter kit's unused header-layout variant and its links to Laravel's repository and documentation.
- `storage:ensure-bucket` artisan command creates the local bucket; it refuses to run outside local/testing because real buckets are provisioned separately.
- Horizon's dashboard gate currently allows nobody outside the local environment. Phase 3 grants it to Super Administrators.

## Not yet done (needs GitHub access)

Branch protection rules for `main` and `develop` (require PRs and green CI). The GitHub CLI isn't installed on the development PC, so these are set in GitHub → Settings → Branches, or by installing `gh`.
