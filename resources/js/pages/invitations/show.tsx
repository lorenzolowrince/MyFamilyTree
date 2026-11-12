import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { home, login, logout, register } from '@/routes';
import { accept } from '@/routes/invitations';

type Props = {
    token: string;
    invitation: {
        family: string | null;
        inviter: string | null;
        role: string;
        message: string | null;
        state: 'pending' | 'accepted' | 'revoked' | 'expired';
        email_hint: string;
    } | null;
    viewer: { email: string; verified: boolean; matches: boolean } | null;
    errors?: Partial<Record<string, string>>;
};

const ENDED: Record<string, string> = {
    accepted: 'This invitation has already been used.',
    revoked: 'This invitation was cancelled. Ask for a new one.',
    expired: 'This invitation has expired. Ask for a new one.',
};

/** The page behind an invitation link. Works signed in or not; has no app chrome. */
export default function InvitationShow({
    token,
    invitation,
    viewer,
    errors = {},
}: Props) {
    const { name } = usePage().props;
    const [processing, setProcessing] = useState(false);

    return (
        <div className="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6">
            <Head title="Invitation" />

            <Link
                href={home()}
                className="flex flex-col items-center gap-2 font-medium"
            >
                <span className="flex size-9 items-center justify-center rounded-md">
                    <AppLogoIcon className="size-9 fill-current text-foreground" />
                </span>
                <span className="sr-only">{name}</span>
            </Link>

            <main className="w-full max-w-md rounded-xl border p-6 text-center">
                {!invitation ? (
                    <>
                        <h1 className="text-xl font-semibold">
                            Invitation not found
                        </h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            This link is not valid. Check that you copied the
                            whole link from the email.
                        </p>
                    </>
                ) : invitation.state !== 'pending' ? (
                    <>
                        <h1 className="text-xl font-semibold">
                            Invitation to {invitation.family}
                        </h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            {ENDED[invitation.state]}
                        </p>
                    </>
                ) : (
                    <>
                        <h1 className="text-xl font-semibold">
                            Join {invitation.family}
                        </h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            {invitation.inviter ?? 'A relative'} invited you to
                            this family tree as{' '}
                            <strong className="text-foreground">
                                {invitation.role}
                            </strong>
                            .
                        </p>
                        {invitation.message && (
                            <blockquote className="mt-4 rounded-lg bg-muted p-3 text-left text-sm whitespace-pre-line">
                                {invitation.message}
                            </blockquote>
                        )}

                        <div className="mt-6 grid gap-3">
                            {!viewer ? (
                                <>
                                    <p className="text-sm text-muted-foreground">
                                        Sign in or create an account with{' '}
                                        {invitation.email_hint} to accept.
                                    </p>
                                    <Button asChild>
                                        <Link href={login()}>Sign in</Link>
                                    </Button>
                                    <Button variant="outline" asChild>
                                        <Link href={register()}>
                                            Create an account
                                        </Link>
                                    </Button>
                                </>
                            ) : !viewer.matches ? (
                                <>
                                    <p className="text-sm text-muted-foreground">
                                        You are signed in as {viewer.email}, but
                                        this invitation was sent to{' '}
                                        {invitation.email_hint}. Sign out and
                                        sign in with that address.
                                    </p>
                                    <Button variant="outline" asChild>
                                        <Link href={logout()} as="button">
                                            Sign out
                                        </Link>
                                    </Button>
                                </>
                            ) : !viewer.verified ? (
                                <p className="text-sm text-muted-foreground">
                                    Verify your email address first (check your
                                    inbox), then open this link again.
                                </p>
                            ) : (
                                <Button
                                    type="button"
                                    disabled={processing}
                                    onClick={() => {
                                        setProcessing(true);
                                        router.post(
                                            accept.url(token),
                                            {},
                                            {
                                                onFinish: () =>
                                                    setProcessing(false),
                                            },
                                        );
                                    }}
                                >
                                    Accept and open the family tree
                                </Button>
                            )}
                            <InputError message={errors.invitation} />
                        </div>
                    </>
                )}
            </main>
        </div>
    );
}
