import { Form, Head, Link, router, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { FuzzyDateInput } from '@/components/members/fuzzy-date-input';
import { MemberMultiPicker } from '@/components/members/member-multi-picker';
import { TimelineList } from '@/components/timeline/timeline-list';
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
import { destroy, index, store, update } from '@/routes/families/timeline';
import { emptyFuzzyDate } from '@/types/family';
import type {
    FamilyRef,
    FuzzyDateValue,
    GenealogyOptions,
    MemberSummary,
    Option,
    TimelineItem,
} from '@/types';

type Props = {
    family: FamilyRef;
    items: TimelineItem[];
    truncated: boolean;
    filters: {
        category: string | null;
        from: number | null;
        to: number | null;
        member: MemberSummary | null;
    };
    can: { create: boolean };
    options: GenealogyOptions & { categories: Option[] };
};

const fieldClass =
    'w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

type EntryForm = {
    title: string;
    category: string;
    description: string;
    event: FuzzyDateValue;
    place: string;
    visibility: string;
    participant_ids: string[];
};

function EntryDialog({
    family,
    item,
    options,
    onClose,
}: {
    family: FamilyRef;
    /** The entry being edited, or null for a new one. */
    item: TimelineItem | null;
    options: Props['options'];
    onClose: () => void;
}) {
    const [people, setPeople] = useState<MemberSummary[]>(item?.people ?? []);
    const form = useForm<EntryForm>({
        title: item?.title ?? '',
        category: item?.category ?? 'other',
        description: item?.description ?? '',
        event: item?.date ?? emptyFuzzyDate,
        place: item?.place ?? '',
        visibility: item?.visibility ?? 'family',
        participant_ids: [],
    });
    const errors = form.errors as Partial<Record<string, string>>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            participant_ids: people.map((person) => person.id),
        }));

        const settings = { preserveScroll: true, onSuccess: onClose };

        if (item) {
            form.patch(
                update.url({ family: family.slug, entry: item.id }),
                settings,
            );
        } else {
            form.post(store.url(family.slug), settings);
        }
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>
                        {item ? 'Edit timeline entry' : 'Add to the timeline'}
                    </DialogTitle>
                    <DialogDescription>
                        Births, deaths and marriages appear automatically from
                        people&apos;s records; add other moments here.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="grid gap-4" noValidate>
                    <div className="grid gap-2">
                        <Label htmlFor="entry-title">What happened</Label>
                        <Input
                            id="entry-title"
                            value={form.data.title}
                            maxLength={200}
                            onChange={(event) =>
                                form.setData('title', event.target.value)
                            }
                        />
                        <InputError message={errors.title} />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="entry-category">Kind</Label>
                            <select
                                id="entry-category"
                                className={`${fieldClass} h-9`}
                                value={form.data.category}
                                onChange={(event) =>
                                    form.setData('category', event.target.value)
                                }
                            >
                                {options.categories.map((option) => (
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
                            <Label htmlFor="entry-visibility">
                                Who can see it
                            </Label>
                            <select
                                id="entry-visibility"
                                className={`${fieldClass} h-9`}
                                value={form.data.visibility}
                                onChange={(event) =>
                                    form.setData(
                                        'visibility',
                                        event.target.value,
                                    )
                                }
                            >
                                {options.visibilities.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>

                    <FuzzyDateInput
                        label="When"
                        value={form.data.event}
                        onChange={(value) => form.setData('event', value)}
                        errors={errors}
                        errorPrefix="event"
                    />

                    <div className="grid gap-2">
                        <Label htmlFor="entry-place">Where (optional)</Label>
                        <Input
                            id="entry-place"
                            value={form.data.place}
                            maxLength={200}
                            onChange={(event) =>
                                form.setData('place', event.target.value)
                            }
                        />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="entry-description">
                            Details (optional)
                        </Label>
                        <textarea
                            id="entry-description"
                            rows={4}
                            maxLength={10000}
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                            className={`${fieldClass} py-2`}
                        />
                        <InputError message={errors.description} />
                    </div>

                    <MemberMultiPicker
                        family={family}
                        label="Who was involved (optional)"
                        value={people}
                        onChange={setPeople}
                    />

                    <DialogFooter className="sm:justify-between">
                        {item ? (
                            <Button
                                type="button"
                                variant="ghost"
                                className="text-red-600 dark:text-red-400"
                                onClick={() => {
                                    if (
                                        window.confirm(
                                            `Remove “${item.title}” from the timeline?`,
                                        )
                                    ) {
                                        router.delete(
                                            destroy.url({
                                                family: family.slug,
                                                entry: item.id,
                                            }),
                                            {
                                                preserveScroll: true,
                                                onSuccess: onClose,
                                            },
                                        );
                                    }
                                }}
                            >
                                Remove
                            </Button>
                        ) : (
                            <span />
                        )}
                        <span className="flex gap-2">
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={onClose}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                Save
                            </Button>
                        </span>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function TimelineIndex({
    family,
    items,
    truncated,
    filters,
    can,
    options,
}: Props) {
    const [editing, setEditing] = useState<TimelineItem | 'new' | null>(null);

    return (
        <>
            <Head title={`${family.name} – Timeline`} />

            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Family timeline
                        </h1>
                        <p className="text-muted-foreground">
                            {filters.member
                                ? `Moments involving ${filters.member.name}.`
                                : family.name}
                        </p>
                    </div>
                    {can.create && (
                        <Button type="button" onClick={() => setEditing('new')}>
                            <Plus aria-hidden="true" />
                            Add entry
                        </Button>
                    )}
                </div>

                <Form
                    {...index.form(family.slug)}
                    options={{ preserveState: true }}
                    className="flex flex-wrap items-end gap-3"
                    role="search"
                    aria-label="Filter the timeline"
                >
                    {filters.member && (
                        <input
                            type="hidden"
                            name="member"
                            value={filters.member.id}
                        />
                    )}
                    <div className="grid gap-2">
                        <Label htmlFor="filter-category">Show</Label>
                        <select
                            id="filter-category"
                            name="category"
                            defaultValue={filters.category ?? ''}
                            className={`${fieldClass} h-9 w-48`}
                        >
                            <option value="">Everything</option>
                            <option value="birth">Births</option>
                            <option value="death">Deaths</option>
                            <option value="marriage">Marriages</option>
                            {options.categories.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="filter-from">From year</Label>
                        <Input
                            id="filter-from"
                            name="from"
                            inputMode="numeric"
                            maxLength={4}
                            className="w-24"
                            defaultValue={filters.from ?? ''}
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="filter-to">To year</Label>
                        <Input
                            id="filter-to"
                            name="to"
                            inputMode="numeric"
                            maxLength={4}
                            className="w-24"
                            defaultValue={filters.to ?? ''}
                        />
                    </div>
                    <Button type="submit" variant="outline">
                        Apply
                    </Button>
                    {(filters.category ||
                        filters.from ||
                        filters.to ||
                        filters.member) && (
                        <Button variant="ghost" asChild>
                            <Link href={index(family.slug)}>Clear</Link>
                        </Button>
                    )}
                </Form>

                <p className="text-sm text-muted-foreground" role="status">
                    {items.length.toLocaleString()}{' '}
                    {items.length === 1 ? 'moment' : 'moments'}
                    {truncated &&
                        ' (the first 500; narrow the years to see more)'}
                </p>

                {items.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">
                        Nothing to show yet. Births, deaths and marriages appear
                        here once people have dates, and you can add other
                        moments yourself.
                    </p>
                ) : (
                    <TimelineList
                        family={family}
                        items={items}
                        onEdit={(item) => setEditing(item)}
                    />
                )}
            </div>

            {editing && (
                <EntryDialog
                    key={editing === 'new' ? 'new' : editing.id}
                    family={family}
                    item={editing === 'new' ? null : editing}
                    options={options}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}
