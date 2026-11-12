import { Link, usePage } from '@inertiajs/react';
import { Cake, CalendarDays, Heart, History, UserPlus } from 'lucide-react';
import type { ReactNode } from 'react';
import { MemberAvatar } from '@/components/members/member-avatar';
import { formatDate, formatDateTime } from '@/lib/dates';
import { tree } from '@/routes/families';
import { index as activityIndex } from '@/routes/families/activity';
import { index as eventsIndex } from '@/routes/families/events';
import {
    index as membersIndex,
    show as showMember,
} from '@/routes/families/members';
import type { FamilyOverview as Overview } from '@/types';

function inDays(days: number): string {
    return days === 0 ? 'Today' : days === 1 ? 'Tomorrow' : `In ${days} days`;
}

/** "25 Nov" from a plain date (no time zone shifting). */
function dayMonth(date: string): string {
    return new Intl.DateTimeFormat('en-MY', {
        day: 'numeric',
        month: 'short',
        timeZone: 'UTC',
    }).format(new Date(`${date}T00:00:00Z`));
}

function Widget({
    title,
    icon,
    empty,
    count,
    children,
}: {
    title: string;
    icon: ReactNode;
    empty: string;
    count: number;
    children: ReactNode;
}) {
    return (
        <section className="rounded-xl border p-4">
            <h3 className="mb-3 flex items-center gap-2 text-sm font-medium">
                {icon}
                {title}
            </h3>
            {count === 0 ? (
                <p className="text-sm text-muted-foreground">{empty}</p>
            ) : (
                <ul className="grid gap-2.5">{children}</ul>
            )}
        </section>
    );
}

