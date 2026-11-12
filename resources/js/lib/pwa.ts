/**
 * Installable app: registers the service worker (public/sw.js) and keeps the browser's
 * install offer so the "Install the app" item can use it later.
 * See docs/architecture/07-pwa.md.
 */

/** Chrome, Edge and Samsung Internet; not in the DOM types yet. */
export type BeforeInstallPromptEvent = Event & {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
};

let deferredPrompt: BeforeInstallPromptEvent | null = null;
const listeners = new Set<() => void>();

const notify = () => listeners.forEach((listener) => listener());

export function subscribeToInstall(listener: () => void): () => void {
    listeners.add(listener);

    return () => listeners.delete(listener);
}

export function installPrompt(): BeforeInstallPromptEvent | null {
    return deferredPrompt;
}

/** Shows the browser's own install dialog; the offer can be used only once. */
export async function promptInstall(): Promise<boolean> {
    const event = deferredPrompt;

    if (!event) {
        return false;
    }

    deferredPrompt = null;
    notify();
    await event.prompt();

    return (await event.userChoice).outcome === 'accepted';
}

/** Already opened from the home screen or as an installed app. */
export function isStandalone(): boolean {
    return (
        window.matchMedia('(display-mode: standalone)').matches ||
        (navigator as Navigator & { standalone?: boolean }).standalone === true
    );
}

/** iPhone and iPad have no install offer: people use Share, then Add to Home Screen. */
export function isAppleMobile(): boolean {
    const { userAgent, maxTouchPoints } = navigator;

    // iPadOS reports itself as a Mac; a touch screen gives it away.
    return (
        /iPhone|iPad|iPod/.test(userAgent) ||
        (/Macintosh/.test(userAgent) && maxTouchPoints > 1)
    );
}

export function initializePwa(): void {
    if (typeof window === 'undefined') {
        return;
    }

    window.addEventListener('beforeinstallprompt', (event) => {
        // Keep the offer for our own button instead of the browser's mini bar.
        event.preventDefault();
        deferredPrompt = event as BeforeInstallPromptEvent;
        notify();
    });

    window.addEventListener('appinstalled', () => {
        deferredPrompt = null;
        notify();
    });

    // The Vite dev server serves code that changes on every save: no worker there.
    if (!import.meta.env.PROD || !('serviceWorker' in navigator)) {
        return;
    }

    window.addEventListener('load', () => {
        navigator.serviceWorker
            .register('/sw.js', { scope: '/', updateViaCache: 'none' })
            .catch(() => {
                // The app works without it; it only loses the offline page.
            });
    });
}
