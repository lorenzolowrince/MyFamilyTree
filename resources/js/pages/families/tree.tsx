import { Head, Link, router, useForm } from '@inertiajs/react';
import { List, Network, Settings, UserPlus } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { AddRelativeDialog } from '@/components/members/add-relative-dialog';
import { MemberAvatar } from '@/components/members/member-avatar';
import { FamilyTreeCanvas } from '@/components/tree/family-tree-canvas';
import { partnersOf } from '@/components/tree/tree-layout';
import { TreeList } from '@/components/tree/tree-list';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit as editFamily, tree } from '@/routes/families';
import {
    index as membersIndex,
    show as showMember,
    store as storeMember,
} from '@/routes/families/members';
import type {
    FamilyRef,
    GenealogyOptions,
    MemberSummary,
    RelativeKind,
    TreeSlice,
    TreeView,
} from '@/types';

type Props = {
    family: FamilyRef;
    view: TreeView;
    depth: { up: number; down: number; max: number };
    slice: TreeSlice | null;
    people: Record<string, MemberSummary>;
    can: { addPeople: boolean; manageRelationships: boolean };
    options: GenealogyOptions;
};

const selectClass =
    'h-8 rounded-md border border-input bg-transparent px-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

const VIEWS: { value: TreeView; label: string }[] = [
    { value: 'hourglass', label: 'Hourglass' },
    { value: 'descendants', label: 'Descendants' },
    { value: 'ancestors', label: 'Ancestors' },
];

function FirstPerson({ family }: { family: FamilyRef }) {
    const form = useForm({
        full_name: '',
        gender: '',
        living_status: 'living',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, gender: data.gender || null }));
        form.post(storeMember.url(family.slug));
    };

    return (
        <section
            aria-labelledby="first-person-heading"
            className="mx-auto flex w-full max-w-md flex-1 flex-col justify-center gap-4 py-10"
        >
            <div className="flex size-12 items-center justify-center rounded-full bg-primary/10 text-primary">
                <Network className="size-6" aria-hidden="true" />
            </div>
            <div>
                <h2 id="first-person-heading" className="text-lg font-medium">
                    Add the first person
                </h2>
                <p className="text-sm text-muted-foreground">
                    Start with yourself or with the oldest ancestor you know.
                    Parents, partners, children and siblings are added from
                    their card afterwards.
                </p>
            </div>
            <form onSubmit={submit} className="grid gap-4" noValidate>
                <div className="grid gap-2">
                    <Label htmlFor="first-name">Full name</Label>
                    <Input
                        id="first-name"
                        value={form.data.full_name}
                        maxLength={200}
                        autoComplete="off"
                        onChange={(event) =>
                            form.setData('full_name', event.target.value)
                        }
                    />
                    <InputError message={form.errors.full_name} />
                </div>
                <div className="grid grid-cols-2 gap-3">
                    <div className="grid gap-2">
                        <Label htmlFor="first-gender">Gender</Label>
                        <select
                            id="first-gender"
                            className={`${selectClass} h-9`}
                            value={form.data.gender}
                            onChange={(event) =>
                                form.setData('gender', event.target.value)
                            }
                        >
                            <option value="">Not stated</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="first-living">Living?</Label>
                        <select
                            id="first-living"
                            className={`${selectClass} h-9`}
                            value={form.data.living_status}
                            onChange={(event) =>
                                form.setData(
                                    'living_status',
                                    event.target.value,
                                )
                            }
                        >
                            <option value="living">Living</option>
                            <option value="deceased">Deceased</option>
                            <option value="unknown">Not sure</option>
                        </select>
                    </div>
                </div>
                <Button type="submit" disabled={form.processing}>
                    Add to the tree
                </Button>
            </form>
        </section>
    );
}

