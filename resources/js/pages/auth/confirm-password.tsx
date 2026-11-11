import { Form, Head, Link, usePage } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { store } from '@/routes/password/confirm';
import {
    index as confirmOptions,
    store as confirmStore,
} from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyConfirmationController';
import PasskeyVerify from '@/components/passkey-verify';

type Props = {
    hasPassword: boolean;
};

export default function ConfirmPassword({ hasPassword }: Props) {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Confirm password" />

            <PasskeyVerify
                routes={{
                    options: confirmOptions(),
                    submit: confirmStore(),
                }}
                label="Confirm with passkey"
                loadingLabel="Confirming..."
                separator="Or confirm with password"
            />

            {hasPassword ? (
                <Form {...store.form()} resetOnSuccess={['password']}>
                    {({ processing, errors }) => (
                        <div className="space-y-6">
                            <div className="grid gap-2">
                                <Label htmlFor="password">Password</Label>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    placeholder="Password"
                                    autoComplete="current-password"
                                    autoFocus
                                />

                                <InputError message={errors.password} />
                            </div>

                            <div className="flex items-center">
                                <Button
                                    className="w-full"
                                    disabled={processing}
                                    data-test="confirm-password-button"
                                >
                                    {processing && <Spinner />}
                                    Confirm password
                                </Button>
                            </div>
                        </div>
                    )}
                </Form>
            ) : (
                // Accounts created with Google have no password to confirm yet.
                <div className="space-y-4">
                    <p className="text-sm text-muted-foreground">
                        You signed up with Google, so your account has no
                        password yet. To open this area, set one first: log out,
                        choose "Forgot your password?" on the login page and
                        enter{' '}
                        <span className="font-medium text-foreground">
                            {auth.user.email}
                        </span>
                        . We'll email you a link.
                    </p>

                    <Button variant="outline" className="w-full" asChild>
                        <Link href={logout()} as="button">
                            Log out
                        </Link>
                    </Button>
                </div>
            )}
        </>
    );
}

ConfirmPassword.layout = {
    title: 'Confirm password',
    description:
        'This is a secure area of the application. Please confirm your password before continuing.',
};
