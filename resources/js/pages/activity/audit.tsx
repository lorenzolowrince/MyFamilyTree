import { Head, Link, usePage } from '@inertiajs/react';
import { Pager } from '@/components/pager';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/dates';
import { index as activityIndex } from '@/routes/families/activity';
import type { FamilyRef, Paginated } from '@/types';

type Entry = {
    id: number;
    action: string;
    type: string | null;
    old: Record<string, unknown> | null;
    new: Record<string, unknown> | null;
    ip: string | null;
    actor: string | null;
    created_at: string;
};

function show(value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (
        typeof value === 'string' ||
        typeof value === 'number' ||
        typeof value === 'boolean'
    ) {
        return String(value);
    }

    return JSON.stringify(value);
}

function Values({ entry }: { entry: Entry }) {
    const keys = [
        ...new Set([
            ...Object.keys(entry.old ?? {}),
            ...Object.keys(entry.new ?? {}),
        ]),
    ];

    if (keys.length === 0) {
        return null;
    }

    return (
        <details className="mt-1">
            <summary className="cursor-pointer text-xs text-muted-foreground">
                {keys.length} {keys.length === 1 ? 'value' : 'values'}
            </summary>
            <dl className="mt-2 grid gap-1 text-xs">
                {keys.map((key) => (
                    <div
                        key={key}
                        className="grid gap-x-3 sm:grid-cols-[10rem_1fr_1fr]"
                    >
                        <dt className="font-medium break-all">{key}</dt>
                        <dd className="break-all text-muted-foreground">
                            {entry.old ? show(entry.old[key]) : ''}
                        </dd>
                        <dd className="break-all">
                            {entry.new ? show(entry.new[key]) : ''}
                        </dd>
                    </div>
                ))}
            </dl>
        </details>
    );
}

/** The security record: every change with its values before and after. Owner and administrators only. */
export default function ActivityAudit({
    family,
    entries,
}: {
    family: FamilyRef;
    entries: Paginated<Entry>;
}) {
    const { auth } = usePage().props;

    return (
        <>
            <Head title={`${family.name} – Audit log`} />

            <div className="mx-auto grid w-full max-w-5xl gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Audit log
                        </h1>
                        <p className="text-muted-foreground">
                            Every change to {family.name}: who, when, from
                            where, and the values before and after. It cannot be
                            edited or deleted.
                        </p>
                    </div>
                    <Button variant="outline" size="sm" asChild>
                        <Link href={activityIndex(family.slug)}>
                            Activity feed
                        </Link>
                    </Button>
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-left text-sm">
                        <thead className="border-b bg-muted/50 text-xs text-muted-foreground">
                            <tr>
                                <th scope="col" className="px-3 py-2">
                                    When
                                </th>
                                <th scope="col" className="px-3 py-2">
                                    Who
                                </th>
                                <th scope="col" className="px-3 py-2">
                                    What
                                </th>
                                <th scope="col" className="px-3 py-2">
                                    Address
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {entries.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={4}
                                        className="px-3 py-8 text-center text-muted-foreground"
                                    >
                                        Nothing recorded yet.
                                    </td>
                                </tr>
                            )}
                            {entries.data.map((entry) => (
                                <tr key={entry.id}>
                                    <td className="px-3 py-2 align-top whitespace-nowrap text-muted-foreground">
                                        {formatDateTime(
                                            entry.created_at,
                                            auth.user.timezone,
                                        )}
                                    </td>
                                    <td className="px-3 py-2 align-top">
                                        {entry.actor ?? 'System'}
                                    </td>
                                    <td className="px-3 py-2 align-top">
                                        <code className="text-xs">
                                            {entry.action}
                                        </code>
                                        <Values entry={entry} />
                                    </td>
                                    <td className="px-3 py-2 align-top text-xs text-muted-foreground">
                                        {entry.ip ?? ''}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <Pager page={entries} />
            </div>
        </>
    );
}
