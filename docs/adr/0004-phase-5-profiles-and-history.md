# ADR 0004: Phase 5 profiles and history

Date: 2026-11-11 · Status: Proposed (awaiting review)

## What Phase 5 delivers

| Area      | Delivered                                                                                                                                                                                                                         |
| --------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Profiles  | The full profile page and editor (from Phase 4) gain **other names** (birth, married, religious, alias, romanised) and the person's own **life events**. Other names are found by search.                                         |
| Timeline  | `family_timeline` and `timeline_participants`. The timeline page merges recorded entries with births, deaths and marriages read live from people and relationships, grouped by decade, with filters by kind, years and person.    |
| Events    | `family_events`: reunions, celebrations, memorials, announcements. Entered in the person's own time zone, stored in UTC. A past event can be added to the timeline once.                                                          |
| Dashboard | For the family last opened: people, generations, living and deceased counts; upcoming birthdays and anniversaries (60 days); upcoming events; recently added people.                                                              |
| Privacy   | Living people's births and marriages, hidden people and restricted entries never reach a viewer who may not see them: on the timeline, on a profile's life events, and in the birthday and anniversary widgets (tested per role). |

## Differences from the architecture documents

| Planned                                              | Implemented                                              | Reason                                                                                                                                                                                                |
| ---------------------------------------------------- | -------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Living-person privacy and field masking              | Delivered in Phase 4                                     | See ADR 0003.                                                                                                                                                                                         |
| Biography as sanitised rich text (HTML allow-list)   | **Plain text**, shown with line breaks                   | Plain text cannot carry script, so there is nothing to sanitise and no `SafeHtml` exception to the "no raw HTML" rule. Formatting can be added later with HTML Purifier without changing stored data. |
| Activity feed on the dashboard                       | "Recently added" people                                  | The activity log is built in Phase 6; the feed is added there.                                                                                                                                        |
| `timeline_participants (timeline_id, member_id)`     | Also carries **`family_id`**                             | Needed for composite foreign keys, so an entry can never involve a person from another family.                                                                                                        |
| Who may create events (not in the permission matrix) | Whoever may add timeline entries (**Editors and above**) | Events become timeline entries, so the same permission governs both. Everyone in the family can see events.                                                                                           |
| `family_events.notify_members`                       | Stored, **not yet acted on**                             | Notifications are Phase 6 (email) and Phase 9 (push).                                                                                                                                                 |
| Language choice (EN/BM)                              | Still not offered                                        | No translations exist yet; offering Bahasa Malaysia would show an English interface. Translation is listed as after-launch work in the roadmap.                                                       |

## Other decisions

- **Births, deaths and marriages are never copied into the timeline table.** A date corrected on a person is correct on the timeline at once.
- **Birthdays for Viewers and Contributors.** The matrix withholds living people's exact birth dates from these roles, so the birthday and anniversary widgets are empty for them. If the family wants everyone to see birthdays (day and month only), that is a deliberate privacy choice to make in the family's settings later, not a default.
- **An editor cannot attach, detach or reveal people they may not see** on a timeline entry; hidden participants are kept as they were when the entry is saved.
- **Nobody can set a visibility level they could not see themselves.**
- **The timeline page shows at most 500 moments** and says so; the year filter narrows it.
- **29 February birthdays** fall on 28 February in other years.
