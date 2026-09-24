import { Link, usePage } from '@inertiajs/react';
import {
    Building2,
    ChartColumn,
    FolderKanban,
    FormInput,
    KeyRound,
    Layers,
    LayoutDashboard,
    Library,
    Map,
    Upload,
    Users,
    UsersRound,
    type LucideIcon,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import type { NavItem } from '@/types';

const ICONS: Record<string, LucideIcon> = {
    dashboard: LayoutDashboard,
    projects: FolderKanban,
    imports: Upload,
    layers: Layers,
    maps: Map,
    catalog: Library,
    dashboards: ChartColumn,
    forms: FormInput,
    groups: UsersRound,
    tokens: KeyRound,
    organization: Building2,
    users: Users,
};

function toNavItem(entry: { label: string; href: string; icon: string; match?: string }): NavItem {
    return {
        title: entry.label,
        href: entry.href,
        icon: ICONS[entry.icon] || Layers,
        isActive: entry.match
            ? typeof window !== 'undefined' && window.location.pathname.startsWith(entry.match)
            : undefined,
    };
}

export function AppSidebar() {
    const { nav = [], routes, brand, organization } = usePage().props;
    const [organizeOpen, setOrganizeOpen] = useState(() => {
        try {
            return localStorage.getItem('console.organizeOpen') === '1';
        } catch {
            return false;
        }
    });

    const workspace = useMemo(
        () => nav.filter((item) => item.group !== 'organize').map(toNavItem),
        [nav],
    );
    const organize = useMemo(
        () => nav.filter((item) => item.group === 'organize').map(toNavItem),
        [nav],
    );

    const homeHref = routes.dashboard || '/dashboard';
    const brandName = brand?.name || 'Lumina GIS';

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={homeHref}>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={workspace} label="Workspace" />
                {organize.length ? (
                    <Collapsible
                        open={organizeOpen}
                        onOpenChange={(open) => {
                            setOrganizeOpen(open);
                            try {
                                localStorage.setItem('console.organizeOpen', open ? '1' : '0');
                            } catch {
                                // Preference is best-effort.
                            }
                        }}
                        className="group/collapsible"
                    >
                        <SidebarGroup className="px-2 py-0">
                            <SidebarGroupLabel asChild>
                                <CollapsibleTrigger className="flex w-full items-center">
                                    Organize
                                    <span className="ml-auto text-xs tabular-nums text-muted-foreground">
                                        {organize.length}
                                    </span>
                                </CollapsibleTrigger>
                            </SidebarGroupLabel>
                            <CollapsibleContent>
                                <SidebarMenu>
                                    {organize.map((item) => (
                                        <SidebarMenuItem key={`${item.title}-${String(item.href)}`}>
                                            <SidebarMenuButton asChild tooltip={{ children: item.title }}>
                                                <Link href={item.href}>
                                                    {item.icon ? <item.icon /> : null}
                                                    <span>{item.title}</span>
                                                </Link>
                                            </SidebarMenuButton>
                                        </SidebarMenuItem>
                                    ))}
                                </SidebarMenu>
                            </CollapsibleContent>
                        </SidebarGroup>
                    </Collapsible>
                ) : null}
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
