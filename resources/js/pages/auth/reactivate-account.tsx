import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatDate } from '@/lib/dates';
import { logout } from '@/routes';
import { store } from '@/routes/reactivation';

type Props = {
    /** Set when the user asked for the account to be deleted. */
    deletionScheduledAt: string | null;
};

export default function ReactivateAccount({ deletionScheduledAt }: Props) {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Reactivate your account" />

            <div className="space-y-6">
                {deletionScheduledAt ? (
                    <p
                        className="rounded-lg border border-red-100 bg-red-50 p-4 text-sm text-red-700 dark:border-red-200/10 dark:bg-red-700/10 dark:text-red-100"
                        role="alert"
                    >
                        This account is scheduled to be permanently deleted on{' '}
                        <strong>
                            {formatDate(
                                deletionScheduledAt,
                                auth.user.timezone,
                            )}
                        </strong>
                        . Reactivating it cancels the deletion.
                    </p>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        You deactivated this account. Nothing was deleted:
                        reactivate it to pick up where you left off.
                    </p>
                )}

                <Form {...store.form()}>
                    {({ processing }) => (
                        <Button
                            className="w-full"
                            disabled={processing}
                            data-test="reactivate-account-button"
                        >
                            {processing && <Spinner />}
                            {deletionScheduledAt
                                ? 'Keep my account'
                                : 'Reactivate my account'}
                        </Button>
                    )}
                </Form>

                <Button variant="ghost" className="w-full" asChild>
                    <Link href={logout()} as="button">
                        Log out
                    </Link>
                </Button>
            </div>
        </>
    );
}

ReactivateAccount.layout = {
    title: 'Welcome back',
    description: 'Your account is currently deactivated',
};
