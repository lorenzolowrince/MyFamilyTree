# ADR 0003: Phase 4 family tree core

Date: 2026-11-11 · Status: Proposed (awaiting review)

Decisions taken while building families, people, relationships and the tree view, including where the implementation differs from the Phase 1 architecture documents and why.

Note on process: from this phase on, the phases are built back to back in local Docker and reviewed together at the end, at the owner's request (no staging server yet). Each phase still gets its own branch, commit and decision record.

## What Phase 4 delivers

| Area              | Delivered                                                                                                                                                                                                                                                     |
| ----------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Families          | Create a family (the creator becomes Owner and, optionally, the first person), family list, name/description settings, cover image.                                                                                                                           |
| People            | `family_members` with fuzzy dates, places, living status, visibility, optimistic locking. Add, edit, remove (soft delete, together with the person's links).                                                                                                  |
| Relationships     | `family_relationships` with the constraints and the `enforce_parent_child_rules` trigger from the design. "Add parent / child / partner / sibling" with a duplicate check first. Siblings without known parents are held together by an "Unknown parent".     |
| Impossible trees  | Cycles, a third biological parent, parent-and-partner, self-links, cross-family links and duplicate parent links are refused twice: by the application with a message naming the people, and by the database even if the application is bypassed (tested).    |
| Generations       | Derived by a queued job after every change to the tree's shape.                                                                                                                                                                                               |
| Tree view         | Hourglass, descendants and ancestors (pedigree) views; pan, pinch/wheel zoom, fit; fold/unfold; "more" markers that re-centre the tree; multiple marriages side by side; dashed lines for non-biological links; path highlight; an accessible list mode.      |
| Search            | Member list with name search: partial names, close spellings ("Lidow" finds "Lidau") and accents ignored.                                                                                                                                                     |
| Privacy           | Living people's dates, places and details are withheld from Contributors and Viewers; `restricted` and `private` records are invisible to roles that may not see them (lists, search, duplicate check, profile) and appear in the tree as "Private relative". |
| Local environment | `horizon` and `scheduler` containers, so background jobs and scheduled tasks run locally. A demo family (`DemoFamilySeeder`) is seeded in local development only.                                                                                             |

## Differences from the architecture documents

| Planned                                                      | Implemented                                                                                      | Reason                                                                                                                                                                                                                                                                  |
| ------------------------------------------------------------ | ------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| D3 computes the layout and draws the tree inside one SVG     | **Our own layout function** (pure TypeScript); React draws the cards; **d3-zoom** pans and zooms | `d3.tree()` lays out one node per person; a family tree needs couples of varying width with each couple's children beneath them. Drawing cards as real HTML buttons (not SVG `foreignObject`) makes them keyboard- and screen-reader-accessible and avoids Safari bugs. |
| Living-person privacy and field masking in Phase 5           | **Built now**                                                                                    | Phase 4 already sends people to the browser. Shipping names and dates first and masking them a phase later would leak data in between.                                                                                                                                  |
| `BelongsToFamily` trait with a global scope from the route   | **Explicit scoping**: scoped route bindings, `inFamily()` query scope, composite foreign keys    | A global scope that reads the current route does nothing in queued jobs and console commands, and hides the tenant filter from the reader. Three explicit layers are easier to audit; tests prove every route returns 404 for another family's records.                 |
| Contributors' additions and edits are submitted for approval | Contributors **cannot add or edit yet** (except their own linked profile)                        | Change requests are Phase 6. Until then the server refuses with a message saying proposals are not available yet.                                                                                                                                                       |
| Cover image as `families.cover_photo_id`                     | A private file path in `families.settings`                                                       | "Temporary local storage until Phase 7", as the roadmap says. Validated as a real image, stored under a random name on the private disk, served only to members. It moves into the media pipeline in Phase 7.                                                           |
| Two-factor required for Owners and Administrators            | Required **except when `APP_ENV=local`**                                                         | So the app can be tried on a developer's PC without setting up an authenticator first. `FAMILY_ADMIN_TWO_FACTOR=true` turns it on locally. Super Administrators always need it.                                                                                         |
| Larastan level 8 with the first domain code                  | Still **level 7**                                                                                | Level 8 reports 26 "possibly null user" findings in Phase 3 controllers. Raising it is tidy-up work scheduled for Phase 10 (hardening), not a behaviour change.                                                                                                         |
| General limit of 300 requests/minute per signed-in user      | Per-route limits on search, duplicate check, family creation and cover upload                    | The general limit is added with the API surface in Phase 10 when it can be load-tested.                                                                                                                                                                                 |
| Merging a placeholder parent into a real person              | Renaming the "Unknown parent" **turns it into a real person**                                    | Full merge of two existing records arrives with duplicate handling in Phase 8.                                                                                                                                                                                          |

## Other decisions

- **`graph_version` is bumped by the models themselves** (every save or delete of a relationship, every add/remove of a person), so no code path can forget. The cached graph is keyed by it and read fresh from the database each request.
- **Every change to a tree's shape runs inside `GraphChange`**: it locks the family row (one structural change at a time per family), turns a database refusal into a friendly form error, and queues the generation recalculation.
- **Adding a relative is one transaction.** If the link is refused, the new person is not left behind.
- **Which marriage a child belongs to is derived**, as designed: the other parent who is a partner, else the other biological parent. A child with one known parent hangs directly under that parent.
- **A person reached twice in one picture** (cousin marriage) is drawn in full once and as a "See also" card the second time.
- **Hidden people keep the tree's shape.** Their card says "Private relative" and carries no name, dates or gender. Centring the tree on a hidden person falls back to a visible one.
- **Research notes and visibility are editors-and-above fields**, even when a Contributor edits their own profile.
- **Families are addressed by slug** (`/families/keluarga-lidau/tree`), people by UUID.
- **Death date implies deceased**: enforced by a CHECK constraint and applied automatically by the form.
- **Places are deduplicated without regard to case** ("Ranau" and "ranau" are one place per family).

## Local testing

`docker compose up -d` starts the app, PostgreSQL, Redis, Mailpit, object storage, the queue worker and the scheduler. `php artisan migrate --seed` creates the test account (`test@example.com` / `password-for-local-dev`) and the demo family. See the README.
