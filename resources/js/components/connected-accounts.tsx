import { Link, usePage } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { destroy, redirect } from '@/routes/social';

export type Props = {
    google?: {
        /** False when Google sign-in is not configured on this server. */
        available: boolean;
        connected: boolean;
    };
};

export default function ConnectedAccounts({ google }: Props) {
    const { auth } = usePage().props;

    if (!google || (!google.available && !google.connected)) {
        return null;
    }

    return (
        <div className="space-y-6">
            <Heading
                variant="small"
                title="Connected accounts"
                description="Sign in faster with an account you already have"
            />

            <div className="flex items-center justify-between gap-4 rounded-lg border p-4">
                <div className="space-y-1">
                    <p className="flex items-center gap-2 text-sm font-medium">
                        Google
                        {google.connected && (
                            <Badge variant="secondary">Connected</Badge>
                        )}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {google.connected
                            ? 'You can sign in with your Google account.'
                            : 'Connect Google to sign in without typing your password.'}
                    </p>
                    {google.connected && !auth.user.has_password && (
                        <p className="text-sm text-muted-foreground">
                            To disconnect Google, first set a password using
                            "Forgot your password?" on the login page.
                        </p>
                    )}
                </div>

                {google.connected ? (
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={!auth.user.has_password}
                        asChild={auth.user.has_password}
                    >
                        {auth.user.has_password ? (
                            <Link
                                href={destroy('google')}
                                as="button"
                                preserveScroll
                                data-test="disconnect-google-button"
                            >
                                Disconnect
                            </Link>
                        ) : (
                            'Disconnect'
                        )}
                    </Button>
                ) : (
                    <Button variant="outline" size="sm" asChild>
                        <a
                            href={redirect.url('google')}
                            data-test="connect-google-button"
                        >
                            Connect
                        </a>
                    </Button>
                )}
            </div>
        </div>
    );
}
