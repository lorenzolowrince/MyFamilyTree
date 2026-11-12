import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate } from '@/lib/dates';
import { show as showMember } from '@/routes/families/members';
import {
    destroy,
    invite,
    role as changeRole,
    transfer,
} from '@/routes/families/people';
import { destroy as revokeInvitation } from '@/routes/families/people/invitations';
import type { FamilyPerson, FamilyRef, InvitationItem, Option } from '@/types';

type Props = {
    family: FamilyRef;
    people: FamilyPerson[];
    invitations: InvitationItem[];
    assignableRoles: Option[];
    myRole: string | null;
    can: { invite: boolean; transfer: boolean; leave: boolean };
};

const selectClass =
    'h-9 rounded-md border border-input bg-transparent px-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

const ROLE_HELP: Record<string, string> = {
    admin: 'Helps run the tree: can invite people and manage roles.',
    editor: 'Keeps records accurate: edits apply immediately.',
    contributor: 'Adds information: changes wait for approval.',
    viewer: 'Can browse the tree.',
};

function InviteForm({ family, roles }: { family: FamilyRef; roles: Option[] }) {
    const form = useForm({
        email: '',
        role: roles.at(-1)?.value ?? 'viewer',
        message: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(invite.url(family.slug), {
            preserveScroll: true,
            onSuccess: () => form.reset('email', 'message'),
        });
    };

    return (
        <form
            onSubmit={submit}
            className="grid gap-4 rounded-xl border p-4"
            noValidate
        >
            <div className="grid gap-4 sm:grid-cols-[1fr_12rem]">
                <div className="grid gap-2">
                    <Label htmlFor="invite-email">Email address</Label>
                    <Input
                        id="invite-email"
                        type="email"
                        value={form.data.email}
                        autoComplete="off"
                        onChange={(event) =>
                            form.setData('email', event.target.value)
                        }
                    />
                    <InputError message={form.errors.email} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="invite-role">Role</Label>
                    <select
                        id="invite-role"
                        className={selectClass}
                        value={form.data.role}
                        aria-describedby="invite-role-help"
                        onChange={(event) =>
                            form.setData('role', event.target.value)
                        }
                    >
                        {roles.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                    <InputError message={form.errors.role} />
                </div>
            </div>
            <p
                id="invite-role-help"
                className="-mt-2 text-xs text-muted-foreground"
            >
                {ROLE_HELP[form.data.role]}
            </p>
            <div className="grid gap-2">
                <Label htmlFor="invite-message">
                    Personal message (optional)
                </Label>
                <textarea
                    id="invite-message"
                    rows={2}
                    maxLength={1000}
                    value={form.data.message}
                    onChange={(event) =>
                        form.setData('message', event.target.value)
                    }
                    className="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                />
            </div>
            <div>
                <Button type="submit" disabled={form.processing}>
                    Send invitation
                </Button>
            </div>
        </form>
    );
}

function TransferDialog({
    family,
    person,
    onClose,
}: {
    family: FamilyRef;
    person: FamilyPerson;
    onClose: () => void;
}) {
    const form = useForm({ password: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(
            transfer.url({ family: family.slug, membership: person.id }),
            { preserveScroll: true, onSuccess: onClose },
        );
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Transfer ownership</DialogTitle>
                    <DialogDescription>
                        {person.name} becomes the owner of {family.name}. You
                        become an administrator and cannot undo this yourself.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-4" noValidate>
                    <div className="grid gap-2">
                        <Label htmlFor="transfer-password">Your password</Label>
                        <Input
                            id="transfer-password"
                            type="password"
                            autoComplete="current-password"
                            value={form.data.password}
                            onChange={(event) =>
                                form.setData('password', event.target.value)
                            }
                        />
                        <InputError message={form.errors.password} />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={form.processing}
                        >
                            Transfer ownership
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function PeopleIndex({
    family,
    people,
    invitations,
    assignableRoles,
    can,
}: Props) {
    const { auth } = usePage().props;
    const [transferring, setTransferring] = useState<FamilyPerson | null>(null);

    return (
        <>
            <Head title={`${family.name} – People and invitations`} />

            <div className="mx-auto grid w-full max-w-4xl gap-10 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        People and invitations
                    </h1>
                    <p className="text-muted-foreground">
                        Who can open {family.name}, and what they can do.
                    </p>
                </div>

                {can.invite && assignableRoles.length > 0 && (
                    <section aria-labelledby="invite-heading">
                        <h2
                            id="invite-heading"
                            className="mb-3 text-base font-medium"
                        >
                            Invite a relative
                        </h2>
                        <InviteForm family={family} roles={assignableRoles} />
                    </section>
                )}

                <section aria-labelledby="people-heading">
                    <h2
                        id="people-heading"
                        className="mb-3 text-base font-medium"
                    >
                        People with access ({people.length})
                    </h2>
                    <ul className="grid gap-2">
                        {people.map((person) => (
                            <li
                                key={person.id}
                                className="flex flex-wrap items-center justify-between gap-3 rounded-xl border p-3"
                            >
                                <div className="min-w-0">
                                    <p className="flex flex-wrap items-center gap-2 text-sm font-medium">
                                        {person.name ?? 'Former member'}
                                        {person.is_me && <Badge>You</Badge>}
                                        {person.status !== 'active' && (
                                            <Badge variant="destructive">
                                                Suspended
                                            </Badge>
                                        )}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {[
                                            person.email,
                                            person.joined_at
                                                ? `joined ${formatDate(person.joined_at, auth.user.timezone)}`
                                                : null,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </p>
                                    {person.linked_member && (
                                        <p className="text-xs text-muted-foreground">
                                            In the tree as{' '}
                                            <Link
                                                href={showMember({
                                                    family: family.slug,
                                                    member: person.linked_member
                                                        .id,
                                                })}
                                                className="underline underline-offset-2"
                                            >
                                                {person.linked_member.name ??
                                                    'a relative'}
                                            </Link>
                                        </p>
                                    )}
                                </div>

                                <div className="flex flex-wrap items-center gap-2">
                                    {person.can.manage ? (
                                        <select
                                            className={selectClass}
                                            aria-label={`Role of ${person.name ?? 'this person'}`}
                                            value={person.role}
                                            onChange={(event) =>
                                                router.patch(
                                                    changeRole.url({
                                                        family: family.slug,
                                                        membership: person.id,
                                                    }),
                                                    {
                                                        role: event.target
                                                            .value,
                                                    },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {assignableRoles.map((option) => (
                                                <option
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </option>
                                            ))}
                                        </select>
                                    ) : (
                                        <Badge variant="secondary">
                                            {person.role_label}
                                        </Badge>
                                    )}

                                    {can.transfer && !person.is_me && (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                setTransferring(person)
                                            }
                                        >
                                            Make owner
                                        </Button>
                                    )}

                                    {(person.can.manage ||
                                        (person.is_me && can.leave)) && (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            className="text-red-600 dark:text-red-400"
                                            onClick={() => {
                                                if (
                                                    window.confirm(
                                                        person.is_me
                                                            ? `Leave ${family.name}? You will need a new invitation to come back.`
                                                            : `Remove ${person.name ?? 'this person'} from ${family.name}? Their account is not deleted.`,
                                                    )
                                                ) {
                                                    router.delete(
                                                        destroy.url({
                                                            family: family.slug,
                                                            membership:
                                                                person.id,
                                                        }),
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    );
                                                }
                                            }}
                                        >
                                            {person.is_me ? 'Leave' : 'Remove'}
                                        </Button>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                </section>

                {can.invite && invitations.length > 0 && (
                    <section aria-labelledby="invitations-heading">
                        <h2
                            id="invitations-heading"
                            className="mb-3 text-base font-medium"
                        >
                            Invitations
                        </h2>
                        <ul className="grid gap-2">
                            {invitations.map((invitation) => (
                                <li
                                    key={invitation.id}
                                    className="flex flex-wrap items-center justify-between gap-3 rounded-xl border p-3"
                                >
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-medium">
                                            {invitation.email}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {invitation.role_label}
                                            {invitation.state === 'pending' &&
                                                ` · expires ${formatDate(invitation.expires_at, auth.user.timezone)}`}
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <Badge
                                            variant={
                                                invitation.state === 'pending'
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                        >
                                            {invitation.state === 'pending'
                                                ? 'Waiting'
                                                : invitation.state ===
                                                    'accepted'
                                                  ? 'Accepted'
                                                  : invitation.state ===
                                                      'revoked'
                                                    ? 'Cancelled'
                                                    : 'Expired'}
                                        </Badge>
                                        {invitation.state === 'pending' && (
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    router.delete(
                                                        revokeInvitation.url({
                                                            family: family.slug,
                                                            invitation:
                                                                invitation.id,
                                                        }),
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Cancel
                                                <span className="sr-only">
                                                    {' '}
                                                    invitation to{' '}
                                                    {invitation.email}
                                                </span>
                                            </Button>
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>

            {transferring && (
                <TransferDialog
                    family={family}
                    person={transferring}
                    onClose={() => setTransferring(null)}
                />
            )}
        </>
    );
}
