# My Family Tree — Phase 1 Solution Architecture

Status: **Draft for approval**. Nothing in Phase 2 begins until this is approved.

| # | Deliverable | Document |
|---|---|---|
| A | System architecture diagram | [01-system-architecture.md](01-system-architecture.md) |
| B | PostgreSQL ERD | [02-database.md](02-database.md#b-entity-relationship-diagram) |
| C | Table and relationship specifications | [02-database.md](02-database.md#c-table-specifications) |
| D | Laravel directory structure | [04-application-structure.md](04-application-structure.md#d-laravel-backend-structure) |
| E | Frontend component architecture | [04-application-structure.md](04-application-structure.md#e-frontend-component-architecture) |
| F | Family relationship modelling | [03-relationship-modelling.md](03-relationship-modelling.md) |
| G | Roles and permissions matrix | [05-roles-permissions.md](05-roles-permissions.md) |
| H | Security architecture | [06-security.md](06-security.md) |
| I | PWA architecture | [07-pwa.md](07-pwa.md) |
| J | GitHub and branching strategy | [08-github-workflow.md](08-github-workflow.md) |
| K | Hosting and deployment | [09-hosting-deployment.md](09-hosting-deployment.md) |
| L | Development roadmap | [10-roadmap.md](10-roadmap.md) |
| M | Initial setup instructions | [11-setup.md](11-setup.md) |

---

## Decisions that need your approval

### 1. Hosting: the current Hostinger plan cannot run the approved stack (blocking for deployment)

I checked the existing Hostinger account over SSH on 2026-11-10. It is **shared hosting**:

| Approved stack needs | On the shared plan |
|---|---|
| PostgreSQL | Not available. MySQL/MariaDB only. The PHP `pdo_pgsql` driver is also missing, so the app cannot even connect to an external PostgreSQL. |
| Redis (cache, queues) | No Redis server (only the PHP extension). |
| Nginx + PHP-FPM | Not configurable. The host runs its own web server. |
| Queue workers (Horizon) | No supervisor and no long-running processes. |
| Node (build assets) | Not installed. Could be worked around by building in CI. |
| ClamAV, Chromium (PDF) | Not installable. No root access. |

**Recommendation: a Hostinger VPS (KVM 2 or higher: 2 vCPU, 8 GB RAM) running Ubuntu LTS.** It fits the approved stack exactly, stays within the same Hostinger account, and gives full control over security hardening. A second, smaller VPS (or a separately isolated staging stack on the same VPS, see K) covers staging.

The alternative, staying on shared hosting, would mean replacing PostgreSQL with MySQL, Redis with database-backed queues driven by cron, and dropping malware scanning and high-quality PDF generation. That conflicts with the approved stack and weakens the security requirements, so I do not recommend it.

**This does not block Phase 2.** Phases 2–9 are developed locally and in CI. The VPS is needed before staging goes live (end of Phase 3 at the latest, so every later phase is tested on real infrastructure).

The current shared plan can still host a simple public landing page if you want one.

### 2. Object storage provider

The stack requires S3-compatible storage. Options:

| Provider | Data location | Notes |
|---|---|---|
| **AWS S3, `ap-southeast-5` (Malaysia)** | Malaysia | Recommended for PDPA comfort. Data stays in-country. Highest per-GB cost of the three. |
| Cloudflare R2 | Global | No egress fees. Data location not pinned to Malaysia. |
| Wasabi `ap-southeast-1` | Singapore | Low flat pricing; 90-day minimum storage charge. |

Malaysia's PDPA (as amended in 2024) allows cross-border transfers when the destination has comparable protection, so all three are workable. In-country storage is simply the easiest position to defend to families.

### 3. Local development environment (Windows)

Recommended: **Docker Desktop + Laravel Sail** (PostgreSQL, Redis, Mailpit, ClamAV in containers that mirror production). Alternative: native installs of PHP, PostgreSQL and Redis/Memurai. See [11-setup.md](11-setup.md).

### 4. Apple Sign-In

Requires a paid Apple Developer account. Proposed: build Google login in Phase 3, and add Apple login when the account exists (the code path is the same, via Laravel Socialite).

### 5. PDF engine

Family tree PDFs need real SVG rendering. Proposed: Chromium (via Browsershot) on the server. It renders the D3 tree exactly as on screen. It adds a ~300 MB dependency on the VPS. See [04-application-structure.md](04-application-structure.md#key-libraries).

---

## Target versions

Versions are pinned at the start of Phase 2 after checking the current stable releases. Targets:

| Component | Target |
|---|---|
| PHP | 8.4+ |
| Laravel | Latest stable major (13.x) |
| React / TypeScript | 19 / 5.x |
| Inertia.js | Latest stable (2.x or later) |
| Tailwind CSS | 4.x |
| D3.js | 7.x |
| PostgreSQL | 18 |
| Redis | 8 (or Valkey 8) |
| Node.js (build only) | 24 LTS |
| Ubuntu | LTS (24.04 or 26.04) |

## Guiding principles

1. **One person, one record per tree.** Every relationship points at existing member records.
2. **Tenant isolation is enforced in the database, not just in code** (composite foreign keys include `family_id`).
3. **Private by default** for living people.
4. **Single source of truth.** Siblings, generations and birth/death timeline entries are derived, not stored twice.
5. **Every change is attributable** (audit log) and **reviewable** (change requests).
6. **AI suggests, humans decide.**
7. **Shared business logic** (Actions) serves both the Inertia web app and a future `/api/v1` for Flutter/React Native.
