import { Head, Link, usePage } from '@inertiajs/react';
import { Network, Plus } from 'lucide-react';
import { FamilyOverview } from '@/components/dashboard/family-overview';
import { FamilyCard } from '@/components/family-card';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { create as createFamily } from '@/routes/families';
import type { FamilyOverview as Overview, FamilySummary } from '@/types';

export default function Dashboard({
    families,
    overview,
    can,
}: {
    families: FamilySummary[];
    overview: Overview | null;
    can: { createFamily: boolean };
}) {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Welcome, {auth.user?.name}
                        </h1>
                        <p className="text-muted-foreground">
                            Birthdays, anniversaries and events coming up in
                            your family.
                        </p>
                    </div>
                    {can.createFamily && families.length > 0 && (
                        <Button asChild variant="outline">
                            <Link href={createFamily()}>
                                <Plus aria-hidden="true" />
                                New family tree
                            </Link>
                        </Button>
                    )}
                </div>

                {families.length === 0 ? (
                    <section
                        aria-labelledby="no-trees-heading"
                        className="flex flex-1 flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-sidebar-border p-10 text-center"
                    >
                        <div className="flex size-12 items-center justify-center rounded-full bg-primary/10 text-primary">
                            <Network className="size-6" aria-hidden="true" />
                        </div>
                        <h2
                            id="no-trees-heading"
                            className="text-lg font-medium"
                        >
                            No family trees yet
                        </h2>
                        <p className="max-w-md text-sm text-muted-foreground">
                            Create a tree, add yourself, then add parents,
                            partners, children and siblings from each
                            person&apos;s card.
                        </p>
                        {can.createFamily && (
                            <Button asChild>
                                <Link href={createFamily()}>
                                    <Plus aria-hidden="true" />
                                    Create your family tree
                                </Link>
                            </Button>
                        )}
                    </section>
                ) : (
                    <>
                        {overview && <FamilyOverview overview={overview} />}
                        <section aria-labelledby="trees-heading">
                            <h2
                                id="trees-heading"
                                className="mb-3 text-lg font-medium"
                            >
                                Your family trees
                            </h2>
                            <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                                {families.map((family) => (
                                    <li key={family.slug}>
                                        <FamilyCard family={family} />
                                    </li>
                                ))}
                            </ul>
                        </section>
                    </>
                )}
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
