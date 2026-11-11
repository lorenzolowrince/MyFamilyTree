# ADR 0002: Phase 3 authentication and users

Date: 2026-11-11 · Status: Proposed (awaiting review)

Decisions taken while building accounts, sign-in security, roles and the admin shell, including where the implementation differs from the Phase 1 architecture documents and why.

## What Phase 3 delivers

| Area                    | Delivered                                                                                                                                                                                                                  |
| ----------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Passwords               | Minimum 12 characters, no forced complexity; breached-password check in production. Argon2id (from Phase 2).                                                                                                               |
| Sign-in protection      | 5 attempts/minute and 20/hour per email + IP, 60/minute per IP. A lockout is shown as a form error and recorded. Registration and password reset: 5/minute per email + IP.                                                 |
| No account enumeration  | Asking for a password-reset link gives the same answer whether or not the email has an account.                                                                                                                            |
| Sign-in history         | `login_activities`: sign-in, sign-out, failed, wrong two-factor code, lockout, password reset, two-factor on/off. Kept 12 months, pruned nightly. Never stores passwords or codes.                                         |
| Devices                 | Settings → Devices & history lists active sessions and lets the user sign out one or all others. Resetting or changing a password signs out other devices.                                                                 |
| Account status          | `active`, `deactivated` (by the user, reversible by signing in), `suspended` (by a Super Administrator). Enforced on every request, however the user signed in.                                                            |
| Account deletion        | 14-day grace period, then the account is erased (see below).                                                                                                                                                               |
| Google sign-in          | Sign up, sign in, connect and disconnect. Hidden until `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` are set.                                                                                                                |
| Families and roles      | `families`, `family_user`, `family_roles`, `family_permissions`, `family_role_permissions`; the matrix is seeded from PHP enums; `FamilyAccess` service and `FamilyPolicy`.                                                |
| Two-factor requirement  | Required for Super Administrators now (admin area and queue dashboard). `User::mustUseTwoFactor()` already covers family Owners and Administrators; the middleware is attached to family routes when they exist (Phase 4). |
| Admin shell             | `/admin` (overview totals, user list, suspend/reinstate). Super Administrators only; everyone else gets 404.                                                                                                               |
| Content-Security-Policy | Nonce-based `script-src` (deferred from Phase 2).                                                                                                                                                                          |
| Cookies                 | `__Host-` prefix on the session cookie wherever `SESSION_SECURE_COOKIE=true`; "remember me" limited to 30 days.                                                                                                            |

## Differences from the architecture documents

| Planned                                                         | Implemented                                              | Reason                                                                                                                                                                                                                                                                                           |
| --------------------------------------------------------------- | -------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `users` columns as listed                                       | One extra column: **`deletion_scheduled_at`**            | The 14-day deletion grace period needs an end date. During it the account is `deactivated`; signing in and choosing "Keep my account" clears the date.                                                                                                                                           |
| Account deletion "deletes personal account data"                | The row is **anonymised and soft-deleted**, not removed  | Later tables point at `users` (`created_by`, `submitted_by`...). Keeping an empty row named "Former member" lets those records stay attributed, as the security document requires, without nullable foreign keys everywhere. Email, name, password, 2FA, passkeys, sessions, history are erased. |
| `family_user.linked_member_id` and `families.root_member_id` FK | Columns exist, **foreign keys added in Phase 4**         | They point at `family_members`, which does not exist yet.                                                                                                                                                                                                                                        |
| Strict CSP with nonces                                          | Nonces for **scripts**; `style-src` allows inline styles | The UI library positions menus, dialogs and toasts with inline `style` attributes, which nonces cannot cover. Styles cannot execute code, so the script rule is the one that blocks XSS.                                                                                                         |
| CSP on every response                                           | **Horizon's dashboard is exempt**                        | It ships pre-built with inline scripts. It is only reachable by Super Administrators with two-factor on.                                                                                                                                                                                         |
| "IP region" in the device list                                  | The **IP address** is shown                              | A region needs a GeoIP database (a new dependency and a data file to keep updated). Can be added in Phase 10 if wanted.                                                                                                                                                                          |
| General limit of 300 requests/minute per signed-in user         | **Not yet applied**                                      | Best tuned against real pages (tree, search) rather than guessed now. Nginx's per-IP limit still sits in front. Planned with the Phase 4 routes.                                                                                                                                                 |
| Avatar upload on the profile                                    | `avatar_path` column only                                | Uploads go through the scanning and re-encoding pipeline built in Phase 7; accepting images before that exists would bypass it.                                                                                                                                                                  |
| Language choice (EN/BM) on the profile                          | `locale` column only; **time zone** is editable          | Offering Bahasa Malaysia before any translations exist would be misleading (i18n is Phase 5, see ADR 0001).                                                                                                                                                                                      |
| Larastan level 8 with the first domain code                     | Still **level 7**                                        | Unchanged from ADR 0001: raised in Phase 4.                                                                                                                                                                                                                                                      |
| Staging VPS provisioned at the end of Phase 3                   | **Not done**                                             | Needs the Hostinger VPS to be purchased (see "Needs your action").                                                                                                                                                                                                                               |

