# ADR 0005: Phase 6 collaboration

Date: 2026-11-12 · Status: Proposed (awaiting review)

## What Phase 6 delivers

| Area          | Delivered                                                                                                                                                                                                                                  |
| ------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Invitations   | Invite by email with a role, an optional message and an optional "this is you in the tree" link. The emailed link holds a 64-character token; only its SHA-256 hash is stored. Links expire after 7 days, work once, and can be cancelled. |
| People        | A page listing everyone with access: change a role, link an account to a person in the tree, remove someone, leave the family, transfer ownership (password required).                                                                     |
| Proposals     | Changes by people who need approval are stored as change requests: add a person, edit a person, remove a person, add a relative, add a timeline entry. Reviewers see current and proposed values side by side and approve or decline.      |
| Policy        | Two family settings: "Editors' changes also need approval" and "Contributors publish directly".                                                                                                                                            |
| History       | An activity feed in plain sentences (Editors and above, also on the dashboard) and a field-level audit log with before and after values, address and time (Owner and Administrators).                                                      |
| Notifications | Queued email for: an invitation, a new member joining (to the Owner and Administrators), a proposal waiting (to the Owner and Administrators), and the decision on a proposal (to the person who made it).                                 |

## Differences from the architecture documents

| Planned                                                                                      | Implemented                                                        | Reason                                                                                                                                                       |
| -------------------------------------------------------------------------------------------- | ------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Change requests for photos, documents                                                        | Table allows them; **none are created yet**                        | Media is Phase 7.                                                                                                                                            |
| Proposals to edit or remove a timeline entry, or to edit or remove a link between two people | **Not offered**: only people who may do these directly can do them | Kept to the proposals a Contributor actually needs first. The table and review page need no change to add them.                                              |
| `family_events.notify_members`                                                               | Still stored, **not acted on**                                     | Event announcements are sent with the other reminders (birthdays, anniversaries) in Phase 9.                                                                 |
| Audit log protected by database role                                                         | Protected by a **trigger** that refuses every UPDATE and DELETE    | Works the same in local Docker, tests and production. Removing the application role's UPDATE and DELETE rights is still done when the server is provisioned. |

## Other decisions

- **Approval runs the same code as a direct edit.** A proposal stores the validated input; approving it calls the Action a direct edit would call. Cycle checks, privacy limits and optimistic locking therefore cannot differ between the two paths.
- **A proposal is applied with the submitter's limits, not the reviewer's.** A Contributor's proposal cannot set research notes or visibility just because an Owner approved it.
- **Nobody decides their own proposal**, and a reviewer can only approve what they could have done directly. When Editors need approval, Editors cannot approve either. Removing a person is approved by the Owner or an Administrator only.
- **A record that changed after the proposal was made** is flagged as a conflict. The first approval is refused with an explanation; approving again applies only the proposed fields and keeps the newer values of the others.
- **A proposal that the tree no longer allows** (for example it would make someone their own ancestor) stays pending and shows the reason.
- **Proposals and activity carry the visibility of the people they concern.** A reviewer who may not see a private person does not see proposals or activity about them.
- **An invitation is bound to its email address.** A forwarded link does not admit a different account, and the account must have a verified email. A new invitation to the same address cancels the earlier one.
- **Email addresses are stored in lower case.** An address typed with capitals is accepted, not refused.
- **Roles:** a person can only manage people below their own role and only grant roles below their own. Nobody can be invited or promoted to Owner; ownership is transferred, and there is always exactly one Owner. The Owner cannot leave.
- **Email addresses of other members** are shown only to people who may invite.
- **The audit log has no foreign keys**, so it outlives the records, accounts and families it describes. Tokens and their hashes are never written to it.
- **Diff rows follow the order of the edit form.** PostgreSQL `jsonb` does not keep key order, so the order is restored when a proposal is shown.
