/*
 * Service worker: makes the app installable and keeps the app's own code on the device
 * (docs/architecture/07-pwa.md, "Caching rules").
 *
 * What it caches:
 *   - the offline page, icons and manifest (installed with the worker);
 *   - compiled JS/CSS from /build/assets (their names change with every build).
 * What it never caches: pages, Inertia responses, API answers, photos, documents or
 * downloads. Those hold personal data, so they always come from the network, and a page
 * that cannot be reached shows the offline page instead.
 *
 * Change VERSION when the list of static files changes.
 */
const VERSION = 'v1';
const STATIC_CACHE = `mft-static-${VERSION}`;
const ASSET_CACHE = 'mft-assets';
const OFFLINE_URL = '/offline.html';
const STATIC_FILES = [
    OFFLINE_URL,
    '/manifest.webmanifest',
    '/favicon.svg',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
];
const PRUNE_EVERY_MS = 60 * 60 * 1000;

let lastPrune = 0;

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(STATIC_CACHE)
            .then((cache) => cache.addAll(STATIC_FILES))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            const keep = [STATIC_CACHE, ASSET_CACHE];
            const names = await caches.keys();
            await Promise.all(
                names
                    .filter((name) => !keep.includes(name))
                    .map((name) => caches.delete(name)),
            );

            // Lets the page request start while the worker is still waking up.
            if (self.registration.navigationPreload) {
                await self.registration.navigationPreload.enable();
            }

            await pruneAssets();
            await self.clients.claim();
        })(),
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(navigate(event));

        return;
    }

    if (url.pathname.startsWith('/build/assets/')) {
        event.respondWith(cacheFirst(request, ASSET_CACHE));

        return;
    }

    if (STATIC_FILES.includes(url.pathname)) {
        event.respondWith(cacheFirst(request, STATIC_CACHE));
    }

    // Everything else (Inertia visits, JSON, media, downloads) goes to the network untouched.
});

/** Pages always come from the network; only when it cannot be reached is the offline page shown. */
async function navigate(event) {
    try {
        const response =
            (await event.preloadResponse) || (await fetch(event.request));

        if (Date.now() - lastPrune > PRUNE_EVERY_MS) {
            event.waitUntil(pruneAssets());
        }

        return response;
    } catch {
        const offline = await caches.match(OFFLINE_URL);

        return offline || Response.error();
    }
}

async function cacheFirst(request, cacheName) {
    const cache = await caches.open(cacheName);
    const cached = await cache.match(request);

    if (cached) {
        return cached;
    }

    const response = await fetch(request);

    if (response.ok && response.type === 'basic') {
        await cache.put(request, response.clone());
    }

    return response;
}

/** Removes compiled files that the current build no longer uses. */
async function pruneAssets() {
    lastPrune = Date.now();

    try {
        const response = await fetch('/build/manifest.json', {
            cache: 'no-store',
        });

        if (!response.ok) {
            return;
        }

        const manifest = await response.json();
        const current = new Set();

        for (const chunk of Object.values(manifest)) {
            for (const file of [
                chunk.file,
                ...(chunk.css || []),
                ...(chunk.assets || []),
            ]) {
                if (file) {
                    current.add(`/build/${file}`);
                }
            }
        }

        const cache = await caches.open(ASSET_CACHE);

        for (const request of await cache.keys()) {
            if (!current.has(new URL(request.url).pathname)) {
                await cache.delete(request);
            }
        }
    } catch {
        // Offline or mid-deploy: try again later.
    }
}
