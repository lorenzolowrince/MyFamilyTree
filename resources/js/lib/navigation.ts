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
import { edit as editProfile } from '@/routes/profile';
import type { NavItem } from '@/types';

/**
 * The ten main sections (docs/architecture/04-application-structure.md).
 * Sections without an `href` are not built yet. They render disabled, labelled with
 * the roadmap phase that delivers them, so no link leads to a page that doesn't work.
 * When a module ships, give it an `href` and drop `availableInPhase`.
 */
export const mainNavItems: NavItem[] = [
    { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
    { title: 'My Family Tree', icon: Network, availableInPhase: 4 },
    { title: 'Family Members', icon: Users, availableInPhase: 4 },
    { title: 'Family Timeline', icon: History, availableInPhase: 5 },
    { title: 'Photo Gallery', icon: Images, availableInPhase: 7 },
    { title: 'Documents', icon: FileText, availableInPhase: 7 },
    { title: 'Family Events', icon: CalendarDays, availableInPhase: 5 },
    { title: 'Invitations', icon: MailPlus, availableInPhase: 6 },
    { title: 'Reports', icon: Printer, availableInPhase: 8 },
    { title: 'Settings', href: editProfile(), icon: Settings },
];
