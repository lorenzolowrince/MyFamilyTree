import { Head, Link, router } from '@inertiajs/react';
import {
    Network,
    Pencil,
    Trash2,
    UserPlus,
    Users,
    Waypoints,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { AddRelativeDialog } from '@/components/members/add-relative-dialog';
import { MemberAvatar } from '@/components/members/member-avatar';
import { MemberEditDialog } from '@/components/members/member-edit-dialog';
import { MemberNames } from '@/components/members/member-names';
import { TimelineList } from '@/components/timeline/timeline-list';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { tree } from '@/routes/families';
import { show as showDocument } from '@/routes/families/documents';
import {
    destroy as destroyMember,
    index as membersIndex,
    root as makeRoot,
    show as showMember,
} from '@/routes/families/members';
import {
    index as photosIndex,
    show as showPhoto,
} from '@/routes/families/photos';
import {
    destroy as destroyRelationship,
    finder,
    update as updateRelationship,
} from '@/routes/families/relationships';
import type {
    ChangeModeValue,
    DocumentItem,
    FamilyRef,
    GenealogyOptions,
    MemberNameItem,
    MemberProfile,
    MemberSummary,
    Option,
    PhotoItem,
    RelationshipInfo,
    Relatives,
    RelativeKind,
    TimelineItem,
} from '@/types';

type Props = {
    family: FamilyRef;
    member: MemberProfile;
    relatives: Relatives;
    names: MemberNameItem[];
    nameTypes: Option[];
    timeline: TimelineItem[];
    photos: PhotoItem[];
    documents: DocumentItem[];
    isRoot: boolean;
    linkedToMe: boolean;
    can: {
        update: boolean;
        delete: boolean;
        addPeople: boolean;
        manageRelationships: boolean;
        editRelationships: boolean;
        editNames: boolean;
        editRestricted: boolean;
        setRoot: boolean;
    };
    changeMode: {
        edit: ChangeModeValue;
        add: ChangeModeValue;
        delete: ChangeModeValue;
    };
    options: GenealogyOptions;
    errors?: Partial<Record<string, string>>;
};

const selectClass =
    'h-8 rounded-md border border-input bg-transparent px-2 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

const SIBLING_LABELS: Record<Relatives['siblings'][number]['kind'], string> = {
    full: 'Full sibling',
    half: 'Half-sibling',
    by_family: 'Sibling by adoption or step-family',
    sibling: 'Sibling',
};

function label(options: Option[], value: string | null | undefined): string {
    return options.find((option) => option.value === value)?.label ?? '';
}

function Fact({ term, children }: { term: string; children: ReactNode }) {
    if (children === null || children === undefined || children === '') {
        return null;
    }

    return (
        <div className="grid gap-0.5 sm:grid-cols-[10rem_1fr]">
            <dt className="text-sm text-muted-foreground">{term}</dt>
            <dd className="text-sm whitespace-pre-line">{children}</dd>
        </div>
    );
}

function PersonLink({
    family,
    person,
    note,
}: {
    family: FamilyRef;
    person: MemberSummary;
    note?: string | null;
}) {
    const body = (
        <>
            <MemberAvatar member={person} className="size-8" />
            <span className="min-w-0">
                <span className="block truncate text-sm font-medium">
                    {person.name}
                </span>
                <span className="block truncate text-xs text-muted-foreground">
                    {[note, person.lifespan].filter(Boolean).join(' · ')}
                </span>
            </span>
        </>
    );

    return person.hidden ? (
        <span className="flex min-w-0 flex-1 items-center gap-2">{body}</span>
    ) : (
        <Link
            href={showMember({ family: family.slug, member: person.id })}
            className="flex min-w-0 flex-1 items-center gap-2 rounded-md outline-none hover:underline focus-visible:ring-[3px] focus-visible:ring-ring/60"
        >
            {body}
        </Link>
    );
}

function RelationshipControls({
    family,
    relationship,
    person,
    choices,
    field,
}: {
    family: FamilyRef;
    relationship: RelationshipInfo;
    person: MemberSummary;
    choices: Option[];
    /** Which property the dropdown edits: the kind of parent link, or the marriage status. */
    field: 'subtype' | 'status';
}) {
    const args = { family: family.slug, relationship: relationship.id };

    return (
        <div className="flex shrink-0 items-center gap-1">
            <select
                className={selectClass}
                aria-label={`${field === 'subtype' ? 'Kind of link' : 'Status'} with ${person.name}`}
                value={relationship[field] ?? ''}
                onChange={(event) =>
                    router.patch(
                        updateRelationship.url(args),
                        { [field]: event.target.value },
                        { preserveScroll: true },
                    )
                }
            >
                {choices.map((choice) => (
                    <option key={choice.value} value={choice.value}>
                        {choice.label}
                    </option>
                ))}
            </select>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className="size-8 text-muted-foreground"
                aria-label={`Remove the link with ${person.name}`}
                onClick={() => {
                    if (
                        window.confirm(
                            `Remove the link with ${person.name}? Both people stay in the tree.`,
                        )
                    ) {
                        router.delete(destroyRelationship.url(args), {
                            preserveScroll: true,
                        });
                    }
                }}
            >
                <Trash2 className="size-4" />
            </Button>
        </div>
    );
}

function RelativeSection({
    title,
    empty,
    children,
    count,
}: {
    title: string;
    empty: string;
    count: number;
    children: ReactNode;
}) {
    return (
        <section className="min-w-0">
            <h3 className="mb-2 text-sm font-medium">{title}</h3>
            {count === 0 ? (
                <p className="text-sm text-muted-foreground">{empty}</p>
            ) : (
                <ul className="grid gap-2">{children}</ul>
            )}
        </section>
    );
}

export default function MemberShow({
    family,
    member,
    relatives,
    names,
    nameTypes,
    timeline,
    photos,
    documents,
    isRoot,
    linkedToMe,
    can,
    changeMode,
    options,
    errors = {},
}: Props) {
    const [editing, setEditing] = useState(false);
    const [adding, setAdding] = useState<RelativeKind | null>(null);

    const row =
        'flex min-w-0 items-center justify-between gap-2 rounded-lg border p-2';

    return (
        <>
            <Head title={`${member.name} – ${family.name}`} />

            <div className="mx-auto grid w-full max-w-4xl gap-8 p-4 md:p-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="flex items-start gap-4">
                        <MemberAvatar
                            member={member}
                            className="size-16 text-lg"
                        />
                        <div>
                            <h1 className="text-2xl font-semibold tracking-tight">
                                {member.name}
                            </h1>
                            <p className="text-muted-foreground">
                                {member.lifespan ??
                                    (member.living
                                        ? 'Living'
                                        : 'Dates not recorded')}
                                {member.nickname &&
                                    ` · known as ${member.nickname}`}
                            </p>
                            <div className="mt-2 flex flex-wrap gap-1">
                                {linkedToMe && <Badge>This is you</Badge>}
                                {member.generation !== null && (
                                    <Badge variant="secondary">
                                        Generation {member.generation}
                                    </Badge>
                                )}
                                {isRoot && (
                                    <Badge variant="secondary">
                                        Tree opens here
                                    </Badge>
                                )}
                                {member.is_placeholder && (
                                    <Badge variant="outline">
                                        Unknown person (placeholder)
                                    </Badge>
                                )}
                                {member.visibility &&
                                    member.visibility !== 'family' && (
                                        <Badge variant="outline">
                                            {label(
                                                options.visibilities,
                                                member.visibility,
                                            )}
                                        </Badge>
                                    )}
                            </div>
                        </div>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" size="sm" asChild>
                            <Link
                                href={tree(family.slug, {
                                    query: { focus: member.id },
                                })}
                            >
                                <Network aria-hidden="true" />
                                Show in tree
                            </Link>
                        </Button>
                        {!linkedToMe && (
                            <Button variant="outline" size="sm" asChild>
                                <Link
                                    href={finder.url(family.slug, {
                                        query: { to: member.id },
                                    })}
                                >
                                    <Waypoints aria-hidden="true" />
                                    How are we related?
                                </Link>
                            </Button>
                        )}
                        <Button variant="outline" size="sm" asChild>
                            <Link
                                href={membersIndex.url(family.slug, {
                                    query: { branch: member.id },
                                })}
                            >
                                <Users aria-hidden="true" />
                                Descendants
                            </Link>
                        </Button>
                        {can.update && (
                            <Button
                                type="button"
                                size="sm"
                                onClick={() => setEditing(true)}
                            >
                                <Pencil aria-hidden="true" />
                                Edit
                            </Button>
                        )}
                    </div>
                </header>

                {(changeMode.edit === 'propose' ||
                    changeMode.add === 'propose') && (
                    <p className="rounded-lg border bg-muted/40 p-3 text-sm text-muted-foreground">
                        Your changes here are sent for approval before they
                        appear in the tree.
                    </p>
                )}

                {member.sensitive_hidden && (
                    <p className="rounded-lg border bg-muted/40 p-3 text-sm text-muted-foreground">
                        This person is living, so their dates, places and
                        personal details are only shown to editors and
                        administrators of the family.
                    </p>
                )}

                <section aria-labelledby="details-heading">
                    <h2
                        id="details-heading"
                        className="mb-3 text-base font-medium"
                    >
                        Details
                    </h2>
                    <dl className="grid gap-3">
                        <Fact term="Born">
                            {[member.birth?.label, member.birth_place]
                                .filter(Boolean)
                                .join(', ')}
                        </Fact>
                        <Fact term="Died">
                            {[member.death.label, member.death_place]
                                .filter(Boolean)
                                .join(', ')}
                        </Fact>
                        <Fact term="Gender">
                            {label(options.genders, member.gender)}
                        </Fact>
                        <Fact term="Maiden or birth name">
                            {member.maiden_name}
                        </Fact>
                        <Fact term="Hometown">{member.hometown}</Fact>
                        <Fact term="Nationality">{member.nationality}</Fact>
                        <Fact term="Occupation">{member.occupation}</Fact>
                        <Fact term="Education">{member.education}</Fact>
                        <Fact term="Life story">{member.biography}</Fact>
                        <Fact term="Research notes">{member.notes}</Fact>
                    </dl>
                </section>

                <section aria-labelledby="relatives-heading">
                    <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <h2
                            id="relatives-heading"
                            className="text-base font-medium"
                        >
                            Close family
                        </h2>
                        {can.manageRelationships && (
                            <div
                                className="flex flex-wrap items-center gap-2"
                                role="group"
                                aria-label="Add a relative"
                            >
                                <UserPlus
                                    className="size-4 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                {(
                                    [
                                        ['parent', 'Parent'],
                                        ['partner', 'Partner'],
                                        ['child', 'Child'],
                                        ['sibling', 'Sibling'],
                                    ] as const
                                ).map(([kind, text]) => (
                                    <Button
                                        key={kind}
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() => setAdding(kind)}
                                    >
                                        <span className="sr-only">Add </span>
                                        {text}
                                    </Button>
                                ))}
                            </div>
                        )}
                    </div>

                    <InputError
                        message={errors.relationship}
                        className="mb-3"
                    />

                    <div className="grid gap-6 md:grid-cols-2">
                        <RelativeSection
                            title="Parents"
                            empty="No parents recorded."
                            count={relatives.parents.length}
                        >
                            {relatives.parents.map(
                                ({ relationship, person }) => (
                                    <li key={relationship.id} className={row}>
                                        <PersonLink
                                            family={family}
                                            person={person}
                                            note={
                                                relationship.subtype ===
                                                'biological'
                                                    ? null
                                                    : label(
                                                          options.parentSubtypes,
                                                          relationship.subtype,
                                                      )
                                            }
                                        />
                                        {can.editRelationships &&
                                            !person.hidden && (
                                                <RelationshipControls
                                                    family={family}
                                                    relationship={relationship}
                                                    person={person}
                                                    choices={
                                                        options.parentSubtypes
                                                    }
                                                    field="subtype"
                                                />
                                            )}
                                    </li>
                                ),
                            )}
                        </RelativeSection>

                        <RelativeSection
                            title="Spouses and partners"
                            empty="No partners recorded."
                            count={relatives.partners.length}
                        >
                            {relatives.partners.map(
                                ({ relationship, person }) => (
                                    <li key={relationship.id} className={row}>
                                        <PersonLink
                                            family={family}
                                            person={person}
                                            note={[
                                                label(
                                                    options.partnershipSubtypes,
                                                    relationship.subtype,
                                                ),
                                                relationship.start?.label,
                                            ]
                                                .filter(Boolean)
                                                .join(' ')}
                                        />
                                        {can.editRelationships &&
                                            !person.hidden && (
                                                <RelationshipControls
                                                    family={family}
                                                    relationship={relationship}
                                                    person={person}
                                                    choices={
                                                        options.partnershipStatuses
                                                    }
                                                    field="status"
                                                />
                                            )}
                                    </li>
                                ),
                            )}
                        </RelativeSection>

                        <RelativeSection
                            title="Children"
                            empty="No children recorded."
                            count={relatives.children.length}
                        >
                            {relatives.children.map(
                                ({ relationship, person, other_parents }) => (
                                    <li key={relationship.id} className={row}>
                                        <PersonLink
                                            family={family}
                                            person={person}
                                            note={
                                                [
                                                    relationship.subtype ===
                                                    'biological'
                                                        ? null
                                                        : label(
                                                              options.parentSubtypes,
                                                              relationship.subtype,
                                                          ),
                                                    other_parents.length > 0
                                                        ? `with ${other_parents.map((parent) => parent.name).join(' and ')}`
                                                        : null,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ') || null
                                            }
                                        />
                                        {can.editRelationships &&
                                            !person.hidden && (
                                                <RelationshipControls
                                                    family={family}
                                                    relationship={relationship}
                                                    person={person}
                                                    choices={
                                                        options.parentSubtypes
                                                    }
                                                    field="subtype"
                                                />
                                            )}
                                    </li>
                                ),
                            )}
                        </RelativeSection>

                        <RelativeSection
                            title="Brothers and sisters"
                            empty="No siblings recorded."
                            count={relatives.siblings.length}
                        >
                            {relatives.siblings.map(({ person, kind }) => (
                                <li key={person.id} className={row}>
                                    <PersonLink
                                        family={family}
                                        person={person}
                                        note={SIBLING_LABELS[kind]}
                                    />
                                </li>
                            ))}
                        </RelativeSection>
                    </div>
                </section>

                <MemberNames
                    family={family}
                    memberId={member.id}
                    names={names}
                    types={nameTypes}
                    canEdit={can.editNames}
                />

                {photos.length > 0 && (
                    <section aria-labelledby="photos-heading">
                        <div className="mb-3 flex items-baseline justify-between gap-2">
                            <h2
                                id="photos-heading"
                                className="text-base font-medium"
                            >
                                Photos
                            </h2>
                            <Link
                                href={photosIndex.url(family.slug, {
                                    query: { person: member.id },
                                })}
                                className="text-sm underline underline-offset-4"
                            >
                                All photos of {member.name}
                            </Link>
                        </div>
                        <ul className="grid grid-cols-3 gap-2 sm:grid-cols-4 md:grid-cols-6">
                            {photos.map(
                                (photo) =>
                                    photo.urls && (
                                        <li
                                            key={photo.id}
                                            className="aspect-square overflow-hidden rounded-lg border bg-muted"
                                        >
                                            <Link
                                                href={showPhoto({
                                                    family: family.slug,
                                                    photo: photo.id,
                                                })}
                                            >
                                                <img
                                                    src={photo.urls.thumb}
                                                    alt={
                                                        photo.caption ??
                                                        `Photo of ${member.name}`
                                                    }
                                                    loading="lazy"
                                                    className="size-full object-cover"
                                                />
                                            </Link>
                                        </li>
                                    ),
                            )}
                        </ul>
                    </section>
                )}

                {documents.length > 0 && (
                    <section aria-labelledby="documents-heading">
                        <h2
                            id="documents-heading"
                            className="mb-3 text-base font-medium"
                        >
                            Documents
                        </h2>
                        <ul className="grid gap-1">
                            {documents.map((document) => (
                                <li
                                    key={document.id}
                                    className="flex flex-wrap items-baseline gap-x-3 text-sm"
                                >
                                    <Link
                                        href={showDocument({
                                            family: family.slug,
                                            document: document.id,
                                        })}
                                        className="font-medium underline underline-offset-2"
                                    >
                                        {document.title}
                                    </Link>
                                    <span className="text-xs text-muted-foreground">
                                        {document.category_label}
                                        {document.issued &&
                                            ` · ${document.issued}`}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {timeline.length > 0 && (
                    <section aria-labelledby="life-heading">
                        <h2
                            id="life-heading"
                            className="mb-3 text-base font-medium"
                        >
                            Life events
                        </h2>
                        <TimelineList family={family} items={timeline} />
                    </section>
                )}

                {(can.setRoot || can.delete) && (
                    <section
                        aria-labelledby="manage-heading"
                        className="flex flex-wrap items-center gap-2 border-t pt-6"
                    >
                        <h2 id="manage-heading" className="sr-only">
                            Manage this record
                        </h2>
                        {can.setRoot && !isRoot && !member.is_placeholder && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    router.post(
                                        makeRoot.url({
                                            family: family.slug,
                                            member: member.id,
                                        }),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Open the tree on this person
                            </Button>
                        )}
                        {can.delete && (
                            <Button
                                type="button"
                                variant="destructive"
                                size="sm"
                                onClick={() => {
                                    if (
                                        window.confirm(
                                            `Remove ${member.name} from the tree? Their links to relatives are removed too.`,
                                        )
                                    ) {
                                        router.delete(
                                            destroyMember.url({
                                                family: family.slug,
                                                member: member.id,
                                            }),
                                        );
                                    }
                                }}
                            >
                                <Trash2 aria-hidden="true" />
                                {changeMode.delete === 'propose'
                                    ? 'Propose removal'
                                    : 'Remove from tree'}
                            </Button>
                        )}
                        <Button variant="ghost" size="sm" asChild>
                            <Link href={membersIndex(family.slug)}>
                                All members
                            </Link>
                        </Button>
                    </section>
                )}
            </div>

            {editing && (
                <MemberEditDialog
                    family={family}
                    member={member}
                    options={options}
                    canEditRestricted={can.editRestricted}
                    onClose={() => setEditing(false)}
                />
            )}

            {adding && (
                <AddRelativeDialog
                    key={`${member.id}-${adding}`}
                    family={family}
                    anchor={{ id: member.id, name: member.name }}
                    kind={adding}
                    options={options}
                    partners={relatives.partners
                        .map((partner) => partner.person)
                        .filter((person) => !person.hidden)}
                    parents={relatives.parents
                        .map((parent) => parent.person)
                        .filter((person) => !person.hidden)}
                    onClose={() => setAdding(null)}
                />
            )}
        </>
    );
}
