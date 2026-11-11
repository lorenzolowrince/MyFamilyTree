import { Head } from '@inertiajs/react';
import { overview } from '@/routes/admin';

type Stats = {
    users: number;
    usersNewThisWeek: number;
    usersUnverified: number;
    usersSuspended: number;
    usersPendingDeletion: number;
    families: number;
    signInsLastDay: number;
    failedSignInsLastDay: number;
    lockoutsLastDay: number;
};

function StatTile({
    label,
    value,
    hint,
    warn = false,
}: {
    label: string;
    value: number;
    hint?: string;
    warn?: boolean;
}) {
    return (
        <div className="rounded-xl border p-4">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd
                className={`mt-1 text-2xl font-semibold tabular-nums ${
                    warn && value > 0 ? 'text-red-600 dark:text-red-400' : ''
                }`}
            >
                {value.toLocaleString()}
            </dd>
            {hint && (
                <dd className="mt-1 text-xs text-muted-foreground">{hint}</dd>
            )}
        </div>
    );
}

export default function AdminOverview({ stats }: { stats: Stats }) {
    return (
        <>
            <Head title="Administration" />

            <div className="space-y-8">
                <section aria-labelledby="accounts-heading">
                    <h2
                        id="accounts-heading"
                        className="mb-3 text-base font-medium"
                    >
                        Accounts
                    </h2>
                    <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <StatTile
                            label="Users"
                            value={stats.users}
                            hint={`${stats.usersNewThisWeek.toLocaleString()} new in the last 7 days`}
                        />
                        <StatTile
                            label="Email not verified"
                            value={stats.usersUnverified}
                        />
                        <StatTile label="Families" value={stats.families} />
                        <StatTile
                            label="Suspended"
                            value={stats.usersSuspended}
                        />
                        <StatTile
                            label="Waiting to be deleted"
                            value={stats.usersPendingDeletion}
                            hint="In their 14-day grace period"
                        />
                    </dl>
                </section>

                <section aria-labelledby="security-heading">
                    <h2
                        id="security-heading"
                        className="mb-3 text-base font-medium"
                    >
                        Sign-ins, last 24 hours
                    </h2>
                    <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <StatTile
                            label="Successful"
                            value={stats.signInsLastDay}
                        />
                        <StatTile
                            label="Failed (wrong password or code)"
                            value={stats.failedSignInsLastDay}
                        />
                        <StatTile
                            label="Lockouts"
                            value={stats.lockoutsLastDay}
                            hint="Too many attempts in a row"
                            warn
                        />
                    </dl>
                </section>
            </div>
        </>
    );
}

AdminOverview.layout = {
    breadcrumbs: [{ title: 'Administration', href: overview() }],
};
