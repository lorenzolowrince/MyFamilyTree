import {
    CalendarDays,
    FileText,
    History,
    Images,
    LayoutGrid,
    MailPlus,
    Network,
    Printer,
    Settings,
    Users,
} from 'lucide-react';
import { dashboard } from '@/routes';
import { index as familiesIndex, tree } from '@/routes/families';
import { index as membersIndex } from '@/routes/families/members';
import { edit as editProfile } from '@/routes/profile';
import type { CurrentFamily, NavItem } from '@/types';

/**
 * The ten main sections (docs/architecture/04-application-structure.md).
 * Family sections lead to the family last opened, or to the family list when the person has
 * no family yet. Sections without an `href` are not built yet: they render disabled, labelled
 * with the roadmap phase that delivers them, so no link leads to a page that doesn't work.
 */
export function mainNavItems(family: CurrentFamily | null): NavItem[] {
    return [
        { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
        {
            title: 'My Family Tree',
            href: family ? tree(family.slug) : familiesIndex(),
            icon: Network,
        },
        {
            title: 'Family Members',
            href: family ? membersIndex(family.slug) : familiesIndex(),
            icon: Users,
        },
        { title: 'Family Timeline', icon: History, availableInPhase: 5 },
        { title: 'Photo Gallery', icon: Images, availableInPhase: 7 },
        { title: 'Documents', icon: FileText, availableInPhase: 7 },
        { title: 'Family Events', icon: CalendarDays, availableInPhase: 5 },
        { title: 'Invitations', icon: MailPlus, availableInPhase: 6 },
        { title: 'Reports', icon: Printer, availableInPhase: 8 },
        { title: 'Settings', href: editProfile(), icon: Settings },
    ];
}
