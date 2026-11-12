import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, Loader2, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { useRefreshWhileChecking } from '@/components/media/media-status';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { tree } from '@/routes/families';
import { cancel, confirm, index } from '@/routes/families/gedcom';
import type { FamilyRef } from '@/types';
import { STATUS_LABELS } from './index';
import type { GedcomImportStatus } from './index';

type Match = {
    id: string;
    name: string;
    birth_year: number | null;
    death_year: number | null;
};

type Person = {
    xref: string;
    name: string;
    birth_year: number | null;
    death_year: number | null;
    gender: string | null;
    matches: Match[];
};

type Props = {
    family: FamilyRef;
    import: {
        id: string;
        filename: string | null;
        status: GedcomImportStatus;
        error: string | null;
        counts: {
            people: number;
            partnerships: number;
            parent_links: number;
            possible_duplicates: number;
        } | null;
        result: {
            created: number;
            merged: number;
            skipped: number;
            partnerships: number;
            parent_links: number;
            problems: string[];
        } | null;
        version: string | null;
        source: string | null;
        warnings: string[];
        duplicates: Person[];
        sample: Person[];
        suggested: Record<string, string>;
    };
    errors?: Partial<Record<string, string>>;
};

const years = (person: {
    birth_year: number | null;
    death_year: number | null;
}) =>
    person.birth_year || person.death_year
        ? `${person.birth_year ?? '?'}–${person.death_year ?? ''}`
        : 'no dates';

function Notes({ title, items }: { title: string; items: string[] }) {
    if (items.length === 0) {
        return null;
    }

    return (
        <details className="rounded-lg border border-amber-300 bg-amber-50 text-sm dark:border-amber-900 dark:bg-amber-950">
            <summary className="flex cursor-pointer items-center gap-2 px-3 py-2 font-medium">
                <TriangleAlert className="size-4" aria-hidden="true" />
                {title} ({items.length})
            </summary>
            <ul className="grid list-disc gap-1 border-t border-amber-300 py-2 pr-3 pl-8 dark:border-amber-900">
                {items.map((item, number) => (
                    <li key={`${number}-${item}`}>{item}</li>
                ))}
            </ul>
        </details>
    );
}