/** One family at a glance: numbers, birthdays, anniversaries, events and recent additions. */
export function FamilyOverview({ overview }: { overview: Overview }) {
    const { auth } = usePage().props;
    const { family, stats } = overview;

    const tiles = [
        { label: 'People', value: stats.members },
        { label: 'Generations', value: stats.generations },
        { label: 'Living', value: stats.living },
        { label: 'Deceased', value: stats.deceased },
    ];

    return (
        <section aria-labelledby="overview-heading" className="grid gap-4">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="overview-heading" className="text-lg font-medium">
                    {family.name}
                </h2>
                <p className="flex gap-4 text-sm">
                    <Link
                        href={tree(family.slug)}
                        className="underline underline-offset-4"
                    >
                        Open tree
                    </Link>
                    <Link
                        href={membersIndex(family.slug)}
                        className="underline underline-offset-4"
                    >
                        All members
                    </Link>
                </p>
            </div>

            <dl className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                {tiles.map((tile) => (
                    <div key={tile.label} className="rounded-xl border p-4">
                        <dt className="text-sm text-muted-foreground">
                            {tile.label}
                        </dt>
                        <dd className="mt-1 text-2xl font-semibold tabular-nums">
                            {tile.value.toLocaleString()}
                        </dd>
                    </div>
                ))}
            </dl>

            <div className="grid gap-4 md:grid-cols-2">
                <Widget
                    title="Upcoming birthdays"
                    icon={<Cake className="size-4" aria-hidden="true" />}
                    empty="No birthdays in the next two months that you can see."
                    count={overview.birthdays.length}
                >
                    {overview.birthdays.map((birthday) => (
                        <li
                            key={birthday.person.id}
                            className="flex items-center gap-2.5"
                        >
                            <MemberAvatar
                                member={birthday.person}
                                className="size-8"
                            />
                            <span className="min-w-0 flex-1">
                                <Link
                                    href={showMember({
                                        family: family.slug,
                                        member: birthday.person.id,
                                    })}
                                    className="block truncate text-sm font-medium hover:underline"
                                >
                                    {birthday.person.name}
                                </Link>
                                <span className="block text-xs text-muted-foreground">
                                    {dayMonth(birthday.date)} · turns{' '}
                                    {birthday.turning}
                                </span>
                            </span>
                            <span className="text-xs text-muted-foreground">
                                {inDays(birthday.days)}
                            </span>
                        </li>
                    ))}
                </Widget>

                <Widget
                    title="Upcoming anniversaries"
                    icon={<Heart className="size-4" aria-hidden="true" />}
                    empty="No anniversaries in the next two months that you can see."
                    count={overview.anniversaries.length}
                >
                    {overview.anniversaries.map((anniversary) => (
                        <li
                            key={anniversary.people
                                .map((person) => person.id)
                                .join('-')}
                            className="flex items-center gap-2.5"
                        >
                            <span className="min-w-0 flex-1">
                                <span className="block truncate text-sm font-medium">
                                    {anniversary.people
                                        .map((person) => person.name)
                                        .join(' & ')}
                                </span>
                                <span className="block text-xs text-muted-foreground">
                                    {dayMonth(anniversary.date)} ·{' '}
                                    {anniversary.years}{' '}
                                    {anniversary.years === 1 ? 'year' : 'years'}
                                </span>
                            </span>
                            <span className="text-xs text-muted-foreground">
                                {inDays(anniversary.days)}
                            </span>
                        </li>
                    ))}
                </Widget>

                <Widget
                    title="Family events"
                    icon={
                        <CalendarDays className="size-4" aria-hidden="true" />
                    }
                    empty="No events planned."
                    count={overview.events.length}
                >
                    {overview.events.map((event) => (
                        <li key={event.id}>
                            <Link
                                href={eventsIndex(family.slug)}
                                className="block text-sm font-medium hover:underline"
                            >
                                {event.title}
                            </Link>
                            <span className="block text-xs text-muted-foreground">
                                {event.all_day
                                    ? formatDate(
                                          event.starts_at,
                                          auth.user.timezone,
                                      )
                                    : formatDateTime(
                                          event.starts_at,
                                          auth.user.timezone,
                                      )}
                                {event.location && ` · ${event.location}`}
                            </span>
                        </li>
                    ))}
                </Widget>

                <Widget
                    title="Recently added"
                    icon={<UserPlus className="size-4" aria-hidden="true" />}
                    empty="Nobody has been added yet."
                    count={overview.recent.length}
                >
                    {overview.recent.map((person) => (
                        <li
                            key={person.id}
                            className="flex items-center gap-2.5"
                        >
                            <MemberAvatar member={person} className="size-8" />
                            <Link
                                href={showMember({
                                    family: family.slug,
                                    member: person.id,
                                })}
                                className="min-w-0 flex-1 truncate text-sm font-medium hover:underline"
                            >
                                {person.name}
                            </Link>
                            <span className="text-xs text-muted-foreground">
                                {person.lifespan}
                            </span>
                        </li>
                    ))}
                </Widget>

                {overview.activity && (
                    <div className="md:col-span-2">
                        <Widget
                            title="Recent activity"
                            icon={
                                <History
                                    className="size-4"
                                    aria-hidden="true"
                                />
                            }
                            empty="Nothing has changed yet."
                            count={overview.activity.length}
                        >
                            {overview.activity.map((item) => (
                                <li
                                    key={item.id}
                                    className="flex flex-wrap items-baseline justify-between gap-x-4"
                                >
                                    <span className="text-sm">
                                        <span className="font-medium">
                                            {item.actor ?? 'The system'}
                                        </span>{' '}
                                        {item.summary}
                                    </span>
                                    <time
                                        dateTime={item.created_at}
                                        className="text-xs whitespace-nowrap text-muted-foreground"
                                    >
                                        {formatDateTime(
                                            item.created_at,
                                            auth.user.timezone,
                                        )}
                                    </time>
                                </li>
                            ))}
                            <li>
                                <Link
                                    href={activityIndex(family.slug)}
                                    className="text-sm underline underline-offset-4"
                                >
                                    All activity
                                </Link>
                            </li>
                        </Widget>
                    </div>
                )}
            </div>
        </section>
    );
}