## Other decisions

- **Roles and permissions come from enums** (`App\Enums\FamilyRole`, `FamilyPermission`). `RolesAndPermissionsSeeder` copies them into the three tables and is safe to run on every deployment. Policies read the database through `FamilyAccess`, and a test checks all 29 permissions × 5 roles against the documented matrix.
- **Permission keys.** The matrix's 📝 ("submitted for approval") is one permission, `changes.propose`, held by Editors (for deletions and merges) and Contributors (for additions and edits). "Own linked profile", record visibility, living-person privacy and "only roles below your own" are rules in Policies, not rows in the matrix.
- **Exactly one owner per family** is enforced by a partial unique index, and a user who owns a family cannot be removed at the database level (`ON DELETE RESTRICT`). A user must transfer or delete their families before deleting their account.
- **Outsiders get 404, members get 403.** Someone who is not an active member of a family is told "not found"; a member who lacks a permission is told "forbidden" (they already know the family exists).
- **Super Administrators have no access to family records.** `FamilyPolicy` gives them nothing extra. The owner-granted, time-limited support grant is built in Phase 10.
- **Super Administrator access is granted only from the server's command line:** `php artisan admin:grant person@example.com` (`--revoke` to remove). No screen or form can set the flag (tested).
- **Google sign-in never attaches itself to an existing account.** If the Google email matches an existing account, the person is asked to sign in with their password and connect Google under Settings → Security. Only Google addresses that Google reports as verified can create an account. Accounts with two-factor still get the code prompt.
- **Google-only accounts have no password.** Areas that ask for the password again (Security, Devices, Admin, deactivate/delete) tell such users to set one first through "Forgot your password?".
- **Session ids are never sent to the browser as identifiers.** The device list uses a SHA-256 of the id; the id itself is the cookie's secret.
- **The browser receives a fixed list of user fields** (`HandleInertiaRequests::user`), not the model, so a new column cannot leak by accident.
- **Known limitation: the registration form still reveals whether an email is registered** ("The email has already been taken"). Closing this needs a different sign-up flow (always answer "check your inbox"). It is rate-limited to 5 attempts/minute meanwhile; worth deciding before launch.

## Operations

- Every deployment runs `php artisan db:seed --class=RolesAndPermissionsSeeder --force` after `migrate`.
- The scheduler (`php artisan schedule:run` every minute) runs: `accounts:erase-expired` (03:10), sign-in history pruning (03:20), expired reset-token cleanup (03:30).
- Production `.env`: `SESSION_SECURE_COOKIE=true`, `SESSION_DOMAIN=null`, `SESSION_PATH=/`.

## Needs your action

1. **Staging VPS.** Purchase the Hostinger VPS (KVM 2 or higher, Ubuntu LTS) approved in Phase 1 so staging can be provisioned and this phase deployed.
2. **Google sign-in.** Create an OAuth client in Google Cloud Console (redirect URI `{APP_URL}/auth/google/callback`) and supply the client id and secret per environment. Until then the button stays hidden.
3. **Pull requests.** Open and merge the Phase 2 PR (`feature/phase-2-foundation` → `develop`), then the Phase 3 PR. The Phase 3 branch is stacked on Phase 2.
4. Still open from ADR 0001: branch protection for `main` and `develop`.
