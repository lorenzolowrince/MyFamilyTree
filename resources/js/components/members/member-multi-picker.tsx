import { X } from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import { MemberAvatar } from '@/components/members/member-avatar';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { getJson } from '@/lib/http';
import { lookup } from '@/routes/families/members';
import type { FamilyRef, MemberSummary } from '@/types';

/** Choose several people of the family by searching their names. */
export function MemberMultiPicker({
    family,
    label,
    value,
    onChange,
}: {
    family: FamilyRef;
    label: string;
    value: MemberSummary[];
    onChange: (people: MemberSummary[]) => void;
}) {
    const id = useId();
    const [search, setSearch] = useState('');
    const [results, setResults] = useState<MemberSummary[]>([]);

    useEffect(() => {
        if (search.trim().length < 2) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            getJson<{ members: MemberSummary[] }>(
                lookup.url(family.slug, { query: { search } }),
                controller.signal,
            )
                .then((data) => setResults(data.members))
                .catch(() => undefined);
        }, 250);

        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [search, family.slug]);

    const chosen = new Set(value.map((person) => person.id));
    const shown =
        search.trim().length < 2
            ? []
            : results.filter((person) => !chosen.has(person.id));

    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>

            {value.length > 0 && (
                <ul className="flex flex-wrap gap-1.5" aria-label="Chosen">
                    {value.map((person) => (
                        <li
                            key={person.id}
                            className="flex items-center gap-1 rounded-full border bg-muted/50 py-0.5 pr-1 pl-2.5 text-sm"
                        >
                            {person.name}
                            <button
                                type="button"
                                onClick={() =>
                                    onChange(
                                        value.filter(
                                            (item) => item.id !== person.id,
                                        ),
                                    )
                                }
                                aria-label={`Remove ${person.name}`}
                                className="rounded-full p-0.5 text-muted-foreground outline-none hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/60"
                            >
                                <X className="size-3.5" />
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            <Input
                id={id}
                type="search"
                value={search}
                autoComplete="off"
                placeholder="Search by name"
                onChange={(event) => setSearch(event.target.value)}
            />

            {shown.length > 0 && (
                <ul className="grid max-h-44 gap-1 overflow-y-auto">
                    {shown.map((person) => (
                        <li key={person.id}>
                            <button
                                type="button"
                                onClick={() => {
                                    onChange([...value, person]);
                                    setSearch('');
                                }}
                                className="flex w-full items-center gap-2 rounded-lg border p-1.5 text-left text-sm outline-none hover:bg-muted focus-visible:ring-[3px] focus-visible:ring-ring/60"
                            >
                                <MemberAvatar
                                    member={person}
                                    className="size-7"
                                />
                                <span className="min-w-0 flex-1 truncate">
                                    {person.name}
                                    {person.lifespan && (
                                        <span className="text-muted-foreground">
                                            {' '}
                                            · {person.lifespan}
                                        </span>
                                    )}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
