import { Link, usePage } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { mainNavItems } from '@/lib/navigation';
import { dashboard } from '@/routes';
import { overview as adminOverview } from '@/routes/admin';
import type { NavItem } from '@/types';

export function AppSidebar() {
    const { auth } = usePage().props;

    // A hint only: the server decides who may open the admin area.
    const items: NavItem[] = auth.user?.is_super_admin
        ? [
              ...mainNavItems,
              {
                  title: 'Administration',
                  href: adminOverview(),
                  icon: ShieldCheck,
              },
          ]
        : mainNavItems;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={items} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
