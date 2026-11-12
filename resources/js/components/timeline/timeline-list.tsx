import { Link } from '@inertiajs/react';
import {
    Baby,
    BookOpen,
    Briefcase,
    Flower2,
    Heart,
    Home,
    MapPin,
    Milestone,
    Pencil,
    Plane,
    Star,
    Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { show as showMember } from '@/routes/families/members';
import type { FamilyRef, TimelineItem } from '@/types';

const ICONS: Record<string, LucideIcon> = {
    birth: Baby,
    death: Flower2,
    marriage: Heart,
    education: BookOpen,
    career: Briefcase,
    migration: Plane,
    reunion: Users,
    residence: Home,
    achievement: Star,
};

/**
 * Timeline items in date order, grouped by decade. Items without a date come last.
 */
export function TimelineList({
    family,
    items,
    onEdit,
}: {
    family: FamilyRef;
    items: TimelineItem[];
    onEdit?: (item: TimelineItem) => void;
}) {
    const groups = new Map<string, TimelineItem[]>();

    for (const item of items) {
        const heading =
            item.year === null
                ? 'Date not known'
                : `${Math.floor(item.year / 10) * 10}s`;
        groups.set(heading, [...(groups.get(heading) ?? []), item]);
    }

    return (
        <div className="grid gap-8">
            {[...groups.entries()].map(([heading, group]) => (
                <section key={heading} aria-label={heading}>
                    <h3 className="sticky top-0 z-10 mb-3 bg-background/90 py-1 text-sm font-semibold text-muted-foreground backdrop-blur">
                        {heading}
                    </h3>
                    <ol className="relative grid gap-4 border-l pl-6">
                        {group.map((item) => {
                            const Icon = ICONS[item.category] ?? Milestone;

                            return (
                                <li key={item.id} className="relative">
                                    <span
                                        className="absolute top-0.5 -left-[2.3rem] flex size-7 items-center justify-center rounded-full border bg-background text-muted-foreground"
                                        aria-hidden="true"
                                    >
                                        <Icon className="size-3.5" />
                                    </span>
                                    <div className="flex items-start justify-between gap-2">
                                        <div className="min-w-0">
                                            <p className="text-xs text-muted-foreground">
                                                {item.date.label ??
                                                    'Date not known'}
                                            </p>
                                            <p className="text-sm font-medium">
                                                {item.title}
                                            </p>
                                            {item.place && (
                                                <p className="flex items-center gap-1 text-xs text-muted-foreground">
                                                    <MapPin
                                                        className="size-3"
                                                        aria-hidden="true"
                                                    />
                                                    {item.place}
                                                </p>
                                            )}
                                            {item.description && (
                                                <p className="mt-1 text-sm whitespace-pre-line text-muted-foreground">
                                                    {item.description}
                                                </p>
                                            )}
                                            {item.kind === 'entry' &&
                                                item.people.length > 0 && (
                                                    <p className="mt-1 flex flex-wrap gap-x-2 text-xs">
                                                        {item.people.map(
                                                            (person) => (
                                                                <Link
                                                                    key={
                                                                        person.id
                                                                    }
                                                                    href={showMember(
                                                                        {
                                                                            family: family.slug,
                                                                            member: person.id,
                                                                        },
                                                                    )}
                                                                    className="underline underline-offset-2"
                                                                >
                                                                    {
                                                                        person.name
                                                                    }
                                                                </Link>
                                                            ),
                                                        )}
                                                    </p>
                                                )}
                                            {item.attachments.length > 0 && (
                                                <ul className="mt-2 flex flex-wrap gap-2">
                                                    {item.attachments.map(
                                                        (attachment) => (
                                                            <li
                                                                key={
                                                                    attachment.id
                                                                }
                                                            >
                                                                <Link
                                                                    href={
                                                                        attachment.url
                                                                    }
                                                                    className="block size-16 overflow-hidden rounded-md border bg-muted outline-none focus-visible:ring-[3px] focus-visible:ring-ring/60"
                                                                >
                                                                    <img
                                                                        src={
                                                                            attachment.thumb_url
                                                                        }
                                                                        alt={
                                                                            attachment.title ??
                                                                            (attachment.type ===
                                                                            'photo'
                                                                                ? 'Photo'
                                                                                : 'Document')
                                                                        }
                                                                        loading="lazy"
                                                                        className="size-full object-cover object-top"
                                                                    />
                                                                </Link>
                                                            </li>
                                                        ),
                                                    )}
                                                </ul>
                                            )}
                                        </div>
                                        {item.editable && onEdit && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                className="size-8 shrink-0 text-muted-foreground"
                                                aria-label={`Edit “${item.title}”`}
                                                onClick={() => onEdit(item)}
                                            >
                                                <Pencil className="size-4" />
                                            </Button>
                                        )}
                                    </div>
                                </li>
                            );
                        })}
                    </ol>
                </section>
            ))}
        </div>
    );
}
