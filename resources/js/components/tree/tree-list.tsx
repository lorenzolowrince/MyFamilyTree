import { MemberAvatar } from '@/components/members/member-avatar';
import { cn } from '@/lib/utils';
import type {
    MemberSummary,
    TreeAncestor,
    TreeSlice,
    TreeUnit,
    TreeView,
} from '@/types';

type Pick = (personId: string) => void;

function PersonButton({
    person,
    selected,
    note,
    onPick,
}: {
    person: MemberSummary | undefined;
    selected: boolean;
    note?: string | null;
    onPick: Pick;
}) {
    if (!person) {
        return null;
    }

    return (
        <button
            type="button"
            onClick={() => onPick(person.id)}
            aria-pressed={selected}
            className={cn(
                'flex w-full items-center gap-2 rounded-lg border bg-card px-2.5 py-1.5 text-left outline-none hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/60',
                selected && 'border-primary ring-2 ring-primary/40',
            )}
        >
            <MemberAvatar member={person} className="size-8" />
            <span className="min-w-0 flex-1">
                <span className="block truncate text-sm font-medium">
                    {person.name}
                    {person.is_me && (
                        <span className="font-normal text-muted-foreground">
                            {' '}
                            (you)
                        </span>
                    )}
                </span>
                <span className="block truncate text-xs text-muted-foreground">
                    {[note, person.lifespan].filter(Boolean).join(' · ') ||
                        (person.living && !person.hidden ? 'Living' : '')}
                </span>
            </span>
        </button>
    );
}

function linkNote(link: string | null): string | null {
    return link && link !== 'biological' ? `${link} link` : null;
}

function Descendants({
    unit,
    people,
    selectedId,
    onPick,
}: {
    unit: TreeUnit;
    people: Record<string, MemberSummary>;
    selectedId: string | null;
    onPick: Pick;
}) {
    return (
        <li className="grid gap-1.5">
            <PersonButton
                person={people[unit.person]}
                selected={selectedId === unit.person}
                note={unit.repeat ? 'see also' : linkNote(unit.link)}
                onPick={onPick}
            />
            {unit.more && (
                <p className="pl-3 text-xs text-muted-foreground">
                    Has descendants not shown. Select and choose “Centre tree
                    here”.
                </p>
            )}
            {unit.unions.map((union, index) => (
                <div
                    key={union.partner ?? `single-${index}`}
                    className="ml-4 grid gap-1.5 border-l pl-3"
                >
                    {union.partner && (
                        <PersonButton
                            person={people[union.partner]}
                            selected={selectedId === union.partner}
                            note={
                                union.relationship
                                    ? `partner (${union.relationship.status ?? union.relationship.subtype})`
                                    : 'other parent'
                            }
                            onPick={onPick}
                        />
                    )}
                    {union.children.length > 0 && (
                        <ul
                            className="grid gap-1.5"
                            aria-label={`Children of ${people[unit.person]?.name ?? 'this person'}${union.partner ? ` and ${people[union.partner]?.name ?? 'partner'}` : ''}`}
                        >
                            {union.children.map((child, childIndex) => (
                                <Descendants
                                    key={`${child.person}-${childIndex}`}
                                    unit={child}
                                    people={people}
                                    selectedId={selectedId}
                                    onPick={onPick}
                                />
                            ))}
                        </ul>
                    )}
                </div>
            ))}
        </li>
    );
}

function Ancestors({
    node,
    people,
    selectedId,
    onPick,
    root = false,
}: {
    node: TreeAncestor;
    people: Record<string, MemberSummary>;
    selectedId: string | null;
    onPick: Pick;
    root?: boolean;
}) {
    return (
        <li className="grid gap-1.5">
            {!root && (
                <PersonButton
                    person={people[node.person]}
                    selected={selectedId === node.person}
                    note={node.repeat ? 'see also' : linkNote(node.link)}
                    onPick={onPick}
                />
            )}
            {node.more && (
                <p className="pl-3 text-xs text-muted-foreground">
                    Has ancestors not shown. Select and choose “Centre tree
                    here”.
                </p>
            )}
            {node.parents.length > 0 && (
                <ul
                    className={cn(
                        'grid gap-1.5',
                        !root && 'ml-4 border-l pl-3',
                    )}
                    aria-label={`Parents of ${people[node.person]?.name ?? 'this person'}`}
                >
                    {node.parents.map((parent, index) => (
                        <Ancestors
                            key={`${parent.person}-${index}`}
                            node={parent}
                            people={people}
                            selectedId={selectedId}
                            onPick={onPick}
                        />
                    ))}
                </ul>
            )}
        </li>
    );
}

/** The same slice as nested lists: the accessible, small-screen alternative to the drawing. */
export function TreeList({
    slice,
    people,
    view,
    selectedId,
    onPick,
}: {
    slice: TreeSlice;
    people: Record<string, MemberSummary>;
    view: TreeView;
    selectedId: string | null;
    onPick: Pick;
}) {
    return (
        <div className="grid gap-6 rounded-xl border p-4">
            {view !== 'descendants' && (
                <section aria-labelledby="list-ancestors">
                    <h2
                        id="list-ancestors"
                        className="mb-2 text-sm font-medium"
                    >
                        Ancestors of {people[slice.focus]?.name}
                    </h2>
                    {slice.ancestors.parents.length === 0 &&
                    !slice.ancestors.more ? (
                        <p className="text-sm text-muted-foreground">
                            No parents recorded yet.
                        </p>
                    ) : (
                        <ul className="grid gap-1.5">
                            <Ancestors
                                node={slice.ancestors}
                                people={people}
                                selectedId={selectedId}
                                onPick={onPick}
                                root
                            />
                        </ul>
                    )}
                </section>
            )}
            {view !== 'ancestors' && (
                <section aria-labelledby="list-descendants">
                    <h2
                        id="list-descendants"
                        className="mb-2 text-sm font-medium"
                    >
                        {people[slice.focus]?.name}, partners and descendants
                    </h2>
                    <ul className="grid gap-1.5">
                        <Descendants
                            unit={slice.descendants}
                            people={people}
                            selectedId={selectedId}
                            onPick={onPick}
                        />
                    </ul>
                </section>
            )}
        </div>
    );
}
