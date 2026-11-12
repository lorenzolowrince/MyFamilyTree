import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import type { Paginated } from '@/types';

/** Previous/next links for a paginated list that runs newest first. */
export function Pager<T>({ page }: { page: Paginated<T> }) {
    if (page.last_page <= 1) {
        return null;
    }

    return (
        <nav
            className="flex items-center justify-between"
            aria-label="Pagination"
        >
            <p className="text-sm text-muted-foreground">
                Page {page.current_page} of {page.last_page}
            </p>
            <div className="flex gap-2">
                {page.prev_page_url && (
                    <Button variant="outline" size="sm" asChild>
                        <Link href={page.prev_page_url}>Newer</Link>
                    </Button>
                )}
                {page.next_page_url && (
                    <Button variant="outline" size="sm" asChild>
                        <Link href={page.next_page_url}>Older</Link>
                    </Button>
                )}
            </div>
        </nav>
    );
}
