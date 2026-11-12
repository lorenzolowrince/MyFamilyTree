import { Head, Link, usePage } from '@inertiajs/react';
import { Pager } from '@/components/pager';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/dates';
import { audit } from '@/routes/families/activity';
import type { FamilyRef, Paginated } from '@/types';

type Activity = {
    id: string;
    verb: string;
    summary: string;
    actor: string | null;
    created_at: string;
};

export default function ActivityIndex({
    family,
    activity,
    canViewAudit,
}: {
    family: FamilyRef;
    activity: Paginated<Activity>;
    canViewAudit: boolean;
}) {
    const { auth } = usePage().props;

    return (
        <>
            <Head title={`${family.name} – Activity`} />

            <div className="mx-auto grid w-full max-w-4xl gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Recent activity
                        </h1>
                        <p className="text-muted-foreground">
                            What has changed in {family.name}.
                        </p>
                    </div>
                    {canViewAudit && (
                        <Button variant="outline" size="sm" asChild>
                            <Link href={audit(family.slug)}>
                                Detailed audit log
                            </Link>
                        </Button>
                    )}
                </div>

                {activity.data.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                        Nothing has happened yet.
                    </p>
                ) : (
                    <ol className="grid gap-2">
                        {activity.data.map((item) => (
                            <li
                                key={item.id}
                                className="flex flex-wrap items-baseline justify-between gap-x-4 rounded-lg border px-3 py-2"
                            >
                                <p className="text-sm">
                                    <span className="font-medium">
                                        {item.actor ?? 'The system'}
                                    </span>{' '}
                                    {item.summary}
                                </p>
                                <time
                                    dateTime={item.created_at}
                                    className="text-xs whitespace-nowrap text-muted-foreground"
                                >
                                    {formatDateTime(
                                        item.created_at,
                                        auth.user.timezone,
                                    )}
                                </time>
                            </li>
                        ))}
                    </ol>
                )}

                <Pager page={activity} />
            </div>
        </>
    );
}
