/**
 * Push notifications in this browser (Settings → Notifications). The service worker
 * (public/sw.js) shows the messages; the server keeps where to send them.
 */
import { isAppleMobile, isStandalone } from '@/lib/pwa';

export type PushSupport =
    | 'supported'
    /** iPhone/iPad: only an app added to the Home Screen can receive push. */
    | 'install-first'
    | 'unsupported';

export function pushSupport(): PushSupport {
    const capable =
        'serviceWorker' in navigator &&
        'PushManager' in window &&
        'Notification' in window;

    if (isAppleMobile() && !isStandalone()) {
        return 'install-first';
    }

    return capable ? 'supported' : 'unsupported';
}

async function registration(): Promise<ServiceWorkerRegistration | null> {
    if (!('serviceWorker' in navigator)) {
        return null;
    }

    // The worker is registered at load in production builds (lib/pwa.ts).
    const existing = await navigator.serviceWorker.getRegistration('/');

    return existing ?? null;
}

export async function currentSubscription(): Promise<PushSubscription | null> {
    const worker = await registration();

    return worker ? worker.pushManager.getSubscription() : null;
}

/** SHA-256 of the endpoint, to compare with the server's list without sending it. */
export async function endpointHash(endpoint: string): Promise<string> {
    const digest = await crypto.subtle.digest(
        'SHA-256',
        new TextEncoder().encode(endpoint),
    );

    return Array.from(new Uint8Array(digest))
        .map((byte) => byte.toString(16).padStart(2, '0'))
        .join('');
}

function keyBytes(base64Url: string): Uint8Array<ArrayBuffer> {
    const padded = (base64Url + '='.repeat((4 - (base64Url.length % 4)) % 4))
        .replace(/-/g, '+')
        .replace(/_/g, '/');
    const raw = atob(padded);
    const bytes = new Uint8Array(new ArrayBuffer(raw.length));

    for (let i = 0; i < raw.length; i++) {
        bytes[i] = raw.charCodeAt(i);
    }

    return bytes;
}

/** Asks for permission (the browser's own prompt) and subscribes this browser. */
export async function subscribe(
    vapidPublicKey: string,
): Promise<PushSubscriptionJSON | null> {
    const worker = await registration();

    if (!worker) {
        return null;
    }

    const permission = await Notification.requestPermission();

    if (permission !== 'granted') {
        return null;
    }

    const subscription =
        (await worker.pushManager.getSubscription()) ??
        (await worker.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: keyBytes(vapidPublicKey),
        }));

    return subscription.toJSON();
}

/**
 * Stops push in this browser and returns the address it had. The push service then refuses
 * that address, and the server forgets it the next time it tries to send.
 *
 * Used on logout (with a time limit), so the next person signing in on a shared device
 * does not receive the previous person's messages. If the browser is slow, logging out
 * goes ahead and unsubscribing finishes in the background.
 */
export async function unsubscribeThisDevice(
    waitMs?: number,
): Promise<string | null> {
    const work = (async () => {
        try {
            const subscription = await currentSubscription();

            if (!subscription) {
                return null;
            }

            const endpoint = subscription.endpoint;
            await subscription.unsubscribe();

            return endpoint;
        } catch {
            return null;
        }
    })();

    if (waitMs === undefined) {
        return work;
    }

    return Promise.race([
        work,
        new Promise<null>((resolve) => setTimeout(() => resolve(null), waitMs)),
    ]);
}
