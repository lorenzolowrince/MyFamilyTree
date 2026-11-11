import { Link } from '@inertiajs/react';
import { Network } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { tree } from '@/routes/families';
import type { FamilySummary } from '@/types';

/** One family tree with its headline numbers. The whole card opens the tree. */
export function FamilyCard({ family }: { family: FamilySummary }) {
    return (
        <Link
            href={tree(family.slug)}
            className="group flex h-full flex-col overflow-hidden rounded-xl border bg-card outline-none hover:border-primary/50 focus-visible:ring-[3px] focus-visible:ring-ring/60"
        >
            <div className="flex h-28 items-center justify-center bg-muted">
                {family.cover_url ? (
                    <img
                        src={family.cover_url}
                        alt=""
                        className="size-full object-cover"
                        loading="lazy"
                    />
                ) : (
                    <Network
                        className="size-8 text-muted-foreground/60"
                        aria-hidden="true"
                    />
                )}
            </div>
            <div className="flex flex-1 flex-col gap-2 p-4">
                <div className="flex items-start justify-between gap-2">
                    <h2 className="text-base leading-tight font-medium group-hover:underline">
                        {family.name}
                    </h2>
                    {family.role && (
                        <Badge variant="secondary">{family.role}</Badge>
                    )}
                </div>
                {family.description && (
                    <p className="line-clamp-2 text-sm text-muted-foreground">
                        {family.description}
                    </p>
                )}
                <p className="mt-auto text-sm text-muted-foreground">
                    {family.members.toLocaleString()}{' '}
                    {family.members === 1 ? 'person' : 'people'}
                    {family.generations > 0 &&
                        ` · ${family.generations} ${family.generations === 1 ? 'generation' : 'generations'}`}
                </p>
            </div>
        </Link>
    );
}
