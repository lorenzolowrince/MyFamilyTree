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
import { index as documentsIndex } from '@/routes/families/documents';
import { index as eventsIndex } from '@/routes/families/events';
import { index as membersIndex } from '@/routes/families/members';
import { index as peopleIndex } from '@/routes/families/people';
import { index as photosIndex } from '@/routes/families/photos';
import { index as reportsIndex } from '@/routes/families/reports';
import { index as timelineIndex } from '@/routes/families/timeline';
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
        {
            title: 'Family Timeline',
            href: family ? timelineIndex(family.slug) : familiesIndex(),
            icon: History,
        },
        {
            title: 'Photo Gallery',
            href: family ? photosIndex(family.slug) : familiesIndex(),
            icon: Images,
        },
        {
            title: 'Documents',
            href: family ? documentsIndex(family.slug) : familiesIndex(),
            icon: FileText,
        },
        {
            title: 'Family Events',
            href: family ? eventsIndex(family.slug) : familiesIndex(),
            icon: CalendarDays,
        },
        {
            title: 'Invitations',
            href: family ? peopleIndex(family.slug) : familiesIndex(),
            icon: MailPlus,
        },
        {
            title: 'Reports',
            href: family ? reportsIndex(family.slug) : familiesIndex(),
            icon: Printer,
        },
        { title: 'Settings', href: editProfile(), icon: Settings },
    ];
}
