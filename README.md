# My Family Tree

A family heritage platform for documenting ancestry, managing family relationships, preserving photographs and documents, and connecting generations.

**Status:** Phase 3 (authentication and users) built and awaiting review; staging deployment is waiting for the VPS. Family tree features start in Phase 4. See the [roadmap](docs/architecture/10-roadmap.md).

- Architecture: [docs/architecture/00-overview.md](docs/architecture/00-overview.md)
- Implementation decisions: [docs/adr/](docs/adr/)

## Technology

| Layer    | Technology                                                                                       |
| -------- | ------------------------------------------------------------------------------------------------ |
| Backend  | Laravel 13 (PHP 8.5), Fortify (auth, 2FA, passkeys), Horizon (queues)                            |
| Frontend | React 19 + TypeScript, Inertia.js 3, Tailwind CSS 4, shadcn/ui (Radix), Wayfinder (typed routes) |
| Data     | PostgreSQL 18, Redis 8                                                                           |
| Files    | S3-compatible object storage (RustFS locally, AWS S3 `ap-southeast-5` in production)             |
| Tooling  | Pest 4, Larastan, Pint, Vite+ (lint, format, build)                                              |
| CI       | GitHub Actions: tests on real PostgreSQL, static analysis, audits, secret scan                   |

## Requirements

- **Docker Desktop** (WSL 2 backend), which runs PHP, PostgreSQL, Redis, Mailpit and RustFS.
- Git.
- Optional, for running tools outside Docker: PHP 8.4+ with `pdo_pgsql`, Composer 2, Node 24.

## First-time setup

```bash
git clone https://github.com/lorenzolowrince/MyFamilyTree.git
cd MyFamilyTree
cp .env.example .env

# Install PHP dependencies (needed once, before the containers can start).
# With local PHP + Composer:
composer install --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix
# Or with no local PHP, using Laravel's throwaway Composer image:
docker run --rm -v "$(pwd):/var/www/html" -w /var/www/html laravelsail/php84-composer:latest composer install --ignore-platform-reqs

docker compose up -d                                       # first run builds the image (~10-30 min)
docker compose exec laravel.test php artisan key:generate
docker compose exec laravel.test php artisan migrate --seed # roles/permissions + a local test account
docker compose exec laravel.test php artisan storage:ensure-bucket
docker compose exec laravel.test npm ci
docker compose exec laravel.test npm run build
```

Open <http://localhost>.

> **Port conflicts:** if another project already uses 5432 (PostgreSQL) or 6379 (Redis), set `FORWARD_DB_PORT` / `FORWARD_REDIS_PORT` in `.env` (e.g. 5433 / 6380). These only change the port on your PC; the app talks to the containers internally.
>
> **Windows:** the `./vendor/bin/sail` helper only runs inside WSL 2. From Git Bash or PowerShell, use `docker compose exec laravel.test ...` as shown here. For much faster file access, clone the repo inside the WSL filesystem (`\\wsl$\Ubuntu\home\...`) and use `sail` from there.

## Local services

| Service             | URL                        | Credentials         |
| ------------------- | -------------------------- | ------------------- |
| App                 | <http://localhost>         | register an account |
| Mailpit (all email) | <http://localhost:8025>    |                     |
| RustFS console      | <http://localhost:9001>    | `sail` / `password` |
| Horizon (queues)    | <http://localhost/horizon> | local only          |

## Development commands

Run inside the container: `docker compose exec laravel.test <command>`.

| Task                               | Command                               |
| ---------------------------------- | ------------------------------------- |
| Vite dev server (hot reload)       | `npm run dev`                         |
| Queue workers                      | `php artisan horizon`                 |
| Tests                              | `php artisan test`                    |
| Code style (fix)                   | `vendor/bin/pint`                     |
| Static analysis                    | `vendor/bin/phpstan analyse`          |
| Frontend lint + format check (fix) | `npm run check` (`npm run check:fix`) |
| TypeScript                         | `npm run types:check`                 |
| Fresh database                     | `php artisan migrate:fresh --seed`    |
| Make someone a Super Administrator | `php artisan admin:grant <email>`     |
| Run scheduled tasks once           | `php artisan schedule:run`            |

`migrate --seed` creates a local-only account: `test@example.com` / `password-for-local-dev`. To open the admin area (`/admin`), run `admin:grant` for your account and turn on two-factor authentication under Settings → Security.

Tests always run against PostgreSQL (the `testing` database in the container), never SQLite, because the schema relies on PostgreSQL constraints, triggers and extensions.

## Environment configuration

Every variable is listed in [.env.example](.env.example) with local defaults. Key choices:

- `HASH_DRIVER=argon2id`: password hashing.
- `SESSION_DRIVER=database`, `SESSION_ENCRYPT=true`: sessions in PostgreSQL so users can review and revoke devices.
- `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`.
- `FILESYSTEM_DISK=s3`: all media goes to private object storage.
- `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET`: optional. "Continue with Google" appears once both are set (redirect URI `{APP_URL}/auth/google/callback`).
- `SESSION_SECURE_COOKIE=true` on HTTPS environments (adds the `__Host-` cookie prefix).

Staging and production values are stored as GitHub Environment secrets and written to the server's `.env`. **Never commit a `.env` file or any credential.** CI runs a secret scan on every push.

## Branches and workflow

`main` = production, `develop` = staging, `feature/*` = one change each, merged by pull request with green CI. Details: [docs/architecture/08-github-workflow.md](docs/architecture/08-github-workflow.md).

## Deployment

Deployment targets a Hostinger VPS (Ubuntu LTS, Nginx, PHP-FPM, PostgreSQL, Redis) with zero-downtime releases from GitHub Actions. The staging server is provisioned at the end of Phase 3. See [docs/architecture/09-hosting-deployment.md](docs/architecture/09-hosting-deployment.md).
