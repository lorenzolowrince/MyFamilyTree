# ADR 0008: Phase 9 installable app (PWA)

Date: 2026-11-13 · Status: In progress (part 1 of Phase 9)

## What part 1 delivers

| Area           | Delivered                                                                                                                                                                                                   |
| -------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Manifest       | `public/manifest.webmanifest`: name, short name, `standalone`, start at the dashboard, icons at 192 and 512 px in both `any` and `maskable` versions. Edge reports no installability errors.                |
| Icons          | The app's own family-tree mark replaces the starter kit's logo: favicon (SVG and ICO), Apple touch icon (180 px) and the manifest icons. Sources in `resources/icons/`, rendered with `rsvg-convert`.       |
| Service worker | `public/sw.js`, registered from `resources/js/lib/pwa.ts` in production builds only.                                                                                                                        |
| Offline page   | `public/offline.html`: shown when a page cannot be reached. It holds no personal data and runs no script. Light and dark.                                                                                   |
| Install guide  | "Install the app" at the bottom of the sidebar. In Chrome, Edge and on Android it opens the browser's own install dialog. On iPhone and iPad it explains Share → Add to Home Screen. Hidden once installed. |
| Home Screen    | Apple and Android meta tags (title "Family Tree", status bar, light and dark `theme-color`).                                                                                                                |

## What the service worker caches

| Content                                                      | Treatment                                                                                                              |
| ------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------- |
| Offline page, manifest, favicon, two icons                   | Cached when the worker installs.                                                                                       |
| Compiled JS, CSS and fonts in `/build/assets/`               | Cached on first use; unused files removed by comparing with `/build/manifest.json` (on activation and at most hourly). |
| Pages, Inertia responses, JSON, photos, documents, downloads | **Never cached.** Always from the network.                                                                             |
| Requests that are not `GET`, or go to another site           | Not touched.                                                                                                           |

Verified in headless Edge on 2026-11-13: after visiting the dashboard, the family tree and the member list, the caches held only the five static files and 33 compiled files. With the network cut, a family page showed the offline page; with it back, the page loaded normally.

## Differences from the architecture documents

| Planned                                                                 | Implemented                                                        | Reason                                                                                                                                                                                                                                                        |
| ----------------------------------------------------------------------- | ------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Service worker built with `vite-plugin-pwa` (Workbox, `injectManifest`) | A hand-written worker of about 150 lines, **no new dependency**    | The project builds with the `vite-plus` fork of Vite, which that plugin does not test against. The worker only needs two simple strategies. Workbox can still be adopted when the offline viewer needs its routing, after checking it works with `vite-plus`. |
| Pre-cache the app shell and all compiled assets                         | Compiled assets are cached **when first used**, not all at install | Nothing needs to work offline yet except the offline page. Pre-caching becomes necessary with the offline viewer, which will list the files it needs.                                                                                                         |
| "A new version is available, reload"                                    | The new worker takes over at once (`skipWaiting`)                  | Pages are never cached and compiled files have unique names, so an old page cannot mix with a new worker. Inertia already reloads the page when the build changes. The prompt becomes useful when the offline viewer is cached.                               |
| Logout wipes caches                                                     | Nothing to wipe yet                                                | The caches hold only the app's code. Wiping on logout is part of the offline snapshot design.                                                                                                                                                                 |

## Still to come in Phase 9

- Design first (Fable 5.2): the encrypted offline snapshot (what is stored, the key, expiry, wiping on logout and on removal from a family) and offline editing with the sync outbox and conflicts.
- Then (Opus 5.5): the offline viewer, the outbox, web push (VAPID, `family_events.notify_members`), camera upload and resizing on the device, and mobile tree polish.

## Operating notes

- The production web server must serve `/sw.js` with `Cache-Control: no-cache` and must not send it from a CDN with a long cache (Phase 10).
- Change `VERSION` in `public/sw.js` when its list of static files changes.
- Service workers need HTTPS, except on `localhost`.

## Part 2: push notifications (2026-11-13)

Built by Opus 5.5 on `feature/pwa-push`. Email stays the main channel; push is an extra on devices where the person turns it on.

