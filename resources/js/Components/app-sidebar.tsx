import { usePage } from '@inertiajs/react';
import { House } from 'lucide-react';
import { ModuleSwitcher } from '@/components/module-switcher';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarSeparator,
} from '@/components/ui/sidebar';
import { moduleIcon, useConsoleModules } from '@/lib/console-modules';
import type { ConsoleModuleItem, NavItem } from '@/types';

function toNavItem(entry: ConsoleModuleItem): NavItem {
    return {
        title: entry.label,
        href: entry.href,
        icon: moduleIcon(entry.icon),
        isActive: entry.match
            ? typeof window !== 'undefined' && window.location.pathname.startsWith(entry.match)
            : undefined,
    };
}

export function AppSidebar() {
    const { routes, brand } = usePage().props;
    const { active } = useConsoleModules();

    const homeHref = routes.dashboard || '/dashboard';
    const brandName = brand?.name || 'Lumina GIS';

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <ModuleSwitcher />
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={[{ title: 'Home', href: homeHref, icon: House }]} label="" />
                <SidebarSeparator className="mx-2" />
                {active?.sections.map((section) => (
                    <NavMain key={`${active.key}-${section.label}`} items={section.items.map(toNavItem)} label={section.label} />
                ))}
            </SidebarContent>

            <SidebarFooter>
                <div className="px-2 text-[10px] text-muted-foreground truncate" title={brandName}>
                    {brandName}
                </div>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