export default function GedcomShow({
    family,
    import: item,
    errors = {},
}: Props) {
    const [decisions, setDecisions] = useState<Record<string, string>>(() =>
        Object.fromEntries(
            item.duplicates.map((person) => [
                person.xref,
                item.suggested[person.xref] ?? 'create',
            ]),
        ),
    );
    const [submitting, setSubmitting] = useState(false);

    const busy =
        item.status === 'uploaded' ||
        item.status === 'parsing' ||
        item.status === 'importing';

    useRefreshWhileChecking(busy, ['import']);

    const merging = Object.values(decisions).filter(
        (decision) => decision !== 'create' && decision !== 'skip',
    ).length;
    const skipping = Object.values(decisions).filter(
        (decision) => decision === 'skip',
    ).length;

    return (
        <>
            <Head title={`${family.name} – Import`} />

            <div className="mx-auto grid w-full max-w-3xl gap-6 p-4 md:p-6">
                <div>
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={index(family.slug)}>
                            <ArrowLeft aria-hidden="true" />
                            Import and export
                        </Link>
                    </Button>
                </div>

                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {item.filename ?? 'GEDCOM file'}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {[
                                item.source && `From ${item.source}`,
                                item.version && `GEDCOM ${item.version}`,
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                        </p>
                    </div>
                    <Badge variant="outline">
                        {STATUS_LABELS[item.status]}
                    </Badge>
                </div>

                {busy && (
                    <p
                        role="status"
                        className="flex items-center gap-3 rounded-xl border p-4 text-sm"
                    >
                        <Loader2
                            className="size-5 motion-safe:animate-spin"
                            aria-hidden="true"
                        />
                        {item.status === 'importing'
                            ? 'Adding the people and their relationships to the tree. A large file can take several minutes; you can leave this page.'
                            : 'Reading the file…'}
                    </p>
                )}

                {item.status === 'failed' && (
                    <p
                        role="alert"
                        className="rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200"
                    >
                        {item.error ??
                            'The file could not be used. Nothing was changed in the tree.'}
                    </p>
                )}

                {item.status === 'cancelled' && (
                    <p className="rounded-xl border p-4 text-sm text-muted-foreground">
                        This import was cancelled. Nothing was changed in the
                        tree.
                    </p>
                )}

                {item.counts && item.status !== 'completed' && (
                    <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        {[
                            ['People', item.counts.people],
                            ['Couples', item.counts.partnerships],
                            ['Parent links', item.counts.parent_links],
                            [
                                'Possibly in the tree',
                                item.counts.possible_duplicates,
                            ],
                        ].map(([label, value]) => (
                            <div key={label} className="rounded-xl border p-3">
                                <dt className="text-xs text-muted-foreground">
                                    {label}
                                </dt>
                                <dd className="text-xl font-semibold tabular-nums">
                                    {Number(value).toLocaleString()}
                                </dd>
                            </div>
                        ))}
                    </dl>
                )}

                {item.status !== 'completed' && (
                    <Notes
                        title="Notes about this file"
                        items={item.warnings}
                    />
                )}

                {item.status === 'preview_ready' && (
                    <form
                        className="grid gap-6"
                        onSubmit={(event) => {
                            event.preventDefault();
                            setSubmitting(true);
                            router.post(
                                confirm.url({
                                    family: family.slug,
                                    import: item.id,
                                }),
                                { decisions },
                                { onFinish: () => setSubmitting(false) },
                            );
                        }}
                    >
                        {item.duplicates.length > 0 && (
                            <section
                                aria-labelledby="duplicates-heading"
                                className="grid gap-3"
                            >
                                <div>
                                    <h2
                                        id="duplicates-heading"
                                        className="text-base font-medium"
                                    >
                                        Already in the tree?
                                    </h2>
                                    <p className="text-sm text-muted-foreground">
                                        These people in the file have names
                                        close to people already in the tree.
                                        Choose the same person to add the
                                        file&apos;s details to them (nothing
                                        already recorded is overwritten), or add
                                        them as someone new.
                                    </p>
                                </div>
                                <ul className="grid gap-2">
                                    {item.duplicates.map((person) => (
                                        <li
                                            key={person.xref}
                                            className="grid gap-2 rounded-lg border p-3 sm:grid-cols-2 sm:items-center"
                                        >
                                            <label
                                                htmlFor={`decision-${person.xref}`}
                                                className="text-sm"
                                            >
                                                <span className="font-medium">
                                                    {person.name}
                                                </span>{' '}
                                                <span className="text-muted-foreground">
                                                    ({years(person)})
                                                </span>
                                            </label>
                                            <select
                                                id={`decision-${person.xref}`}
                                                value={decisions[person.xref]}
                                                onChange={(event) =>
                                                    setDecisions({
                                                        ...decisions,
                                                        [person.xref]:
                                                            event.target.value,
                                                    })
                                                }
                                                className="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                            >
                                                {person.matches.map((match) => (
                                                    <option
                                                        key={match.id}
                                                        value={match.id}
                                                    >
                                                        Same person as{' '}
                                                        {match.name} (
                                                        {years(match)})
                                                    </option>
                                                ))}
                                                <option value="create">
                                                    Someone new: add to the tree
                                                </option>
                                                <option value="skip">
                                                    Leave out
                                                </option>
                                            </select>
                                        </li>
                                    ))}
                                </ul>
                                <InputError message={errors.decisions} />
                            </section>
                        )}

                        {item.sample.length > 0 && (
                            <details className="rounded-lg border">
                                <summary className="cursor-pointer px-3 py-2 text-sm font-medium">
                                    New people in the file
                                    {item.counts &&
                                        item.counts.people -
                                            item.duplicates.length >
                                            item.sample.length &&
                                        ` (the first ${item.sample.length})`}
                                </summary>
                                <ul className="grid gap-x-6 border-t p-3 text-sm sm:grid-cols-2">
                                    {item.sample.map((person) => (
                                        <li
                                            key={person.xref}
                                            className="truncate"
                                        >
                                            {person.name}{' '}
                                            <span className="text-muted-foreground">
                                                ({years(person)})
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </details>
                        )}

                        {item.counts && (
                            <p className="text-sm" role="status">
                                {(
                                    item.counts.people -
                                    merging -
                                    skipping
                                ).toLocaleString()}{' '}
                                people will be added
                                {merging > 0 &&
                                    `, ${merging} matched with people already in the tree`}
                                {skipping > 0 && `, ${skipping} left out`}.
                            </p>
                        )}

                        <div className="flex flex-wrap gap-2">
                            <Button type="submit" disabled={submitting}>
                                Import into {family.name}
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() =>
                                    router.delete(
                                        cancel.url({
                                            family: family.slug,
                                            import: item.id,
                                        }),
                                    )
                                }
                            >
                                Cancel
                            </Button>
                        </div>
                    </form>
                )}

                {item.status === 'completed' && item.result && (
                    <section className="grid gap-4" aria-live="polite">
                        <p className="flex items-center gap-3 rounded-xl border p-4 text-sm">
                            <CheckCircle2
                                className="size-5 text-green-700 dark:text-green-400"
                                aria-hidden="true"
                            />
                            <span>
                                {item.result.created.toLocaleString()} people
                                added, {item.result.merged.toLocaleString()}{' '}
                                matched with people already in the tree,{' '}
                                {item.result.skipped.toLocaleString()} left out.{' '}
                                {item.result.partnerships.toLocaleString()}{' '}
                                couples and{' '}
                                {item.result.parent_links.toLocaleString()}{' '}
                                parent links recorded.
                            </span>
                        </p>
                        <Notes
                            title="Links that could not be recorded"
                            items={item.result.problems}
                        />
                        <div>
                            <Button asChild>
                                <Link href={tree(family.slug)}>
                                    Open the tree
                                </Link>
                            </Button>
                        </div>
                    </section>
                )}
            </div>
        </>
    );
}
