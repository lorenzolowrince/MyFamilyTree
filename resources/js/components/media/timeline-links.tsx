import { Link, router } from '@inertiajs/react';
import { X } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { index as timelineIndex } from '@/routes/families/timeline';
import {
    destroy as detach,
    store as attach,
} from '@/routes/families/timeline/attachments';
import type { FamilyRef, TimelineLinks as Links } from '@/types';

/** The timeline entries a photo or document belongs to, with a way to add it to another. */
export function TimelineLinks({
    family,
    links,
    file,
}: {
    family: FamilyRef;
    links: Links;
    /** Which file to attach: `{ photo_id }` or `{ document_id }`. */
    file: { photo_id: string } | { document_id: string };
}) {
    const [entry, setEntry] = useState('');
    const attachedIds = new Set(links.attached.map((item) => item.entry_id));
    const choices = links.entries.filter((item) => !attachedIds.has(item.id));

    if (links.attached.length === 0 && !links.can_attach) {
        return null;
    }

    return (
        <section
            aria-labelledby="timeline-links-heading"
            className="grid gap-2"
        >
            <h2 id="timeline-links-heading" className="text-sm font-medium">
                On the timeline
            </h2>

            {links.attached.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Not attached to any timeline entry.
                </p>
            ) : (
                <ul className="grid gap-1">
                    {links.attached.map((item) => (
                        <li
                            key={item.id}
                            className="flex items-center justify-between gap-2 text-sm"
                        >
                            <Link
                                href={timelineIndex(family.slug)}
                                className="min-w-0 truncate underline underline-offset-2"
                            >
                                {item.title}
                            </Link>
                            {links.can_attach && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="size-7 shrink-0 text-muted-foreground"
                                    aria-label={`Remove from “${item.title}”`}
                                    onClick={() =>
                                        router.delete(
                                            detach.url({
                                                family: family.slug,
                                                entry: item.entry_id,
                                                attachment: item.id,
                                            }),
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    <X className="size-4" />
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {links.can_attach && choices.length > 0 && (
                <form
                    className="grid gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();

                        if (entry !== '') {
                            router.post(
                                attach.url({
                                    family: family.slug,
                                    entry,
                                }),
                                file,
                                {
                                    preserveScroll: true,
                                    onSuccess: () => setEntry(''),
                                },
                            );
                        }
                    }}
                >
                    <select
                        aria-label="Timeline entry to attach this to"
                        value={entry}
                        onChange={(event) => setEntry(event.target.value)}
                        className="h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    >
                        <option value="">Attach to a timeline entry…</option>
                        {choices.map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.year ? `${item.year} · ` : ''}
                                {item.title}
                            </option>
                        ))}
                    </select>
                    <Button
                        type="submit"
                        variant="outline"
                        className="justify-self-start"
                        disabled={entry === ''}
                    >
                        Attach
                    </Button>
                </form>
            )}
        </section>
    );
}
