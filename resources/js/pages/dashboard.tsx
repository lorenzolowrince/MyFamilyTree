import { Head, usePage } from '@inertiajs/react';
import { Network } from 'lucide-react';
import { dashboard } from '@/routes';

export default function Dashboard() {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Welcome, {auth.user?.name}
                    </h1>
                    <p className="text-muted-foreground">
                        This is where your family trees, upcoming birthdays and
                        recent activity will appear.
                    </p>
                </div>

                <section
                    aria-labelledby="no-trees-heading"
                    className="flex flex-1 flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-sidebar-border p-10 text-center"
                >
                    <div className="flex size-12 items-center justify-center rounded-full bg-primary/10 text-primary">
                        <Network className="size-6" aria-hidden="true" />
                    </div>
                    <h2 id="no-trees-heading" className="text-lg font-medium">
                        No family trees yet
                    </h2>
                    <p className="max-w-md text-sm text-muted-foreground">
                        Creating a family tree and adding relatives arrives in
                        the next development phase. Your account, security
                        settings and two-factor authentication are ready to set
                        up now under Settings.
                    </p>
                </section>
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
