import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import { overview } from '@/routes/admin';
import { index as usersIndex } from '@/routes/admin/users';
import type { NavItem } from '@/types';

const adminNavItems: (NavItem & Required<Pick<NavItem, 'href'>>)[] = [
    { title: 'Overview', href: overview() },
    { title: 'Users', href: usersIndex() },
];

/** Shell for the platform administration area (Super Administrators only). */
export default function AdminLayout({ children }: PropsWithChildren) {
    const { isCurrentUrl } = useCurrentUrl();

    return (
        <div className="px-4 py-6">
            <Heading
                title="Administration"
                description="Platform accounts and health. Families' private records are not visible here."
            />

            <nav
                className="mb-6 flex gap-1 border-b pb-2"
                aria-label="Administration"
            >
                {adminNavItems.map((item) => (
                    <Button
                        key={toUrl(item.href)}
                        size="sm"
                        variant="ghost"
                        asChild
                        className={cn({
                            'bg-muted': isCurrentUrl(item.href),
                        })}
                    >
                        <Link href={item.href}>{item.title}</Link>
                    </Button>
                ))}
            </nav>

            {children}
        </div>
    );
}
