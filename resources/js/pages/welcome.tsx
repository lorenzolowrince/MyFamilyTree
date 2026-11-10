import { Head, Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { dashboard, login, register } from '@/routes';

export default function Welcome() {
    const { auth, name } = usePage().props;

    return (
        <>
            <Head title="Welcome" />
            <div className="flex min-h-screen flex-col bg-background text-foreground">
                <header className="mx-auto flex w-full max-w-5xl items-center justify-between px-4 py-5">
                    <div className="flex items-center gap-2 font-semibold">
                        <span className="flex size-8 items-center justify-center rounded-md bg-primary text-primary-foreground">
                            <AppLogoIcon className="size-5 fill-current" />
                        </span>
                        {name}
                    </div>
                    <nav className="flex items-center gap-2">
                        {auth.user ? (
                            <Button asChild>
                                <Link href={dashboard()}>Dashboard</Link>
                            </Button>
                        ) : (
                            <>
                                <Button variant="ghost" asChild>
                                    <Link href={login()}>Log in</Link>
                                </Button>
                                <Button asChild>
                                    <Link href={register()}>
                                        Create account
                                    </Link>
                                </Button>
                            </>
                        )}
                    </nav>
                </header>

                <main className="mx-auto flex w-full max-w-5xl flex-1 flex-col items-start justify-center gap-6 px-4 pb-20">
                    <h1 className="max-w-2xl text-4xl font-semibold tracking-tight text-balance sm:text-5xl">
                        Preserve your family&rsquo;s story for the generations
                        to come.
                    </h1>
                    <p className="max-w-xl text-lg text-muted-foreground">
                        Record your ancestors and relatives, connect the
                        branches of your family, and keep photographs and
                        documents safe in one private place your family shares.
                    </p>
                    {!auth.user && (
                        <Button size="lg" asChild>
                            <Link href={register()}>
                                Start your family tree
                            </Link>
                        </Button>
                    )}
                </main>
            </div>
        </>
    );
}