export default function FamilyTree({
    family,
    view,
    depth,
    slice,
    people,
    can,
    options,
}: Props) {
    const [mode, setMode] = useState<'tree' | 'list'>('tree');
    const [selection, setSelection] = useState<{
        key: string | null;
        personId: string;
    } | null>(null);
    const [adding, setAdding] = useState<RelativeKind | null>(null);

    const selectedId = selection?.personId ?? slice?.focus ?? null;
    const selected = selectedId ? people[selectedId] : undefined;

    const partners = useMemo(
        () =>
            slice && selectedId
                ? partnersOf(slice.descendants, selectedId)
                      .map((id) => people[id])
                      .filter(
                          (person): person is MemberSummary =>
                              person !== undefined && !person.hidden,
                      )
                : [],
        [slice, selectedId, people],
    );

    const visit = (
        query: Partial<{
            focus: string;
            view: TreeView;
            up: number;
            down: number;
        }>,
    ) => {
        setSelection(null);
        router.get(
            tree.url(family.slug, {
                query: {
                    focus: slice?.focus,
                    view,
                    up: depth.up,
                    down: depth.down,
                    ...query,
                },
            }),
            {},
            { preserveScroll: true, preserveState: true },
        );
    };

    return (
        <>
            <Head title={`${family.name} – Family tree`} />

            <div className="flex h-full flex-1 flex-col gap-4 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {family.name}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {slice
                                ? `Centred on ${people[slice.focus]?.name ?? 'a relative'}.`
                                : 'Nobody has been added yet.'}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" size="sm" asChild>
                            <Link href={membersIndex(family.slug)}>
                                <List aria-hidden="true" />
                                All members
                            </Link>
                        </Button>
                        <Button variant="outline" size="sm" asChild>
                            <Link href={editFamily(family.slug)}>
                                <Settings aria-hidden="true" />
                                Family settings
                            </Link>
                        </Button>
                    </div>
                </div>

                {!slice ? (
                    can.addPeople ? (
                        <FirstPerson family={family} />
                    ) : (
                        <p className="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">
                            This tree is empty. Someone with permission to add
                            people needs to start it.
                        </p>
                    )
                ) : (
                    <>
                        <div
                            className="flex flex-wrap items-center gap-x-4 gap-y-2"
                            role="toolbar"
                            aria-label="Tree display options"
                        >
                            <div
                                role="radiogroup"
                                aria-label="View"
                                className="flex rounded-lg bg-muted p-1 text-sm"
                            >
                                {VIEWS.map((option) => (
                                    <button
                                        key={option.value}
                                        type="button"
                                        role="radio"
                                        aria-checked={view === option.value}
                                        onClick={() =>
                                            visit({ view: option.value })
                                        }
                                        className={`rounded-md px-3 py-1 font-medium transition-colors ${
                                            view === option.value
                                                ? 'bg-background shadow-xs'
                                                : 'text-muted-foreground hover:text-foreground'
                                        }`}
                                    >
                                        {option.label}
                                    </button>
                                ))}
                            </div>

                            {view !== 'descendants' && (
                                <label className="flex items-center gap-2 text-sm">
                                    Generations up
                                    <select
                                        className={selectClass}
                                        value={depth.up}
                                        onChange={(event) =>
                                            visit({
                                                up: Number(event.target.value),
                                            })
                                        }
                                    >
                                        {Array.from(
                                            { length: depth.max },
                                            (_, index) => index + 1,
                                        ).map((value) => (
                                            <option key={value} value={value}>
                                                {value}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            )}

                            {view !== 'ancestors' && (
                                <label className="flex items-center gap-2 text-sm">
                                    Generations down
                                    <select
                                        className={selectClass}
                                        value={depth.down}
                                        onChange={(event) =>
                                            visit({
                                                down: Number(
                                                    event.target.value,
                                                ),
                                            })
                                        }
                                    >
                                        {Array.from(
                                            { length: depth.max },
                                            (_, index) => index + 1,
                                        ).map((value) => (
                                            <option key={value} value={value}>
                                                {value}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            )}

                            <div
                                role="radiogroup"
                                aria-label="Display as"
                                className="ml-auto flex rounded-lg bg-muted p-1 text-sm"
                            >
                                {(
                                    [
                                        ['tree', 'Tree'],
                                        ['list', 'List'],
                                    ] as const
                                ).map(([value, label]) => (
                                    <button
                                        key={value}
                                        type="button"
                                        role="radio"
                                        aria-checked={mode === value}
                                        onClick={() => setMode(value)}
                                        className={`rounded-md px-3 py-1 font-medium transition-colors ${
                                            mode === value
                                                ? 'bg-background shadow-xs'
                                                : 'text-muted-foreground hover:text-foreground'
                                        }`}
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>
                        </div>

                        <div className="grid flex-1 gap-4 lg:grid-cols-[minmax(0,1fr)_19rem]">
                            <div className="min-h-[28rem] lg:min-h-0">
                                {mode === 'tree' ? (
                                    <FamilyTreeCanvas
                                        slice={slice}
                                        people={people}
                                        view={view}
                                        selectedKey={selection?.key ?? null}
                                        onSelect={(card) =>
                                            setSelection({
                                                key: card.key,
                                                personId: card.personId,
                                            })
                                        }
                                        onExpand={(personId) =>
                                            visit({ focus: personId })
                                        }
                                    />
                                ) : (
                                    <TreeList
                                        slice={slice}
                                        people={people}
                                        view={view}
                                        selectedId={selectedId}
                                        onPick={(personId) =>
                                            setSelection({
                                                key: null,
                                                personId,
                                            })
                                        }
                                    />
                                )}
                            </div>

                            <aside
                                aria-label="Selected person"
                                aria-live="polite"
                                className="rounded-xl border p-4"
                            >
                                {selected ? (
                                    <div className="grid gap-4">
                                        <div className="flex items-start gap-3">
                                            <MemberAvatar
                                                member={selected}
                                                className="size-12 text-sm"
                                            />
                                            <div className="min-w-0">
                                                <h2 className="text-base leading-tight font-medium">
                                                    {selected.name}
                                                </h2>
                                                <p className="text-sm text-muted-foreground">
                                                    {selected.lifespan ??
                                                        (selected.hidden
                                                            ? 'You do not have access to this record.'
                                                            : selected.living
                                                              ? 'Living'
                                                              : 'Dates not recorded')}
                                                </p>
                                                <div className="mt-1 flex flex-wrap gap-1">
                                                    {selected.is_me && (
                                                        <Badge>You</Badge>
                                                    )}
                                                    {selected.generation !==
                                                        null && (
                                                        <Badge variant="secondary">
                                                            Generation{' '}
                                                            {
                                                                selected.generation
                                                            }
                                                        </Badge>
                                                    )}
                                                    {selected.is_placeholder && (
                                                        <Badge variant="outline">
                                                            Unknown person
                                                        </Badge>
                                                    )}
                                                </div>
                                            </div>
                                        </div>

                                        {!selected.hidden && (
                                            <>
                                                <div className="grid gap-2">
                                                    <Button asChild size="sm">
                                                        <Link
                                                            href={showMember({
                                                                family: family.slug,
                                                                member: selected.id,
                                                            })}
                                                        >
                                                            Open profile
                                                        </Link>
                                                    </Button>
                                                    {selected.id !==
                                                        slice.focus && (
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() =>
                                                                visit({
                                                                    focus: selected.id,
                                                                })
                                                            }
                                                        >
                                                            Centre tree here
                                                        </Button>
                                                    )}
                                                </div>

                                                {can.manageRelationships && (
                                                    <div className="grid gap-2 border-t pt-4">
                                                        <h3 className="flex items-center gap-2 text-sm font-medium">
                                                            <UserPlus
                                                                className="size-4"
                                                                aria-hidden="true"
                                                            />
                                                            Add a relative
                                                        </h3>
                                                        <div className="grid grid-cols-2 gap-2">
                                                            {(
                                                                [
                                                                    [
                                                                        'parent',
                                                                        'Parent',
                                                                    ],
                                                                    [
                                                                        'partner',
                                                                        'Partner',
                                                                    ],
                                                                    [
                                                                        'child',
                                                                        'Child',
                                                                    ],
                                                                    [
                                                                        'sibling',
                                                                        'Sibling',
                                                                    ],
                                                                ] as const
                                                            ).map(
                                                                ([
                                                                    kind,
                                                                    label,
                                                                ]) => (
                                                                    <Button
                                                                        key={
                                                                            kind
                                                                        }
                                                                        type="button"
                                                                        size="sm"
                                                                        variant="outline"
                                                                        onClick={() =>
                                                                            setAdding(
                                                                                kind,
                                                                            )
                                                                        }
                                                                    >
                                                                        {label}
                                                                    </Button>
                                                                ),
                                                            )}
                                                        </div>
                                                    </div>
                                                )}
                                            </>
                                        )}
                                    </div>
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        Select a person to see details.
                                    </p>
                                )}
                            </aside>
                        </div>
                    </>
                )}
            </div>

            {adding && selected && (
                <AddRelativeDialog
                    key={`${selected.id}-${adding}`}
                    family={family}
                    anchor={{ id: selected.id, name: selected.name }}
                    kind={adding}
                    options={options}
                    partners={partners}
                    onClose={() => setAdding(null)}
                />
            )}
        </>
    );
}
