import { Link, usePage } from '@inertiajs/react';
import {
    Activity,
    ArrowLeftRight,
    ChevronsUpDown,
    ClipboardCheck,
    ShieldCheck,
    Waypoints,
} from 'lucide-react';
import { index as gedcomIndex } from '@/routes/families/gedcom';
import { finder as relationshipFinder } from '@/routes/families/relationships';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { mainNavItems } from '@/lib/navigation';
import { dashboard } from '@/routes';
import { overview as adminOverview } from '@/routes/admin';
import { index as familiesIndex } from '@/routes/families';
import { index as activityIndex } from '@/routes/families/activity';
import { index as changesIndex } from '@/routes/families/changes';
import type { NavItem } from '@/types';

export function AppSidebar() {
    const { auth, currentFamily } = usePage().props;
    const permissions = currentFamily?.permissions ?? [];

    // Shown to everyone in a family: reviewers decide here, others follow their proposals.
    const familyExtras: NavItem[] = currentFamily
        ? [
              {
                  title: 'Relationship Finder',
                  href: relationshipFinder(currentFamily.slug),
                  icon: Waypoints,
              },
              {
                  title: 'Approvals',
                  href: changesIndex(currentFamily.slug),
                  icon: ClipboardCheck,
                  badge: currentFamily.pendingChanges,
              },
              ...(permissions.includes('gedcom.export')
                  ? [
                        {
                            title: 'Import & Export',
                            href: gedcomIndex(currentFamily.slug),
                            icon: ArrowLeftRight,
                        },
                    ]
                  : []),
              ...(permissions.includes('audit.view_records')
                  ? [
                        {
                            title: 'Activity',
                            href: activityIndex(currentFamily.slug),
                            icon: Activity,
                        },
                    ]
                  : []),
          ]
        : [];

    const nav = [
        ...mainNavItems(currentFamily).slice(0, -1),
        ...familyExtras,
        ...mainNavItems(currentFamily).slice(-1),
    ];

    // A hint only: the server decides who may open the admin area.
    const items: NavItem[] = auth.user?.is_super_admin
        ? [
              ...nav,
              {
                  title: 'Administration',
                  href: adminOverview(),
                  icon: ShieldCheck,
              },
          ]
        : nav;

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
                {currentFamily && (
                    <SidebarGroup className="px-2 py-0">
                        <SidebarMenu>
                            <SidebarMenuItem>
                                <SidebarMenuButton
                                    asChild
                                    size="lg"
                                    tooltip={{
                                        children: `${currentFamily.name}: switch family`,
                                    }}
                                >
                                    <Link href={familiesIndex()}>
                                        <div className="grid flex-1 text-left leading-tight">
                                            <span className="truncate text-sm font-medium">
                                                {currentFamily.name}
                                            </span>
                                            <span className="truncate text-xs text-muted-foreground">
                                                {currentFamily.role}
                                            </span>
                                        </div>
                                        <ChevronsUpDown
                                            className="ml-auto size-4"
                                            aria-hidden="true"
                                        />
                                        <span className="sr-only">
                                            Switch family
                                        </span>
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        </SidebarMenu>
                    </SidebarGroup>
                )}
                <NavMain items={items} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