| Area             | Delivered                                                                                                                                                                                                                                                                      |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Package          | `laravel-notification-channels/webpush` 13.0.1 (named in the architecture; MIT; supports Laravel 13; `composer audit` clean). It uses `minishlink/web-push`.                                                                                                                   |
| Settings         | Settings → Notifications: turn push on or off for this device, send a test, and choose topics: Approvals, Family events, Birthdays and anniversaries.                                                                                                                          |
| Approvals        | `ChangeRequestSubmitted` and `ChangeRequestDecided` also push, to those who want it.                                                                                                                                                                                           |
| Events           | `family_events.notify_members` is now acted on. A new event is announced by email to the rest of the family, and by push where on. The event form shows the setting ("Let the family know"). Editing does not announce again.                                                  |
| Morning reminder | `notifications:morning`, hourly: at 08:00 in each person's own time zone, one push per family with today's birthdays and anniversaries (the dashboard's rules, so only those whose dates the person may see) and tomorrow's announced events. Once per day, even if run twice. |
| Service worker   | Shows the message; a tap opens the link only if it is inside this app, otherwise the dashboard.                                                                                                                                                                                |
| iPhone and iPad  | Push is offered only in the app added to the Home Screen; the page explains this.                                                                                                                                                                                              |

### Security and privacy

- **SSRF:** the server sends requests to the address a browser gives. Only HTTPS addresses at the browsers' push services are accepted (`config/webpush.php` `allowed_hosts`), checked in `PushEndpoint` and by a database CHECK. IP addresses, other ports, credentials in the address and look-alike hosts are refused (tested).
- **Locked screens:** push texts name the family and what happened. Approval messages name no person. Morning reminders name people only for those who may see their dates.
- **Shared devices:** logging out unsubscribes the browser. Revoking sessions (log out other devices, password reset, deactivation, suspension) and erasing an account delete every subscription. A device used by two accounts belongs to the last one that turned push on.
- **No tracking:** the settings page receives SHA-256 hashes of the person's own subscriptions, not the addresses.
- The VAPID keys are secrets in `.env` (`php artisan webpush:vapid` once per server). Changing them ends every subscription silently.

### Verified

- Tests: `tests/Feature/Settings/PushNotificationsTest.php` (22 tests, including the address checks, each topic, the morning reminder per role and time zone, and revocation).
- Headless Edge: turning push on subscribed to the Windows push service; the server sent a real message and the service **accepted it (HTTP 201)**, which proves the VAPID signature and encryption. Messages delivered through DevTools were shown by the service worker; an outside link became the dashboard; a malformed message did not break it. Logging out unsubscribed the browser.
- **Not verified:** a message actually appearing on a phone. Headless Edge is not connected to the Windows notification service. Try "Send a test" on a real Android phone and an iPhone (app added to the Home Screen) once the app is on HTTPS.

### Differences from the plan

| Planned                                                                 | Implemented                                          | Reason                                                                                                         |
| ----------------------------------------------------------------------- | ---------------------------------------------------- | -------------------------------------------------------------------------------------------------------------- |
| `notification_preferences (user_id, family_id, channel, type, enabled)` | `users.push_topics` (jsonb list; null = all topics)  | Only push has choices so far, and none per family. The table can come when email or per-family choices do.     |
| The package's own migration                                             | `2026_11_17_000001_create_push_subscriptions_table`  | The package's uses a numeric owner id (users have UUIDs) and MySQL-only column options; ours adds CHECKs.      |
| Push for approvals, events, birthdays, anniversaries                    | As planned, plus an email when an event is announced | `notify_members` meant "tell the family"; without email, iPhone users without the installed app heard nothing. |

### Operating notes

- On the server: set `VAPID_SUBJECT` (a `mailto:` or `https:` contact), generate the keys once, and keep them with the other secrets.
- The scheduler must run (`notifications:morning` hourly at :05). Restart Horizon after changing notification code.
- Local note: every request takes about 6 seconds on this PC because the code is on a Windows folder shared into Docker. This is not caused by the app and will not happen on the server.

## Part 3: camera upload (2026-11-13)

Built by Opus 5.5 on `feature/pwa-camera-upload`. Applies to both photo and document uploads.

| Area               | Delivered                                                                                                                                                                                                                                       |
| ------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Take a photo       | On phones and tablets, "Take a photo" opens the camera (`capture="environment"`). Camera photos get a readable name ("Photo 2026-11-13 09:30.jpg"). Files can be added in several goes and removed from the list before sending.                |
| Smaller on device  | Photos whose longest edge is over 4096 px (the size the server keeps) are shrunk in the browser before sending: a 13.5 MB, 6000 px test photo went as 4 MB. Smaller photos, HEIC, PDF and TIFF are sent unchanged.                              |
| Location           | A shrunk photo is drawn again, so its GPS position and other hidden data **never leave the phone**. Only the date taken is written back (a minimal EXIF block), because the gallery uses it. The server still removes metadata from every file. |
| Dropped connection | Files are sent one at a time. A file interrupted by a lost connection is sent again when the device is back online (up to 5 tries); the dialog says what it is waiting for. Files not sent stay listed for another try.                         |
| Server             | The photo and document upload routes answer JSON (`stored`, `problems`) when asked, so the page can show one summary. The checks are unchanged. Rate limit raised from 60 to 200 requests per 10 minutes, as each file is now its own request.  |

### Verified

- Tests: JSON answers (stored, refused by content with 422, validation, Viewer 403, outsider 404, no flash left behind) and a JPEG carrying only the browser-written date: the server reads the date taken from it.
- Headless Edge: the 6000 px photo with GPS and a rotation tag uploaded as a 4 MB, upright 3072×4096 JPEG; the server processed it to ready with its date taken (15 Jul 1998). With the network cut during upload, the dialog waited, resumed when the network returned, and the photo was stored once. The camera button appears at phone width (dark mode checked) and not on a desktop.
- **Not verified:** the camera on a real phone, and very large (200-megapixel) photos, which a phone may not have the memory to shrink; then the photo is sent unchanged and handled by the server.

### Differences from the plan

| Planned                                             | Implemented                                                        | Reason                                                                                                                                                                                                           |
| --------------------------------------------------- | ------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| "Sent in chunks so a dropped connection can resume" | Each **file** is its own request and is retried after reconnecting | Files are at most 25 MB (photos) and 50 MB (documents), and large photos are shrunk first. Chunked upload needs a new server protocol and storage for partial files; it can be added if real use shows failures. |
| Resize large uploads on the device                  | Only above 4096 px, the size the server keeps                      | Below that, shrinking would lose quality the server would have kept.                                                                                                                                             |

## Proposed design: offline copy and sync (2026-11-13, awaiting the developer's approval)

Designed by Fable 5.2 for implementation by Opus 5.5. Nothing in this section is built yet. It replaces "Security of local data" and "Offline editing and synchronisation" in [07-pwa.md](../architecture/07-pwa.md) where the two differ.

### Who this protects against

| Risk                                                                    | Answer in this design                                                                                              |
| ----------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------ |
| A. Someone else uses the unlocked phone or a shared family tablet       | An offline passcode. Without it the copy cannot be read. Ten wrong tries erase it.                                 |
| B. Someone copies the browser's files (backup, repair shop, lost phone) | The data key is never stored in usable form; it is locked by the passcode.                                         |
| C. Someone is removed from the family, or demoted, and keeps the phone  | The copy is erased the next time the device is online. Offline it stops opening after 30 days. See "Limits".       |
| D. A script injected into the app                                       | The existing Content Security Policy. The offline viewer loads scripts from this site only, with no inline script. |
| E. Malware on the phone, or the owner of the phone taking screenshots   | Not solvable by a web app. This is why the copy is opt-in and holds less than the person can see online.           |

**The plan's key storage is not enough.** 07-pwa.md stores a "non-extractable" key in IndexedDB beside the data. The browser writes that key to the same disk folder as the data, so it does not stop risk B, and it does nothing for risk A. The passcode below is what gives real protection.

### What is stored on the device

Built by the existing presenters with a **narrowed viewer**: the person's own `MemberViewer` with `members.view_restricted` and `members.view_private` taken away. No second set of privacy rules.

| Included                                                                                         | Never included                                                                                |
| ------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------- |
| People at `family` visibility, as `MemberPresenter::profile` shows them to this person           | Restricted and private people (they appear as "Private relative" so the tree keeps its shape) |
| Living people's dates and places **only** if this person sees them online                        | Research notes, for every role                                                                |
| Relationships between included people; a hidden person's links carry no kind (as GEDCOM sharing) | Documents, gallery photos, albums                                                             |
| Timeline entries at `family` visibility, without attachments                                     | Proposals, activity, audit log, invitations, other members' email addresses                   |
| Profile pictures at the 400 px size                                                              | Anything from a family whose Owner has switched offline copies off                            |

- One encrypted copy per family per device. Every refresh **replaces the whole copy**; there are no partial updates, so a person who was hidden since the last refresh cannot linger.
- The server compares a SHA-256 of the copy with the one the device holds and answers "unchanged" when equal.

### Encryption and unlocking

1. Turning offline on asks for an **offline passcode** (at least 6 digits; longer text allowed).
2. The device makes a random 256-bit AES-GCM data key. Records are encrypted in blocks, each with its own random 96-bit IV and the family id, schema version and block number as additional data.
3. The data key is wrapped with a key derived from the passcode (PBKDF2-SHA-256, 600,000 rounds, 16-byte random salt). Only the wrapped key, salt and round count are stored.
4. Unlocking keeps the data key in memory only. It is dropped after 5 minutes in the background or when the app closes.
5. Ten wrong passcodes erase the copy. A forgotten passcode means turning offline off and on again.
6. Profile pictures are encrypted in IndexedDB too, not kept in Cache Storage (which is not encrypted).

Later option, not in v1: unlock with the device's fingerprint or face through a passkey (WebAuthn PRF). It needs testing on real iPhones and Android phones first.

### Server side (all additive)

| Piece                                                            | Purpose                                                                                                                                                                |
| ---------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `offline_devices` table                                          | `id`, `user_id`, `family_id`, `label`, `status_token_hash`, `made_as` (role), `snapshot_hash`, `last_synced_at`, `revoked_at`. One row per family per device.          |
| `offline_sync_items` table                                       | `id` (made on the device, the idempotency key), `device_id`, `user_id`, `family_id`, `type`, `result`, `change_request_id`. A repeated item returns its stored result. |
| `timeline_entries.version`                                       | Only if offline editing of existing entries is wanted (decision 4).                                                                                                    |
| `GET families/{family}/offline/snapshot`                         | Signed-in session. Policy: member of the family, `tree.view`, family setting allows offline. Rate-limited. Outsiders 404.                                              |
| `POST families/{family}/offline/devices` and `DELETE …/{device}` | Turn offline on or off for this device. Written to the audit log (`offline.enabled`, `offline.removed`). Refreshes update `last_synced_at` only.                       |
| `POST offline/devices/status`                                    | No session needed: the device sends its status token and learns only `ok` or `wipe`. The token grants nothing else. Rate-limited.                                      |
| `POST families/{family}/offline/sync`                            | Signed-in session and CSRF token. Up to 200 items, each in its own transaction, in order.                                                                              |
| Settings → Sessions                                              | Lists the person's offline copies, with "Remove".                                                                                                                      |
| Family settings                                                  | "Members may keep an offline copy" (Owner).                                                                                                                            |

### When a copy is erased

| Event                                                                       | What happens                                                                                   |
| --------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------- |
| Log out on this device                                                      | Erased before the logout request; the logout response also sends `Clear-Site-Data: "storage"`. |
| Removed from the family, or the Owner switches offline off                  | Snapshot request answers 404 or 403; the device erases that family.                            |
| Role changed                                                                | The next refresh replaces the copy with one built for the new role.                            |
| "Log out other devices", password reset, deactivation, suspension, "Remove" | The device rows are revoked; the status check answers `wipe`.                                  |
| 30 days without a successful refresh                                        | The copy stops opening and is erased.                                                          |
| Session only timed out (2 hours idle)                                       | Kept, still behind the passcode. Refreshing needs signing in again.                            |

### Sending offline edits

**What can be edited offline:** fields of an existing person that the copy showed, and new timeline entries. Not offline: adding people, relationships, uploads, deleting.

Each queued edit (encrypted like the copy) holds: its own id, the family, the target, the record's `version` when edited, and for **each changed field only** the value before and after.

Sending whole records is forbidden: the copy holds blanks for hidden fields, and a whole record would erase them. That bug existed in the online editor until `fix/hidden-fields-in-edits`.

The server handles each item with the person's rights **at the moment of sending**, not when the edit was made:

| Situation                                                   | Result                                                                                                          |
| ----------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| May edit directly                                           | `MemberPrivacy::editableFields`, the same validation as `MemberRequest`, then `UpdateMember`. Result `applied`. |
| Needs approval                                              | `ChangeRequests::proposeMemberUpdate` with the base version. Result `proposed`.                                 |
| Role no longer allows it                                    | `rejected`, with the reason. Nothing is applied.                                                                |
| The person can no longer be seen, or is gone                | `rejected`, with one message for both cases, so nothing is revealed.                                            |
| The record changed, but not in the fields this edit touches | Applied on top of the current record. Nothing anyone else wrote is replaced.                                    |
| The record changed in a field this edit touches             | `conflict`: the device shows mine and theirs for that field and the person chooses. Nothing is overwritten.     |
| The same item arrives twice                                 | The stored result is returned.                                                                                  |

- Edits go through the same Actions, so audit and history are written as usual, at server time. The device's own time is kept only as a note.
- Sync runs while the app is open and unlocked (on opening, on focus, when the connection returns). **No background sync:** the service worker never has the data key.
- Logging out, or erasing after a wrong passcode, with unsent edits asks first and says how many will be lost. A copy erased by removal from the family loses its unsent edits.

### The offline viewer

- A separate small page at `/offline` (its own Vite entry, not Inertia), cached with its scripts **only on devices that turned offline on**.
- The service worker serves it when a page cannot be reached and a copy exists; otherwise the existing offline page.
- Browse the tree, open a profile, search by name, read the timeline, make the edits above.
- On iPhone and iPad, offline is offered only in the installed app (Safari may clear a website's storage after 7 days without use).

### Limits to state plainly in the app

- Erasing and the 30-day limit are carried out by the app itself. Someone determined, who keeps a phone offline, keeps what they were already allowed to see.
- A 6-digit passcode stops casual access and makes a copied disk slow to break, not impossible. A longer passcode is stronger.
- A copy shows the tree as it was at the last refresh.

### Decisions for the developer

| #   | Question                                                                      | Recommendation                                                                                         |
| --- | ----------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------ |
| 1   | Require an offline passcode?                                                  | **Yes.** Without it, the design falls back to the plan's device key, which does not stop risks A or B. |
| 2   | Who may keep an offline copy?                                                 | Every role, with the narrowed content above; the Owner can switch it off for the family. Default: on.  |
| 3   | How long may a copy go without refreshing?                                    | 30 days, as planned, set in configuration.                                                             |
| 4   | Offline editing of existing timeline entries?                                 | Not in v1. New entries only. Avoids a new column and a second conflict screen.                         |
| 5   | Should living people's details be in the copy for roles that see them online? | Yes, behind the passcode. If decision 1 is "no", leave them out for every role.                        |

### Tests the implementation must have

- Snapshot: outsiders 404; each role gets exactly what the narrowed viewer allows; restricted, private, notes and documents never appear; a person hidden after a refresh is absent from the next one.
- Devices: revoked by each event in the table above; the status token returns only `ok` or `wipe`.
- Sync: each row of the results table; an edit made as Editor and sent as Viewer is rejected; a hidden field in an item is ignored; a replay does not apply twice.
- Browser: turn offline on, cut the network, unlock, browse, edit, reconnect, see the edit applied; log out and confirm IndexedDB is empty.
