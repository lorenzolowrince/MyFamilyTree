import { router } from '@inertiajs/react';
import { Loader2, ShieldAlert } from 'lucide-react';
import { useEffect } from 'react';

/**
 * While any file is still being checked, ask the server again every few seconds so it
 * appears by itself when ready.
 */
export function useRefreshWhileChecking(checking: boolean, only: string[]) {
    useEffect(() => {
        if (!checking) {
            return;
        }

        const timer = window.setInterval(
            () => router.reload({ only, preserveUrl: true }),
            4000,
        );

        return () => window.clearInterval(timer);
        // `only` is a constant list at every call site.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [checking]);
}

/** Shown in place of a file that is not (yet) available. Only its uploader ever sees this. */
export function MediaStatusNote({
    status,
    reason,
    name,
}: {
    status: 'quarantined' | 'rejected';
    reason: string | null;
    name: string | null;
}) {
    return status === 'quarantined' ? (
        <div className="flex size-full flex-col items-center justify-center gap-2 bg-muted p-3 text-center text-xs text-muted-foreground">
            <Loader2
                className="size-5 motion-safe:animate-spin"
                aria-hidden="true"
            />
            <span role="status">
                Being checked…
                {name && <span className="block truncate">{name}</span>}
            </span>
        </div>
    ) : (
        <div className="flex size-full flex-col items-center justify-center gap-2 bg-red-50 p-3 text-center text-xs text-red-800 dark:bg-red-950 dark:text-red-200">
            <ShieldAlert className="size-5" aria-hidden="true" />
            <span>
                <span className="font-medium">Not accepted</span>
                {name && <span className="block truncate">{name}</span>}
                {reason && <span className="mt-1 block">{reason}</span>}
            </span>
        </div>
    );
}
