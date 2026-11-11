import { Head, Link } from '@inertiajs/react';
import { Network, Plus } from 'lucide-react';
import { FamilyCard } from '@/components/family-card';
import { Button } from '@/components/ui/button';
import { create, index } from '@/routes/families';
import type { FamilySummary } from '@/types';

export default function FamiliesIndex({
    families,
    can,
}: {
    families: FamilySummary[];
    can: { create: boolean };
}) {
    return (
        <>
            <Head title="Family trees" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Family trees
                        </h1>
                        <p className="text-muted-foreground">
                            The trees you own or have been invited to.
                        </p>
                    </div>
                    {can.create && (
                        <Button asChild>
                            <Link href={create()}>
                                <Plus aria-hidden="true" />
                                New family tree
                            </Link>
                        </Button>
                    )}
                </div>

                {families.length === 0 ? (
                    <section
                        aria-labelledby="no-trees-heading"
                        className="flex flex-1 flex-col items-center justify-center gap-3 rounded-xl border border-dashed p-10 text-center"
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
                            {can.create
                                ? 'Create your first tree and start adding the people you know.'
                                : 'Verify your email address to create a family tree.'}
                        </p>
                    </section>
                ) : (
                    <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        {families.map((family) => (
                            <li key={family.slug}>
                                <FamilyCard family={family} />
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

FamiliesIndex.layout = {
    breadcrumbs: [{ title: 'Family trees', href: index() }],
};
