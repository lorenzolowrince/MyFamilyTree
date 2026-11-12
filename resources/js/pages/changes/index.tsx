import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { formatDateTime } from '@/lib/dates';
import { approve, reject, withdraw } from '@/routes/families/changes';
import { show as showMember } from '@/routes/families/members';
import type { ChangeItem, FamilyRef } from '@/types';

type Props = {
    family: FamilyRef;
    pending: ChangeItem[];
    decided: ChangeItem[];
    isReviewer: boolean;
    errors?: Partial<Record<string, string>>;
};

const STATUS: Record<ChangeItem['status'], string> = {
    pending: 'Waiting',
    approved: 'Approved',
    rejected: 'Not approved',
    withdrawn: 'Withdrawn',
    superseded: 'Replaced',
};

/** The current and proposed value of each field, side by side. */
function ChangeDiff({ change }: { change: ChangeItem }) {
    if (change.rows.length === 0) {
        return null;
    }

    const isEdit = change.rows.some((row) => row.old !== null);

    return (
        <div className="overflow-x-auto rounded-lg border">
            <table className="w-full text-left text-sm">
                <thead className="border-b bg-muted/50 text-xs text-muted-foreground">
                    <tr>
                        <th scope="col" className="px-3 py-2">
                            Field
                        </th>
                        {isEdit && (
                            <th scope="col" className="px-3 py-2">
                                Now
                            </th>
                        )}
                        <th scope="col" className="px-3 py-2">
                            Proposed
                        </th>
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {change.rows.map((row) => (
                        <tr key={row.label}>
                            <th
                                scope="row"
                                className="px-3 py-2 align-top font-medium whitespace-nowrap"
                            >
                                {row.label}
                            </th>
                            {isEdit && (
                                <td className="px-3 py-2 align-top whitespace-pre-line text-muted-foreground">
                                    {row.old ?? <em>empty</em>}
                                </td>
                            )}
                            <td className="px-3 py-2 align-top whitespace-pre-line">
                                {row.new ?? <em>empty</em>}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function ChangeCard({
    family,
    change,
    error,
}: {
    family: FamilyRef;
    change: ChangeItem;
    error?: string;
}) {
    const { auth } = usePage().props;
    const [note, setNote] = useState('');
    const args = { family: family.slug, change: change.id };
    const options = { preserveScroll: true };

    return (
        <li className="grid gap-3 rounded-xl border p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                    <h3 className="text-base font-medium">{change.summary}</h3>
                    <p className="text-xs text-muted-foreground">
                        {change.is_mine
                            ? 'Proposed by you'
                            : `Proposed by ${change.submitted_by}`}
                        {change.created_at &&
                            ` · ${formatDateTime(change.created_at, auth.user.timezone)}`}
                    </p>
                </div>
                <Badge
                    variant={
                        change.status === 'pending'
                            ? 'default'
                            : change.status === 'approved'
                              ? 'secondary'
                              : 'outline'
                    }
                >
                    {STATUS[change.status]}
                </Badge>
            </div>

            <ChangeDiff change={change} />

            {change.kind === 'member.delete' && change.status === 'pending' && (
                <p className="text-sm">
                    This removes the person and their links to relatives from
                    the tree.
                </p>
            )}

            {change.target_id && !change.missing && (
                <p className="text-sm">
                    <Link
                        href={showMember({
                            family: family.slug,
                            member: change.target_id,
                        })}
                        className="underline underline-offset-4"
                    >
                        Open the record
                    </Link>
                </p>
            )}

            {change.missing && (
                <p
                    role="alert"
                    className="text-sm text-red-600 dark:text-red-400"
                >
                    The record this proposal is about is no longer in the tree,
                    so it cannot be applied.
                </p>
            )}

            {change.conflict && (
                <p
                    role="alert"
                    className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200"
                >
                    This record was changed after the proposal was made. “Now”
                    shows the current values; approving applies the proposed
                    values on top of them.
                </p>
            )}

            {change.review_note && (
                <p className="text-sm text-muted-foreground">
                    Note from {change.reviewer ?? 'the reviewer'}: “
                    {change.review_note}”
                </p>
            )}

            <InputError message={error} />

            {change.can.review && (
                <div className="grid gap-2 border-t pt-3">
                    <Label htmlFor={`note-${change.id}`}>
                        Note to {change.submitted_by} (optional)
                    </Label>
                    <textarea
                        id={`note-${change.id}`}
                        rows={2}
                        maxLength={1000}
                        value={note}
                        onChange={(event) => setNote(event.target.value)}
                        className="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    />
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            disabled={change.missing}
                            onClick={() =>
                                router.post(
                                    approve.url(args),
                                    { note, force: change.conflict },
                                    options,
                                )
                            }
                        >
                            {change.conflict
                                ? 'Approve and apply anyway'
                                : 'Approve'}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() =>
                                router.post(reject.url(args), { note }, options)
                            }
                        >
                            Decline
                        </Button>
                    </div>
                </div>
            )}

            {change.can.withdraw && (
                <div>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() =>
                            router.delete(withdraw.url(args), options)
                        }
                    >
                        Withdraw my proposal
                    </Button>
                </div>
            )}
        </li>
    );
}

export default function ChangesIndex({
    family,
    pending,
    decided,
    isReviewer,
    errors = {},
}: Props) {
    return (
        <>
            <Head title={`${family.name} – Approvals`} />

            <div className="mx-auto grid w-full max-w-4xl gap-8 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Approvals
                    </h1>
                    <p className="text-muted-foreground">
                        {isReviewer
                            ? 'Changes proposed by relatives. Nothing reaches the tree until it is approved.'
                            : 'Changes you proposed, and what was decided.'}
                    </p>
                </div>

                <section aria-labelledby="pending-heading">
                    <h2
                        id="pending-heading"
                        className="mb-3 text-base font-medium"
                    >
                        Waiting ({pending.length})
                    </h2>
                    {pending.length === 0 ? (
                        <p className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                            Nothing is waiting for approval.
                        </p>
                    ) : (
                        <ul className="grid gap-3">
                            {pending.map((change) => (
                                <ChangeCard
                                    key={change.id}
                                    family={family}
                                    change={change}
                                    error={errors[change.id]}
                                />
                            ))}
                        </ul>
                    )}
                </section>

                {decided.length > 0 && (
                    <section aria-labelledby="decided-heading">
                        <h2
                            id="decided-heading"
                            className="mb-3 text-base font-medium"
                        >
                            Recently decided
                        </h2>
                        <ul className="grid gap-3">
                            {decided.map((change) => (
                                <ChangeCard
                                    key={change.id}
                                    family={family}
                                    change={change}
                                />
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}
