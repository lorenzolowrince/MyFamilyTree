import { Head, Link, usePage } from '@inertiajs/react';
import { MonitorSmartphone } from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/dates';
import { destroy, destroyOthers, index } from '@/routes/sessions';

type Session = {
    id: string;
    device: string;
    ip_address: string | null;
    last_active_at: string;
    is_current: boolean;
};

type HistoryEntry = {
    id: string;
    event: string;
    label: string;
    device: string;
    ip_address: string | null;
    created_at: string;
};

type Props = {
    sessions: Session[];
    history: HistoryEntry[];
    historyRetentionMonths: number;
};

/** Events worth a second look when reviewing your own history. */
const WARNING_EVENTS = ['failed', 'mfa_failed', 'lockout', 'mfa_disabled'];

export default function Sessions({
    sessions,
    history,
    historyRetentionMonths,
}: Props) {
    const { auth } = usePage().props;
    const timezone = auth.user.timezone;
    const hasOtherSessions = sessions.some((session) => !session.is_current);

    return (
        <>
            <Head title="Devices and sign-in history" />

            <h1 className="sr-only">Devices and sign-in history</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Where you're signed in"
                    description="Sign out any device you don't recognise, then change your password."
                />

                {sessions.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No active devices to show.
                    </p>
                ) : (
                    <ul className="divide-y rounded-lg border">
                        {sessions.map((session) => (
                            <li
                                key={session.id}
                                className="flex items-center gap-3 p-4"
                            >
                                <MonitorSmartphone
                                    className="size-5 shrink-0 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <div className="min-w-0 flex-1">
                                    <p className="flex flex-wrap items-center gap-2 text-sm font-medium">
                                        {session.device}
                                        {session.is_current && (
                                            <Badge variant="secondary">
                                                This device
                                            </Badge>
                                        )}
                                    </p>
                                    <p className="truncate text-xs text-muted-foreground">
                                        {session.ip_address ??
                                            'Unknown address'}
                                        {' · '}
                                        Last active{' '}
                                        {formatDateTime(
                                            session.last_active_at,
                                            timezone,
                                        )}
                                    </p>
                                </div>
                                {!session.is_current && (
                                    <Button variant="outline" size="sm" asChild>
                                        <Link
                                            href={destroy(session.id)}
                                            as="button"
                                            preserveScroll
                                        >
                                            Sign out
                                            <span className="sr-only">
                                                {' '}
                                                {session.device}
                                            </span>
                                        </Link>
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}

                {hasOtherSessions && (
                    <Button variant="destructive" asChild>
                        <Link
                            href={destroyOthers()}
                            as="button"
                            preserveScroll
                            data-test="sign-out-other-devices-button"
                        >
                            Sign out all other devices
                        </Link>
                    </Button>
                )}
            </div>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Sign-in history"
                    description={`Recent activity on your account. Kept for ${historyRetentionMonths} months.`}
                />

                {history.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No activity recorded yet.
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-left text-sm">
                            <thead className="border-b bg-muted/50 text-xs text-muted-foreground">
                                <tr>
                                    <th scope="col" className="px-4 py-2">
                                        Activity
                                    </th>
                                    <th scope="col" className="px-4 py-2">
                                        Device
                                    </th>
                                    <th scope="col" className="px-4 py-2">
                                        When
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {history.map((entry) => (
                                    <tr key={entry.id}>
                                        <td className="px-4 py-2">
                                            <span
                                                className={
                                                    WARNING_EVENTS.includes(
                                                        entry.event,
                                                    )
                                                        ? 'font-medium text-red-600 dark:text-red-400'
                                                        : undefined
                                                }
                                            >
                                                {entry.label}
                                            </span>
                                        </td>
                                        <td className="px-4 py-2 text-muted-foreground">
                                            {entry.device}
                                            {entry.ip_address &&
                                                ` · ${entry.ip_address}`}
                                        </td>
                                        <td className="px-4 py-2 whitespace-nowrap text-muted-foreground">
                                            {formatDateTime(
                                                entry.created_at,
                                                timezone,
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </>
    );
}

Sessions.layout = {
    breadcrumbs: [
        {
            title: 'Devices and sign-in history',
            href: index(),
        },
    ],
};
