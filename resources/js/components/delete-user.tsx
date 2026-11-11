import { Form, usePage } from '@inertiajs/react';
import { useRef } from 'react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';

type Action = 'deactivate' | 'delete';

/** Password-confirmed dialog shared by "Deactivate" and "Delete". */
function ConfirmWithPassword({
    action,
    trigger,
    title,
    description,
    confirmLabel,
}: {
    action: Action;
    trigger: React.ReactNode;
    title: string;
    description: string;
    confirmLabel: string;
}) {
    const passwordInput = useRef<HTMLInputElement>(null);
    const form =
        action === 'delete'
            ? ProfileController.destroy.form()
            : ProfileController.deactivate.form();
    const inputId = `${action}-password`;

    return (
        <Dialog>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <DialogTitle>{title}</DialogTitle>
                <DialogDescription>{description}</DialogDescription>

                <Form
                    {...form}
                    options={{
                        preserveScroll: true,
                    }}
                    onError={() => passwordInput.current?.focus()}
                    resetOnSuccess
                    className="space-y-6"
                >
                    {({ resetAndClearErrors, processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor={inputId} className="sr-only">
                                    Password
                                </Label>

                                <PasswordInput
                                    id={inputId}
                                    name="password"
                                    ref={passwordInput}
                                    placeholder="Password"
                                    autoComplete="current-password"
                                />

                                <InputError message={errors.password} />
                            </div>

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button
                                        variant="secondary"
                                        onClick={() => resetAndClearErrors()}
                                    >
                                        Cancel
                                    </Button>
                                </DialogClose>

                                <Button
                                    variant="destructive"
                                    disabled={processing}
                                    asChild
                                >
                                    <button
                                        type="submit"
                                        data-test={`confirm-${action}-user-button`}
                                    >
                                        {confirmLabel}
                                    </button>
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

export default function DeleteUser({ graceDays }: { graceDays: number }) {
    const { auth } = usePage().props;

    return (
        <div className="space-y-6">
            <Heading
                variant="small"
                title="Deactivate or delete account"
                description="Take a break, or leave for good"
            />

            {!auth.user.has_password && (
                <p className="text-sm text-muted-foreground">
                    Both actions ask for your password. You signed up with
                    Google, so set a password first using "Forgot your
                    password?" on the login page.
                </p>
            )}

            <div className="space-y-4 rounded-lg border p-4">
                <div className="space-y-0.5">
                    <p className="font-medium">Deactivate account</p>
                    <p className="text-sm text-muted-foreground">
                        Pauses your account and signs you out everywhere.
                        Nothing is deleted. Log in again whenever you like to
                        reactivate it.
                    </p>
                </div>

                <ConfirmWithPassword
                    action="deactivate"
                    trigger={
                        <Button
                            variant="outline"
                            data-test="deactivate-user-button"
                        >
                            Deactivate account
                        </Button>
                    }
                    title="Deactivate your account?"
                    description="You will be signed out on every device. Your account and everything you added stay as they are, and you can reactivate by logging in again. Enter your password to confirm."
                    confirmLabel="Deactivate account"
                />
            </div>

            <div className="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
                <div className="relative space-y-0.5 text-red-600 dark:text-red-100">
                    <p className="font-medium">Delete account</p>
                    <p className="text-sm">
                        Your account is deactivated straight away and
                        permanently erased after {graceDays} days. Log in before
                        then to cancel. Information you added to a family tree
                        stays with that family, shown as added by "Former
                        member".
                    </p>
                </div>

                <ConfirmWithPassword
                    action="delete"
                    trigger={
                        <Button
                            variant="destructive"
                            data-test="delete-user-button"
                        >
                            Delete account
                        </Button>
                    }
                    title="Are you sure you want to delete your account?"
                    description={`Your account will be erased in ${graceDays} days unless you log in again before then. If you own a family tree, transfer or delete it first. Enter your password to confirm.`}
                    confirmLabel="Delete account"
                />
            </div>
        </div>
    );
}
