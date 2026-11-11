import { Head, router, useForm, usePage } from '@inertiajs/react';
import { CalendarDays, History, MapPin, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
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
import { formatDate, formatDateTime } from '@/lib/dates';
import { convert, destroy, store, update } from '@/routes/families/events';
import type { FamilyEventItem, FamilyRef, Option } from '@/types';

type Props = {
    family: FamilyRef;
    upcoming: FamilyEventItem[];
    past: FamilyEventItem[];
    can: { manage: boolean };
    types: Option[];
};

const fieldClass =
    'w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

function EventDialog({
    family,
    event,
    types,
    onClose,
}: {
    family: FamilyRef;
    event: FamilyEventItem | null;
    types: Option[];
    onClose: () => void;
}) {
    const form = useForm({
        title: event?.title ?? '',
        type: event?.type ?? 'reunion',
        description: event?.description ?? '',
        starts_at: event?.starts_local ?? '',
        ends_at: event?.ends_local ?? '',
        all_day: event?.all_day ?? false,
        location_text: event?.location_text ?? '',
        notify_members: event?.notify_members ?? true,
    });

    const submit = (submitEvent: FormEvent) => {
        submitEvent.preventDefault();
        form.transform((data) => ({
            ...data,
            ends_at: data.ends_at || null,
        }));

        const settings = { preserveScroll: true, onSuccess: onClose };

        if (event) {
            form.patch(
                update.url({ family: family.slug, event: event.id }),
                settings,
            );
        } else {
            form.post(store.url(family.slug), settings);
        }
    };

    const inputType = form.data.all_day ? 'date' : 'datetime-local';
    const trim = (value: string) =>
        form.data.all_day ? value.slice(0, 10) : value;

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>
                        {event ? 'Edit event' : 'New family event'}
                    </DialogTitle>
                    <DialogDescription>
                        Times are in your own time zone.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="grid gap-4" noValidate>
                    <div className="grid gap-2">
                        <Label htmlFor="event-title">Title</Label>
                        <Input
                            id="event-title"
                            value={form.data.title}
                            maxLength={200}
                            onChange={(e) =>
                                form.setData('title', e.target.value)
                            }
                        />
                        <InputError message={form.errors.title} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="event-type">Kind</Label>
                        <select
                            id="event-type"
                            className={`${fieldClass} h-9`}
                            value={form.data.type}
                            onChange={(e) =>
                                form.setData('type', e.target.value)
                            }
                        >
                            {types.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>

                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            className="size-4 rounded border-input"
                            checked={form.data.all_day}
                            onChange={(e) => {
                                form.setData((data) => ({
                                    ...data,
                                    all_day: e.target.checked,
                                    starts_at: e.target.checked
                                        ? data.starts_at.slice(0, 10)
                                        : data.starts_at,
                                    ends_at: e.target.checked
                                        ? data.ends_at.slice(0, 10)
                                        : data.ends_at,
                                }));
                            }}
                        />
                        All day
                    </label>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="event-starts">Starts</Label>
                            <Input
                                id="event-starts"
                                type={inputType}
                                value={trim(form.data.starts_at)}
                                onChange={(e) =>
                                    form.setData('starts_at', e.target.value)
                                }
                            />
                            <InputError message={form.errors.starts_at} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="event-ends">Ends (optional)</Label>
                            <Input
                                id="event-ends"
                                type={inputType}
                                value={trim(form.data.ends_at)}
                                onChange={(e) =>
                                    form.setData('ends_at', e.target.value)
                                }
                            />
                            <InputError message={form.errors.ends_at} />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="event-location">Where (optional)</Label>
                        <Input
                            id="event-location"
                            value={form.data.location_text}
                            maxLength={300}
                            onChange={(e) =>
                                form.setData('location_text', e.target.value)
                            }
                        />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="event-description">
                            Details (optional)
                        </Label>
                        <textarea
                            id="event-description"
                            rows={4}
                            maxLength={10000}
                            value={form.data.description}
                            onChange={(e) =>
                                form.setData('description', e.target.value)
                            }
                            className={`${fieldClass} py-2`}
                        />
                    </div>

                    <DialogFooter className="sm:justify-between">
                        {event ? (
                            <Button
                                type="button"
                                variant="ghost"
                                className="text-red-600 dark:text-red-400"
                                onClick={() => {
                                    if (
                                        window.confirm(
                                            `Remove “${event.title}”?`,
                                        )
                                    ) {
                                        router.delete(
                                            destroy.url({
                                                family: family.slug,
                                                event: event.id,
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

function EventCard({
    family,
    event,
    canManage,
    timezone,
    onEdit,
}: {
    family: FamilyRef;
    event: FamilyEventItem;
    canManage: boolean;
    timezone: string;
    onEdit: () => void;
}) {
    const when = (iso: string) =>
        event.all_day
            ? formatDate(iso, timezone)
            : formatDateTime(iso, timezone);

    return (
        <li className="flex items-start justify-between gap-3 rounded-xl border p-4">
            <div className="min-w-0">
                <div className="mb-1 flex flex-wrap items-center gap-2">
                    <Badge variant="secondary">{event.type_label}</Badge>
                    {event.on_timeline && (
                        <Badge variant="outline">On the timeline</Badge>
                    )}
                </div>
                <h3 className="text-base font-medium">{event.title}</h3>
                <p className="flex items-center gap-1.5 text-sm text-muted-foreground">
                    <CalendarDays className="size-3.5" aria-hidden="true" />
                    {when(event.starts_at)}
                    {event.ends_at && ` to ${when(event.ends_at)}`}
                </p>
                {event.location_text && (
                    <p className="flex items-center gap-1.5 text-sm text-muted-foreground">
                        <MapPin className="size-3.5" aria-hidden="true" />
                        {event.location_text}
                    </p>
                )}
                {event.description && (
                    <p className="mt-2 text-sm whitespace-pre-line">
                        {event.description}
                    </p>
                )}
            </div>
            {canManage && (
                <div className="flex shrink-0 flex-col items-end gap-1">
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-8 text-muted-foreground"
                        aria-label={`Edit “${event.title}”`}
                        onClick={onEdit}
                    >
                        <Pencil className="size-4" />
                    </Button>
                    {event.happened && !event.on_timeline && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                router.post(
                                    convert.url({
                                        family: family.slug,
                                        event: event.id,
                                    }),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <History aria-hidden="true" />
                            Add to timeline
                        </Button>
                    )}
                </div>
            )}
        </li>
    );
}

export default function EventsIndex({
    family,
    upcoming,
    past,
    can,
    types,
}: Props) {
    const { auth } = usePage().props;
    const [editing, setEditing] = useState<FamilyEventItem | 'new' | null>(
        null,
    );

    return (
        <>
            <Head title={`${family.name} – Events`} />

            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-8 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Family events
                        </h1>
                        <p className="text-muted-foreground">
                            Reunions, celebrations and announcements for{' '}
                            {family.name}.
                        </p>
                    </div>
                    {can.manage && (
                        <Button type="button" onClick={() => setEditing('new')}>
                            <Plus aria-hidden="true" />
                            New event
                        </Button>
                    )}
                </div>

                <section aria-labelledby="upcoming-heading">
                    <h2
                        id="upcoming-heading"
                        className="mb-3 text-base font-medium"
                    >
                        Coming up
                    </h2>
                    {upcoming.length === 0 ? (
                        <p className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                            No events planned.
                        </p>
                    ) : (
                        <ul className="grid gap-3">
                            {upcoming.map((event) => (
                                <EventCard
                                    key={event.id}
                                    family={family}
                                    event={event}
                                    canManage={can.manage}
                                    timezone={auth.user.timezone}
                                    onEdit={() => setEditing(event)}
                                />
                            ))}
                        </ul>
                    )}
                </section>

                {past.length > 0 && (
                    <section aria-labelledby="past-heading">
                        <h2
                            id="past-heading"
                            className="mb-3 text-base font-medium"
                        >
                            Past events
                        </h2>
                        <ul className="grid gap-3">
                            {past.map((event) => (
                                <EventCard
                                    key={event.id}
                                    family={family}
                                    event={event}
                                    canManage={can.manage}
                                    timezone={auth.user.timezone}
                                    onEdit={() => setEditing(event)}
                                />
                            ))}
                        </ul>
                    </section>
                )}
            </div>

            {editing && (
                <EventDialog
                    key={editing === 'new' ? 'new' : editing.id}
                    family={family}
                    event={editing === 'new' ? null : editing}
                    types={types}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}
