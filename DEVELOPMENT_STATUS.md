# Development status

Last updated: 2026-11-12. Rules for working on the project are in [CLAUDE.md](CLAUDE.md), and the plan is the [roadmap](docs/architecture/10-roadmap.md).

## Overall progress

**Stage:** Phases 1–8 done; Phase 9 (PWA) is next. Everything is built locally in Docker. The staging VPS has not been bought yet, so nothing is deployed. The developer has asked for phases 8–10 to be built back to back, then tested together.

| Phase                                  | State                       | Branch                             | Pushed |
| -------------------------------------- | --------------------------- | ---------------------------------- | ------ |
| 1 Architecture                         | Done                        | `main` (docs)                      | Yes    |
| 2 Foundation                           | Done                        | `feature/phase-2-foundation`       | Yes    |
| 3 Auth & users                         | Done                        | `feature/phase-3-auth-users`       | Yes    |
| 4 Family tree core                     | Done                        | `feature/phase-4-family-tree`      | Yes    |
| 5 Profiles & history                   | Done                        | `feature/phase-5-profiles-history` | Yes    |
| 6 Collaboration                        | Done                        | `feature/phase-6-collaboration`    | Yes    |
| 7 Media & documents                    | Done                        | `feature/phase-7-media`            | Yes    |
| 8 Reports, GEDCOM, relationship finder | Done                        | `feature/phase-8-reports-gedcom`   | Yes    |
| 9 PWA                                  | Not started                 |                                    |        |
| 10 Hardening & launch                  | Not started (needs the VPS) |                                    |        |

The branches are stacked: each one starts from the previous phase. No pull requests have been opened yet.

**Last full test run:** 373 passed, 1 skipped (HEIC), at the end of Phase 8.

## Completed modules

- **Accounts:** registration, email verification, Argon2id passwords, two-factor (TOTP and recovery codes), passkeys, Google sign-in, sessions and their revocation, login history, deactivation and deletion, super-admin area.
- **Families and roles:** five roles (Owner, Admin, Editor, Contributor, Viewer) with a permission matrix; Policies return 404 to outsiders.
- **Tree:**
    - people with fuzzy dates and places;
    - parent, partner and sibling links, with cycle and three-biological-parent rules enforced by database triggers;
    - placeholders and duplicate checks;
    - computed generations;
    - D3 tree in hourglass, descendant and ancestor views, plus a list mode.
- **Profiles and history:**
    - profile pages and an editor;
    - alternate names;
    - living-person privacy;
    - a timeline that merges recorded entries with births, deaths and marriages;
    - family events;
    - dashboard widgets.
- **Collaboration:**
    - invitations (hashed single-use tokens, expiry);
    - role management and ownership transfer;
    - change requests with a diff view, conflict detection and an approval policy;
    - activity feed;
    - append-only audit log;
    - emails.
- **Media:**
    - private storage with a quarantine pipeline: type detected from content, ClamAV scan, re-encoding with GPS and metadata removed, PDF page images;
    - gallery and albums, tagging, profile pictures, family cover;
    - documents, with a view-only option;
    - timeline attachments;
    - storage allowance, with a 30-day purge of deleted files.
- **Phase 8** (see [ADR 0007](docs/adr/0007-phase-8-reports-gedcom-finder.md)):
    - relationship calculator and finder (half, adoptive and step relationships, cousins with degree and removal, more than one answer for cousin marriages, in-laws);
    - advanced member search that respects privacy;
    - GEDCOM 7 export and import, with a preview, duplicate matching and merge-without-overwrite. Exporting a tree and importing it again reproduces the same tree;
    - PDF reports (tree chart, descendants, ancestors, individual summary, directory, timeline), printed by Chromium in the Gotenberg container. Built with the requester's privacy, downloadable only by them, deleted after 7 days.

## In progress

Nothing. Phase 8 is finished.

**Next:**

1. A privacy and security review of Phase 8 with **Fable 5.2**. GEDCOM files, reports and the relationship finder are new ways for family data to leave the app.
2. Phase 9 (PWA) with **Opus 5.5**.

## Pending (by phase)

- **Phase 9 (PWA):**
    - manifest, icons and service worker;
    - iOS install guidance;
    - opt-in encrypted offline snapshot and offline viewer;
    - an outbox that syncs changes made offline, with conflict handling;
    - web push (birthdays, anniversaries, events, approvals); `family_events.notify_members` is acted on here;
    - camera upload;
    - polish of the tree on phones.
- **Phase 10 (needs the VPS):**
    - the rest of the super-admin dashboard;
    - performance with 10,000 people;
    - OWASP ZAP scan and an external penetration test;
    - WCAG 2.2 AA audit;
    - PDPA privacy notice in English and Bahasa Malaysia;
    - backup restore drill;
    - production VPS and launch;
    - user acceptance testing with the family.

## Important technical decisions (details in the ADRs)

- Approving a proposal runs the same Action a direct edit would, so the rules cannot differ between the two paths. The submitter's privacy limits apply, not the reviewer's.
- Files are sent by the app after a permission check, not through signed storage links. There is no separate download domain yet; PDFs are only ever downloaded, and people read them on screen as page images.
- Only the re-encoded JPEG of an image is kept. The original is never stored.
- GEDCOM keeps our extra details in `_` tags declared in the header (`_FREL`/`_MREL` for how a child is related to each parent; `_LIVING`, `_RESEARCH`, `_SUBTYPE`, `_STATUS`, `_SEQ`). An import never overwrites what a family already recorded.
- PDF engine: **Chromium**, approved by the developer on 2026-11-12. It runs in its own Gotenberg container with JavaScript off and nothing loaded from outside, instead of being installed into the app image.
- Slow jobs (media, imports, reports) run on their own Horizon supervisor (900-second timeout; Redis `retry_after` is 960).

## Outstanding bugs and gaps

- HEIC photos are unverified: the container's ImageMagick cannot write HEIC, so the test is skipped. Try a real iPhone photo.
- Contributor uploads always go live after the scan. The planned "require approval for media" switch is not built.
- Contributors cannot propose editing or removing timeline entries or relationships, or adding documents.
- Bahasa Malaysia is not offered; the interface is ready for translations, but none exist.
- The audit log is protected by a trigger. Removing the app's database role's UPDATE and DELETE rights waits for the server.
- The PHPStan level is 7; the architecture document says 8.
- CI (`.github/workflows/tests.yml`) has not been run against Phases 4–8, which have no pull requests.

## Known technical risks

- **Large trees:** the relationship calculator and descendant reports walk the whole graph in memory. They have limits (24 generations, 4,000 lines per person, 3,000 people per report) but are untested at 10,000 people (Phase 10).
- **GEDCOM files from other programs** vary a lot. The parser is tolerant and the import shows a preview, but there are no fixtures from real other software yet.
- **Memory:** ClamAV (about 1.5–2 GB) and Chromium need to be sized for when the VPS is bought.
- **Stacked, unmerged branches:** eight phases deep. Merging into `develop` should happen soon after review.

## Latest activity

- 2026-11-12: Phase 8 finished (PDF reports with Chromium/Gotenberg; ADR 0007) and pushed. `CLAUDE.md` is now tracked in Git.
- 2026-11-12: Phases 4–7 pushed. Phase 8 parts 1 (finder and search) and 2 (GEDCOM) committed; part 3 (PDF reports) in progress. `CLAUDE.md` and this file created.
