# G. User Roles and Permissions

## Two levels of roles

1. **Platform level:** `Super Administrator`, a flag on `users`. Platform staff who run the service. They manage accounts and system health. **They cannot browse a family's private records by default.** Accessing a family's data (e.g. for a support case) requires a "support access" grant from that family's owner, is time-limited (24 h), and is audit-logged. This is privacy-by-design and matters for PDPA.
2. **Family level:** one role per user per family, stored in `family_user`. The same person can be an Owner in their own tree and a Viewer in their in-laws' tree.

## Family roles

| Role | Intended for |
|---|---|
| **Owner** | The person who created the tree. Exactly one per family. |
| **Administrator** | Trusted relatives who help run the tree. |
| **Editor** | Relatives who keep records accurate. Edits apply immediately. |
| **Contributor** | Relatives who add information. Their changes go to the approval queue. |
| **Viewer** | Relatives who just want to browse. |

## Permissions matrix

✅ allowed · 📝 allowed but submitted as a change request for approval · 👤 own records only · ❌ not allowed

| Permission | Owner | Admin | Editor | Contributor | Viewer |
|---|:-:|:-:|:-:|:-:|:-:|
| **Tree** | | | | | |
| View tree, deceased members' profiles | ✅ | ✅ | ✅ | ✅ | ✅ |
| View living members' basic info (name, photo, relationships) | ✅ | ✅ | ✅ | ✅ | ✅ |
| View living members' sensitive info (exact birth date, place, contact notes) | ✅ | ✅ | ✅ | ❌ | ❌ |
| View `restricted` records | ✅ | ✅ | ✅ | ❌ | ❌ |
| View `private` records | ✅ | ✅ | ❌ | ❌ | ❌ |
| Add members / relationships | ✅ | ✅ | ✅ | 📝 | ❌ |
| Edit members / relationships | ✅ | ✅ | ✅ | 📝 (own linked profile: ✅) | 👤 own linked profile: 📝 |
| Delete / merge members | ✅ | ✅ | ❌ (📝) | ❌ | ❌ |
| Add timeline entries | ✅ | ✅ | ✅ | 📝 | ❌ |
| **Media** | | | | | |
| View photos / documents | ✅ | ✅ | ✅ | ✅ | ✅ (per file visibility) |
| Upload photos / documents | ✅ | ✅ | ✅ | ✅ (goes live after scan; admins can switch to approval) | ❌ |
| Edit captions, tags, albums | ✅ | ✅ | ✅ | 👤 | ❌ |
| Download documents | ✅ | ✅ | ✅ | ✅ (if `downloadable`) | ✅ (if `downloadable`) |
| Delete any media | ✅ | ✅ | ❌ | 👤 | ❌ |
| **Collaboration** | | | | | |
| Review / approve change requests | ✅ | ✅ | ✅ | ❌ | ❌ |
| Invite people | ✅ | ✅ (up to Editor) | ❌ | ❌ | ❌ |
| Change members' roles | ✅ | ✅ (below Admin) | ❌ | ❌ | ❌ |
| Remove people from the family | ✅ | ✅ (below Admin) | ❌ | ❌ | ❌ |
| **Reports and data** | | | | | |
| Generate PDF reports | ✅ | ✅ | ✅ | ✅ (without restricted data) | ❌ |
| GEDCOM export | ✅ | ✅ | ❌ | ❌ | ❌ |
| GEDCOM import | ✅ | ✅ | ❌ | ❌ | ❌ |
| View audit history of the family | ✅ | ✅ | ✅ (records only) | ❌ | ❌ |
| **Family settings** | | | | | |
| Edit name, cover, privacy defaults, approval policy | ✅ | ✅ | ❌ | ❌ | ❌ |
| Grant support access to platform staff | ✅ | ❌ | ❌ | ❌ | ❌ |
| Transfer ownership | ✅ | ❌ | ❌ | ❌ | ❌ |
| Delete the family | ✅ (with re-authentication + 7-day grace period) | ❌ | ❌ | ❌ | ❌ |

## Rules that apply on top of the matrix

- **Nobody can grant a role equal to or higher than their own**, except the Owner transferring ownership.
- **Living-person privacy:** a person is treated as living if `living_status = living`, or `unknown` and born less than 110 years ago (or no birth date at all). Contributors and Viewers see such people's name, photo and relationships, but not exact dates, places or notes. A living person who has their own linked account can choose to show more.
- **Record visibility** (`family`, `restricted`, `private`) narrows access further, never widens it.
- **The person themselves:** a user linked to their own tree profile can always see it and propose changes to it, even as a Viewer.
- **Admins can change the approval policy**, e.g. require approval for Editors too, or let trusted Contributors publish directly.

## How it is enforced

1. `family_role_permissions` (seeded) defines the matrix in the database.
2. Each model has a Laravel **Policy** that checks: is the user an active member of this family → does their role have the permission → does record visibility allow it → do living-person rules allow it.
3. **Resources** strip any field the viewer is not allowed to see before data leaves the server.
4. **Search, reports, exports and the relationship finder** use the same visibility service, so a hidden person cannot be discovered through a side door (e.g. a relationship path through a private person shows "a private relative").
5. **Tests:** every route has an automated test proving that a user from another family gets `404` (not `403`, so the record's existence is not revealed), and one test per matrix cell.
