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
