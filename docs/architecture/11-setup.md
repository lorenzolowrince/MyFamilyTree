# M. Initial Project Setup (what Phase 2 will do)

This is the plan for Phase 2. These commands are run **after** the architecture is approved.

## Prerequisites on your Windows PC

| Tool                                                                                                | Why                                                                       | Notes                                          |
| --------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------- | ---------------------------------------------- |
| **Docker Desktop** (with WSL 2)                                                                     | Runs PostgreSQL, Redis, Mailpit, MinIO and ClamAV exactly like production | Recommended route.                             |
| Git                                                                                                 | Version control                                                           | Already installed.                             |
| VS Code + extensions: PHP Intelephense, Laravel, ESLint, Tailwind CSS IntelliSense                  | Editing                                                                   |                                                |
| (Alternative to Docker) PHP 8.4+, Composer, Node 24 LTS, PostgreSQL 18, Memurai (Redis for Windows) | Native setup                                                              | Works, but drifts from production more easily. |

With Sail, PHP, Composer and Node run inside containers, so they do not need to be installed on Windows.

## Phase 2 steps

1. Create the project from the official Laravel **React starter kit** (Laravel + Inertia + React + TypeScript + Tailwind + shadcn/ui, with Fortify auth).
2. Add Sail with `pgsql`, `redis`, `mailpit`, `minio`; add a ClamAV container.
3. Configure `.env.example`: `DB_CONNECTION=pgsql`, `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=database`, `HASH_DRIVER=argon2id`, `FILESYSTEM_DISK=s3` (MinIO locally), `APP_TIMEZONE=UTC`, `APP_LOCALE=en`.
4. Enable PostgreSQL extensions (`citext`, `pg_trgm`, `unaccent`) in the first migration.
5. Install dev tooling: Pest, Larastan, Pint, Rector, ESLint, Prettier, Vitest; architecture tests (controllers must authorise, no `dd()`/`dump()`, no raw `DB::raw` with interpolation).
6. Create the base layout: sidebar/bottom navigation with the 10 main sections, family switcher, light/dark theme, empty-state pages (no fake data).
7. Add i18n structure (`lang/en`, `lang/ms`) from day one.
8. GitHub: `develop` branch, branch protection on `main` and `develop`, PR template, Dependabot, CI workflow.
9. README with installation, environment configuration, database setup, commands and deployment notes.

## Day-to-day commands (after Phase 2)

```bash
./vendor/bin/sail up -d          # start all services
./vendor/bin/sail artisan migrate --seed
./vendor/bin/sail npm run dev    # Vite dev server with hot reload
./vendor/bin/sail test           # Pest test suite
./vendor/bin/sail pint           # code style
./vendor/bin/sail php vendor/bin/phpstan analyse
```

Local URLs: app `http://localhost`, Mailpit `http://localhost:8025`, MinIO console `http://localhost:8900`.
