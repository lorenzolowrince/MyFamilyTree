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
    filters: { search: string };
    can: { addPeople: boolean };
};

export default function MembersIndex({ family, members, filters }: Props) {
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
                    className="flex max-w-md items-end gap-2"
                    role="search"
                >
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
                </Form>

                <p className="text-sm text-muted-foreground" role="status">
                    {members.total.toLocaleString()}{' '}
                    {members.total === 1 ? 'person' : 'people'}
                    {filters.search && ` matching “${filters.search}”`}
                </p>

                {members.data.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">
                        {filters.search
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
