import { EyeOff, HelpCircle } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { MemberSummary } from '@/types';

function initials(name: string): string {
    const words = name.trim().split(/\s+/u);
    const first = words[0]?.[0] ?? '';
    const last = words.length > 1 ? (words[words.length - 1]?.[0] ?? '') : '';

    return (first + last).toUpperCase();
}

/** Initials in a circle. Colour is decoration only; gender is never conveyed by colour alone. */
export function MemberAvatar({
    member,
    className,
}: {
    member: MemberSummary;
    className?: string;
}) {
    const tone = member.hidden
        ? 'bg-muted text-muted-foreground'
        : member.gender === 'male'
          ? 'bg-sky-100 text-sky-900 dark:bg-sky-950 dark:text-sky-200'
          : member.gender === 'female'
            ? 'bg-rose-100 text-rose-900 dark:bg-rose-950 dark:text-rose-200'
            : 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200';

    return (
        <span
            aria-hidden="true"
            className={cn(
                'flex size-9 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                tone,
                !member.living && !member.hidden && 'grayscale-[0.6]',
                className,
            )}
        >
            {member.hidden ? (
                <EyeOff className="size-4" />
            ) : member.is_placeholder ? (
                <HelpCircle className="size-4" />
            ) : (
                initials(member.name)
            )}
        </span>
    );
}
