/**
 * Format a server timestamp (ISO 8601, UTC) in the user's own timezone,
 * e.g. "11 Nov 2026, 3:42 pm".
 */
export function formatDateTime(iso: string, timeZone?: string): string {
    return new Intl.DateTimeFormat('en-MY', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone,
    }).format(new Date(iso));
}

/** Date only, e.g. "25 Nov 2026". */
export function formatDate(iso: string, timeZone?: string): string {
    return new Intl.DateTimeFormat('en-MY', {
        dateStyle: 'medium',
        timeZone,
    }).format(new Date(iso));
}
