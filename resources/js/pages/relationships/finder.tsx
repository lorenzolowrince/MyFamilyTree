import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowLeftRight,
    ArrowUp,
    Heart,
    Network,
} from 'lucide-react';
import { useState } from 'react';
import { MemberAvatar } from '@/components/members/member-avatar';
import { MemberMultiPicker } from '@/components/members/member-multi-picker';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { tree } from '@/routes/families';
import { show as showMember } from '@/routes/families/members';
import { finder } from '@/routes/families/relationships';
import type { FamilyRef, MemberSummary } from '@/types';

type Step = {
    person: MemberSummary;
    via: 'parent' | 'child' | 'partner' | null;
    subtype: string | null;
};

type Result = {
    kind: string;
    label: string;
    uncertain: boolean;
    path: Step[];
    also: MemberSummary[];
};

type Props = {
    family: FamilyRef;
    from: MemberSummary | null;
    to: MemberSummary | null;
    results: Result[];
};

const QUALIFIERS: Record<string, string> = {
    adoptive: 'adoptive',
    step: 'step',
    foster: 'foster',
    guardian: 'guardian',
};

/** How one person on the path is linked to the one before: "father", "adoptive child", "wife". */
function stepWord(step: Step): string {
    const { gender } = step.person;
    const pick = (male: string, female: string, neutral: string) =>
        gender === 'male' ? male : gender === 'female' ? female : neutral;
    const qualifier = QUALIFIERS[step.subtype ?? ''];

    if (step.via === 'partner') {
        return step.subtype === 'marriage' ||
            step.subtype === 'customary_marriage'
            ? pick('husband', 'wife', 'spouse')
            : 'partner';
    }

    const word =
        step.via === 'parent'
            ? pick('father', 'mother', 'parent')
            : pick('son', 'daughter', 'child');

    return qualifier ? `${qualifier} ${word}` : word;
}

function PathCard({ family, result }: { family: FamilyRef; result: Result }) {
    const apex = result.path.reduce(
        (top, step, index) => (step.via === 'parent' ? index : top),
        -1,
    );

    return (
        <ol className="grid gap-1">
            {result.path.map((step, index) => {
                const Icon =
                    step.via === 'parent'
                        ? ArrowUp
                        : step.via === 'child'
                          ? ArrowDown
                          : Heart;

                return (
                    <li
                        key={`${step.person.id}-${index}`}
                        className="grid gap-1"
                    >
                        {step.via && (
                            <p className="flex items-center gap-2 pl-3 text-xs text-muted-foreground">
                                <Icon className="size-3.5" aria-hidden="true" />
                                whose {stepWord(step)} is
                            </p>
                        )}
                        <div className="flex items-center gap-2.5 rounded-lg border p-2">
                            <MemberAvatar
                                member={step.person}
                                className="size-8"
                            />
                            {step.person.hidden ? (
                                <span className="min-w-0 flex-1 truncate text-sm text-muted-foreground">
                                    {step.person.name}
                                </span>
                            ) : (
                                <Link
                                    href={showMember({
                                        family: family.slug,
                                        member: step.person.id,
                                    })}
                                    className="min-w-0 flex-1 truncate text-sm font-medium hover:underline"
                                >
                                    {step.person.name}
                                    {step.person.lifespan && (
                                        <span className="font-normal text-muted-foreground">
                                            {' '}
                                            · {step.person.lifespan}
                                        </span>
                                    )}
                                </Link>
                            )}
                            {index === apex && result.also.length > 0 && (
                                <span className="shrink-0 text-xs text-muted-foreground">
                                    and{' '}
                                    {result.also
                                        .map((person) => person.name)
                                        .join(', ')}
                                </span>
                            )}
                            {index === apex && (
                                <Badge variant="secondary">
                                    Shared ancestor
                                </Badge>
                            )}
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}

export default function RelationshipFinder({
    family,
    from,
    to,
    results,
}: Props) {
    const [first, setFirst] = useState<MemberSummary | null>(from);
    const [second, setSecond] = useState<MemberSummary | null>(to);

    const find = (a: MemberSummary | null, b: MemberSummary | null) =>
        router.get(
            finder.url(family.slug, {
                query: { from: a?.id, to: b?.id },
            }),
            {},
            { preserveState: false },
        );

    const hasPath = (result: Result) =>
        result.path.length > 1 && result.kind !== 'self';

    return (
        <>
            <Head title={`${family.name} – Relationship finder`} />

            <div className="mx-auto grid w-full max-w-3xl gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Relationship finder
                    </h1>
                    <p className="text-muted-foreground">
                        Choose two people to see how they are related.
                    </p>
                </div>

                <form
                    className="grid gap-4 rounded-xl border p-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        find(first, second);
                    }}
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <MemberMultiPicker
                            family={family}
                            label="First person"
                            value={first ? [first] : []}
                            onChange={(people) =>
                                setFirst(people[people.length - 1] ?? null)
                            }
                        />
                        <MemberMultiPicker
                            family={family}
                            label="Second person"
                            value={second ? [second] : []}
                            onChange={(people) =>
                                setSecond(people[people.length - 1] ?? null)
                            }
                        />
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button type="submit" disabled={!first || !second}>
                            Find relationship
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={!first && !second}
                            onClick={() => {
                                setFirst(second);
                                setSecond(first);

                                if (first && second) {
                                    find(second, first);
                                }
                            }}
                        >
                            <ArrowLeftRight aria-hidden="true" />
                            Swap
                        </Button>
                    </div>
                </form>

                {from && to && (
                    <section aria-live="polite" className="grid gap-4">
                        {results.map((result, index) => (
                            <article
                                key={`${result.kind}-${result.label}`}
                                className="grid gap-4 rounded-xl border p-4"
                            >
                                <div className="grid gap-1">
                                    {index > 0 && (
                                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                            Also
                                        </p>
                                    )}
                                    <h2 className="text-lg font-medium">
                                        {result.kind === 'self' ||
                                        result.kind === 'unrelated' ||
                                        result.kind === 'connected' ? (
                                            <>
                                                {to.name} and {from.name}:{' '}
                                                {result.label}
                                            </>
                                        ) : (
                                            <>
                                                {to.name} is {from.name}&apos;s{' '}
                                                <strong className="font-semibold">
                                                    {result.label}
                                                </strong>
                                            </>
                                        )}
                                    </h2>
                                    {result.uncertain && (
                                        <p className="text-sm text-amber-700 dark:text-amber-400">
                                            This depends on a link recorded as
                                            possible or disputed.
                                        </p>
                                    )}
                                    {result.kind === 'unrelated' && (
                                        <p className="text-sm text-muted-foreground">
                                            No chain of parents, children or
                                            partners joins them yet. A missing
                                            link in the tree may be the reason.
                                        </p>
                                    )}
                                </div>

                                {hasPath(result) && (
                                    <PathCard family={family} result={result} />
                                )}
                            </article>
                        ))}

                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" size="sm" asChild>
                                <Link
                                    href={tree(family.slug, {
                                        query: { focus: from.id },
                                    })}
                                >
                                    <Network aria-hidden="true" />
                                    Show {from.name} in the tree
                                </Link>
                            </Button>
                            <Button variant="outline" size="sm" asChild>
                                <Link
                                    href={tree(family.slug, {
                                        query: { focus: to.id },
                                    })}
                                >
                                    <Network aria-hidden="true" />
                                    Show {to.name} in the tree
                                </Link>
                            </Button>
                        </div>
                    </section>
                )}
            </div>
        </>
    );
}
