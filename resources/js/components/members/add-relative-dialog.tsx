import { router } from '@inertiajs/react';
import { useEffect, useId, useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { MemberAvatar } from '@/components/members/member-avatar';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
import { getJson } from '@/lib/http';
import { duplicates, lookup } from '@/routes/families/members';
import { store as storeRelative } from '@/routes/families/members/relatives';
import type {
    FamilyRef,
    GenealogyOptions,
    MemberSummary,
    RelativeKind,
} from '@/types';

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

const TITLES: Record<RelativeKind, string> = {
    parent: 'Add a parent',
    child: 'Add a child',
    partner: 'Add a spouse or partner',
    sibling: 'Add a brother or sister',
};

type Errors = Partial<Record<string, string>>;

function PersonRow({ person }: { person: MemberSummary }) {
    return (
        <span className="flex min-w-0 items-center gap-2">
            <MemberAvatar member={person} className="size-8" />
            <span className="min-w-0">
                <span className="block truncate text-sm font-medium">
                    {person.name}
                </span>
                <span className="block truncate text-xs text-muted-foreground">
                    {person.lifespan ?? 'Dates not shown'}
                </span>
            </span>
        </span>
    );
}

/**
 * "Add parent / child / partner / sibling". The relative is either a new person or someone
 * already in the tree. Before a new person is created the server is asked for look-alikes
 * ("Is this one of these people?") so nobody is entered twice.
 *
 * Render it only while open, with a `key` per person and kind, so each opening starts clean.
 */
export function AddRelativeDialog({
    family,
    anchor,
    kind,
    options,
    partners = [],
    parents = [],
    onClose,
}: {
    family: FamilyRef;
    anchor: { id: string; name: string };
    kind: RelativeKind;
    options: GenealogyOptions;
    /** The anchor's partners: offered as the other parent of a new child. */
    partners?: MemberSummary[];
    /** The anchor's parents: a new sibling may share all or only one of them. */
    parents?: MemberSummary[];
    onClose: () => void;
}) {
    const id = useId();
    const [mode, setMode] = useState<'new' | 'existing'>('new');
    const [name, setName] = useState('');
    const [gender, setGender] = useState('');
    const [livingStatus, setLivingStatus] = useState('unknown');
    const [birthYear, setBirthYear] = useState('');
    const [birthAbout, setBirthAbout] = useState(false);
    const [subtype, setSubtype] = useState(
        kind === 'partner' ? 'marriage' : 'biological',
    );
    const [status, setStatus] = useState('current');
    const [startYear, setStartYear] = useState('');
    const [otherParent, setOtherParent] = useState(
        partners.length === 1 ? (partners[0]?.id ?? '') : '',
    );
    const [sharedParent, setSharedParent] = useState('all');
    const [search, setSearch] = useState('');
    const [results, setResults] = useState<MemberSummary[]>([]);
    const [existingId, setExistingId] = useState('');
    const [matches, setMatches] = useState<MemberSummary[] | null>(null);
    const [errors, setErrors] = useState<Errors>({});
    const [processing, setProcessing] = useState(false);

    // Search for an existing person as the name is typed.
    useEffect(() => {
        if (mode !== 'existing' || search.trim().length < 2) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            getJson<{ members: MemberSummary[] }>(
                lookup.url(family.slug, { query: { search } }),
                controller.signal,
            )
                .then((data) =>
                    setResults(
                        data.members.filter(
                            (member) => member.id !== anchor.id,
                        ),
                    ),
                )
                .catch(() => undefined);
        }, 250);

        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [mode, search, family.slug, anchor.id]);

    const shown =
        mode === 'existing' && search.trim().length >= 2 ? results : [];

    const send = (useExisting: string | null) => {
        const year = Number.parseInt(birthYear, 10);
        const marriageYear = Number.parseInt(startYear, 10);

        setProcessing(true);
        setErrors({});

        router.post(
            storeRelative.url({ family: family.slug, member: anchor.id }),
            {
                kind,
                existing_member_id: useExisting,
                member: useExisting
                    ? null
                    : {
                          full_name: name,
                          gender: gender || null,
                          living_status: livingStatus,
                          birth: Number.isNaN(year)
                              ? null
                              : {
                                    year,
                                    qualifier: birthAbout ? 'about' : 'exact',
                                },
                      },
                subtype,
                status: kind === 'partner' ? status : null,
                start:
                    kind === 'partner' && !Number.isNaN(marriageYear)
                        ? { year: marriageYear, qualifier: 'exact' }
                        : null,
                other_parent_id:
                    kind === 'child' && otherParent ? otherParent : null,
                shared_parent_ids:
                    kind === 'sibling' && sharedParent !== 'all'
                        ? [sharedParent]
                        : null,
            },
            {
                preserveScroll: true,
                onSuccess: onClose,
                onError: (formErrors) => {
                    setErrors(formErrors);
                    setMatches(null);
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    const submit = async (event: FormEvent) => {
        event.preventDefault();

        if (mode === 'existing') {
            if (!existingId) {
                setErrors({
                    existing_member_id: 'Choose a person from the list.',
                });

                return;
            }

            send(existingId);

            return;
        }

        if (name.trim() === '') {
            setErrors({ 'member.full_name': 'Enter the name.' });

            return;
        }

        // First ask whether this person is already in the tree.
        if (matches === null) {
            setProcessing(true);

            try {
                const year = Number.parseInt(birthYear, 10);
                const data = await getJson<{ members: MemberSummary[] }>(
                    duplicates.url(family.slug, {
                        query: {
                            name,
                            ...(Number.isNaN(year) ? {} : { birth_year: year }),
                        },
                    }),
                );
                const found = data.members.filter(
                    (member) => member.id !== anchor.id,
                );

                if (found.length > 0) {
                    setMatches(found);
                    setProcessing(false);

                    return;
                }
            } catch {
                // The check is a convenience; the server still validates everything.
            }
        }

        send(null);
    };

    const subtypes =
        kind === 'partner'
            ? options.partnershipSubtypes
            : options.parentSubtypes;

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{TITLES[kind]}</DialogTitle>
                    <DialogDescription>
                        For {anchor.name}. Add a new person, or link someone who
                        is already in the tree.
                    </DialogDescription>
                </DialogHeader>

                {matches !== null ? (
                    <div className="grid gap-4">
                        <Alert>
                            <AlertTitle>
                                Is this one of these people?
                            </AlertTitle>
                            <AlertDescription>
                                Someone similar to “{name}” is already in the
                                tree. Linking the existing person avoids a
                                duplicate.
                            </AlertDescription>
                        </Alert>
                        <ul className="grid gap-2">
                            {matches.map((match) => (
                                <li
                                    key={match.id}
                                    className="flex items-center justify-between gap-3 rounded-lg border p-2"
                                >
                                    <PersonRow person={match} />
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        disabled={processing}
                                        onClick={() => send(match.id)}
                                    >
                                        Use this person
                                        <span className="sr-only">
                                            : {match.name}
                                        </span>
                                    </Button>
                                </li>
                            ))}
                        </ul>
                        <InputError message={errors.relationship} />
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => setMatches(null)}
                            >
                                Back
                            </Button>
                            <Button
                                type="button"
                                disabled={processing}
                                onClick={() => send(null)}
                            >
                                No, add “{name}” as a new person
                            </Button>
                        </DialogFooter>
                    </div>
                ) : (
                    <form
                        onSubmit={(event) => void submit(event)}
                        className="grid gap-4"
                        noValidate
                    >
                        <div
                            role="radiogroup"
                            aria-label="Who is the relative?"
                            className="grid grid-cols-2 gap-1 rounded-lg bg-muted p-1 text-sm"
                        >
                            {(
                                [
                                    ['new', 'A new person'],
                                    ['existing', 'Someone in the tree'],
                                ] as const
                            ).map(([value, label]) => (
                                <button
                                    key={value}
                                    type="button"
                                    role="radio"
                                    aria-checked={mode === value}
                                    onClick={() => setMode(value)}
                                    className={`rounded-md px-3 py-1.5 font-medium transition-colors ${
                                        mode === value
                                            ? 'bg-background shadow-xs'
                                            : 'text-muted-foreground hover:text-foreground'
                                    }`}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>

                        {mode === 'new' ? (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor={`${id}-name`}>
                                        Full name
                                    </Label>
                                    <Input
                                        id={`${id}-name`}
                                        value={name}
                                        maxLength={200}
                                        autoFocus
                                        autoComplete="off"
                                        placeholder="As the family writes it"
                                        onChange={(event) =>
                                            setName(event.target.value)
                                        }
                                    />
                                    <InputError
                                        message={errors['member.full_name']}
                                    />
                                </div>
                                <div className="grid grid-cols-2 gap-3">
                                    <div className="grid gap-2">
                                        <Label htmlFor={`${id}-gender`}>
                                            Gender
                                        </Label>
                                        <select
                                            id={`${id}-gender`}
                                            className={selectClass}
                                            value={gender}
                                            onChange={(event) =>
                                                setGender(event.target.value)
                                            }
                                        >
                                            <option value="">Not stated</option>
                                            {options.genders.map((option) => (
                                                <option
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor={`${id}-living`}>
                                            Living?
                                        </Label>
                                        <select
                                            id={`${id}-living`}
                                            className={selectClass}
                                            value={livingStatus}
                                            onChange={(event) =>
                                                setLivingStatus(
                                                    event.target.value,
                                                )
                                            }
                                        >
                                            <option value="unknown">
                                                Not sure
                                            </option>
                                            <option value="living">
                                                Living
                                            </option>
                                            <option value="deceased">
                                                Deceased
                                            </option>
                                        </select>
                                    </div>
                                </div>
                                <div className="grid grid-cols-2 items-end gap-3">
                                    <div className="grid gap-2">
                                        <Label htmlFor={`${id}-birth`}>
                                            Year of birth (optional)
                                        </Label>
                                        <Input
                                            id={`${id}-birth`}
                                            inputMode="numeric"
                                            maxLength={4}
                                            value={birthYear}
                                            onChange={(event) =>
                                                setBirthYear(event.target.value)
                                            }
                                        />
                                    </div>
                                    <label className="flex h-9 items-center gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            className="size-4 rounded border-input"
                                            checked={birthAbout}
                                            onChange={(event) =>
                                                setBirthAbout(
                                                    event.target.checked,
                                                )
                                            }
                                        />
                                        Approximate
                                    </label>
                                </div>
                                <InputError
                                    message={errors['member.birth.year']}
                                />
                            </>
                        ) : (
                            <div className="grid gap-2">
                                <Label htmlFor={`${id}-search`}>
                                    Search by name
                                </Label>
                                <Input
                                    id={`${id}-search`}
                                    type="search"
                                    value={search}
                                    autoFocus
                                    autoComplete="off"
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                />
                                <div
                                    role="radiogroup"
                                    aria-label="Matching people"
                                    className="grid max-h-56 gap-1 overflow-y-auto"
                                >
                                    {shown.map((person) => (
                                        <button
                                            key={person.id}
                                            type="button"
                                            role="radio"
                                            aria-checked={
                                                existingId === person.id
                                            }
                                            onClick={() =>
                                                setExistingId(person.id)
                                            }
                                            className={`flex items-center rounded-lg border p-2 text-left ${
                                                existingId === person.id
                                                    ? 'border-primary bg-primary/5'
                                                    : 'hover:bg-muted'
                                            }`}
                                        >
                                            <PersonRow person={person} />
                                        </button>
                                    ))}
                                </div>
                                <p
                                    className="text-xs text-muted-foreground"
                                    role="status"
                                >
                                    {search.trim().length < 2
                                        ? 'Type at least two letters.'
                                        : shown.length === 0
                                          ? 'Nobody found with that name.'
                                          : `${shown.length} found.`}
                                </p>
                                <InputError
                                    message={errors.existing_member_id}
                                />
                            </div>
                        )}

                        <div className="grid grid-cols-2 gap-3">
                            <div className="grid gap-2">
                                <Label htmlFor={`${id}-subtype`}>
                                    {kind === 'partner'
                                        ? 'Kind of partnership'
                                        : kind === 'sibling'
                                          ? 'Link to the shared parents'
                                          : 'Kind of parent link'}
                                </Label>
                                <select
                                    id={`${id}-subtype`}
                                    className={selectClass}
                                    value={subtype}
                                    disabled={kind === 'sibling'}
                                    onChange={(event) =>
                                        setSubtype(event.target.value)
                                    }
                                >
                                    {kind === 'sibling' ? (
                                        <option value={subtype}>
                                            Same as {anchor.name}
                                        </option>
                                    ) : (
                                        subtypes.map((option) => (
                                            <option
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </option>
                                        ))
                                    )}
                                </select>
                                <InputError message={errors.subtype} />
                            </div>

                            {kind === 'partner' && (
                                <div className="grid gap-2">
                                    <Label htmlFor={`${id}-status`}>
                                        Status
                                    </Label>
                                    <select
                                        id={`${id}-status`}
                                        className={selectClass}
                                        value={status}
                                        onChange={(event) =>
                                            setStatus(event.target.value)
                                        }
                                    >
                                        {options.partnershipStatuses.map(
                                            (option) => (
                                                <option
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                </div>
                            )}

                            {kind === 'child' && partners.length > 0 && (
                                <div className="grid gap-2">
                                    <Label htmlFor={`${id}-other-parent`}>
                                        Other parent
                                    </Label>
                                    <select
                                        id={`${id}-other-parent`}
                                        className={selectClass}
                                        value={otherParent}
                                        onChange={(event) =>
                                            setOtherParent(event.target.value)
                                        }
                                    >
                                        <option value="">
                                            Not known / add later
                                        </option>
                                        {partners.map((partner) => (
                                            <option
                                                key={partner.id}
                                                value={partner.id}
                                            >
                                                {partner.name}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            )}

                            {kind === 'sibling' && parents.length > 1 && (
                                <div className="grid gap-2">
                                    <Label htmlFor={`${id}-shared`}>
                                        Shares
                                    </Label>
                                    <select
                                        id={`${id}-shared`}
                                        className={selectClass}
                                        value={sharedParent}
                                        onChange={(event) =>
                                            setSharedParent(event.target.value)
                                        }
                                    >
                                        <option value="all">
                                            All parents (full sibling)
                                        </option>
                                        {parents.map((parent) => (
                                            <option
                                                key={parent.id}
                                                value={parent.id}
                                            >
                                                Only {parent.name} (half)
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            )}
                        </div>

                        {kind === 'partner' && (
                            <div className="grid max-w-48 gap-2">
                                <Label htmlFor={`${id}-start`}>
                                    Year married (optional)
                                </Label>
                                <Input
                                    id={`${id}-start`}
                                    inputMode="numeric"
                                    maxLength={4}
                                    value={startYear}
                                    onChange={(event) =>
                                        setStartYear(event.target.value)
                                    }
                                />
                                <InputError message={errors['start.year']} />
                            </div>
                        )}

                        {kind === 'sibling' && parents.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                {anchor.name} has no parents in the tree yet, so
                                an “Unknown parent” will hold the two together
                                until the real parent is added.
                            </p>
                        )}

                        <InputError message={errors.relationship} />

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={onClose}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={processing}>
                                {processing ? 'Saving…' : 'Add'}
                            </Button>
                        </DialogFooter>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}
