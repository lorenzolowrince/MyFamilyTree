import { Form, Head, Link } from '@inertiajs/react';
import { Network } from 'lucide-react';
import { MemberAvatar } from '@/components/members/member-avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { tree } from '@/routes/families';
import { index, show } from '@/routes/families/members';
import type { FamilyRef, MemberSummary, Paginated } from '@/types';

type Props = {
    family: FamilyRef;
    members: Paginated<MemberSummary>;
    filters: {
        search: string;
        surname: string;
        place: string;
        born_from: number | null;
        born_to: number | null;
        generation: number | null;
        status: 'living' | 'deceased' | null;
        branch: { id: string; name: string } | null;
    };
    /** The deepest generation in the tree. */
    generations: number;
    can: { addPeople: boolean };
};

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

export default function MembersIndex({
    family,
    members,
    filters,
    generations,
}: Props) {
    const narrowed =
        filters.surname !== '' ||
        filters.place !== '' ||
        filters.born_from !== null ||
        filters.born_to !== null ||
        filters.generation !== null ||
        filters.status !== null;

    return (
        <>
            <Head title={`${family.name} – Members`} />

            <div className="flex h-full flex-1 flex-col gap-4 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Family members
                        </h1>
                        <p className="text-muted-foreground">{family.name}</p>
                    </div>
                    <Button variant="outline" size="sm" asChild>
                        <Link href={tree(family.slug)}>
                            <Network aria-hidden="true" />
                            Family tree
                        </Link>
                    </Button>
                </div>

                <Form
                    {...index.form(family.slug)}
                    options={{ preserveState: true }}
                    className="grid max-w-3xl gap-3"
                    role="search"
                >
                    {filters.branch && (
                        <input
                            type="hidden"
                            name="branch"
                            value={filters.branch.id}
                        />
                    )}
                    <div className="flex max-w-md items-end gap-2">
                        <div className="grid flex-1 gap-2">
                            <Label htmlFor="search">Search by name</Label>
                            <Input
                                id="search"
                                type="search"
                                name="search"
                                defaultValue={filters.search}
                                placeholder="e.g. Lidau"
                            />
                        </div>
                        <Button type="submit" variant="outline">
                            Search
                        </Button>
                        {(narrowed || filters.search || filters.branch) && (
                            <Button variant="ghost" asChild>
                                <Link href={index(family.slug)}>Clear</Link>
                            </Button>
                        )}
                    </div>

                    <details open={narrowed} className="rounded-lg border">
                        <summary className="cursor-pointer px-3 py-2 text-sm font-medium">
                            More ways to search
                        </summary>
                        <div className="grid gap-3 border-t p-3 sm:grid-cols-2 lg:grid-cols-3">
                            <div className="grid gap-2">
                                <Label htmlFor="filter-surname">
                                    Surname or family name
                                </Label>
                                <Input
                                    id="filter-surname"
                                    name="surname"
                                    maxLength={100}
                                    defaultValue={filters.surname}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="filter-place">
                                    Place (birth, death or hometown)
                                </Label>
                                <Input
                                    id="filter-place"
                                    name="place"
                                    maxLength={100}
                                    defaultValue={filters.place}
                                    placeholder="e.g. Ranau"
                                />
                            </div>
                            <div className="grid grid-cols-2 gap-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="filter-born-from">
                                        Born from
                                    </Label>
                                    <Input
                                        id="filter-born-from"
                                        name="born_from"
                                        inputMode="numeric"
                                        maxLength={4}
                                        placeholder="Year"
                                        defaultValue={filters.born_from ?? ''}
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="filter-born-to">
                                        Born to
                                    </Label>
                                    <Input
                                        id="filter-born-to"
                                        name="born_to"
                                        inputMode="numeric"
                                        maxLength={4}
                                        placeholder="Year"
                                        defaultValue={filters.born_to ?? ''}
                                    />
                                </div>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="filter-generation">
                                    Generation
                                </Label>
                                <select
                                    id="filter-generation"
                                    name="generation"
                                    defaultValue={filters.generation ?? ''}
                                    className={selectClass}
                                >
                                    <option value="">Any</option>
                                    {Array.from(
                                        { length: generations },
                                        (_, number) => number + 1,
                                    ).map((number) => (
                                        <option key={number} value={number}>
                                            Generation {number}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="filter-status">
                                    Living or deceased
                                </Label>
                                <select
                                    id="filter-status"
                                    name="status"
                                    defaultValue={filters.status ?? ''}
                                    className={selectClass}
                                >
                                    <option value="">Either</option>
                                    <option value="living">Living</option>
                                    <option value="deceased">Deceased</option>
                                </select>
                            </div>
                            <div className="flex items-end">
                                <Button type="submit" variant="outline">
                                    Apply
                                </Button>
                            </div>
                        </div>
                    </details>
                </Form>

                {filters.branch && (
                    <p className="flex flex-wrap items-center gap-2 text-sm">
                        <Badge variant="secondary">
                            {filters.branch.name} and descendants
                        </Badge>
                        <Link
                            href={index(family.slug)}
                            className="underline underline-offset-4"
                        >
                            Show the whole family
                        </Link>
                    </p>
                )}

                <p className="text-sm text-muted-foreground" role="status">
                    {members.total.toLocaleString()}{' '}
                    {members.total === 1 ? 'person' : 'people'}
                    {filters.search && ` matching “${filters.search}”`}
                </p>

                {members.data.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">
                        {narrowed || filters.branch
                            ? 'Nobody matches. Details of living relatives that you are not allowed to see are never searched.'
                            : filters.search
                              ? 'Nobody found with that name. Spelling close to the name also works.'
                              : 'Nobody has been added yet. Open the family tree to add the first person.'}
                    </p>
                ) : (
                    <ul className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                        {members.data.map((member) => (
                            <li key={member.id}>
                                <Link
                                    href={show({
                                        family: family.slug,
                                        member: member.id,
                                    })}
                                    className="flex items-center gap-3 rounded-lg border bg-card p-3 outline-none hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/60"
                                >
                                    <MemberAvatar member={member} />
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-sm font-medium">
                                            {member.name}
                                        </span>
                                        <span className="block truncate text-xs text-muted-foreground">
                                            {member.lifespan ??
                                                (member.living
                                                    ? 'Living'
                                                    : 'Dates not recorded')}
                                        </span>
                                    </span>
                                    {member.is_me && <Badge>You</Badge>}
                                    {member.generation !== null && (
                                        <Badge variant="secondary">
                                            Gen {member.generation}
                                        </Badge>
                                    )}
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}

                {members.last_page > 1 && (
                    <nav
                        className="flex items-center justify-between"
                        aria-label="Pagination"
                    >
                        <p className="text-sm text-muted-foreground">
                            Page {members.current_page} of {members.last_page}
                        </p>
                        <div className="flex gap-2">
                            {members.prev_page_url && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={members.prev_page_url}>
                                        Previous
                                    </Link>
                                </Button>
                            )}
                            {members.next_page_url && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={members.next_page_url}>
                                        Next
                                    </Link>
                                </Button>
                            )}
                        </div>
                    </nav>
                )}
            </div>
        </>
    );
}
