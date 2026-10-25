import { Link, usePage } from '@inertiajs/react';
import { Check, ChevronsUpDown, Puzzle } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useIsMobile } from '@/hooks/use-mobile';
import { moduleAccent, moduleIcon, useConsoleModules } from '@/lib/console-modules';
import { cn } from '@/lib/utils';
import type { ConsoleModule } from '@/types';

export function ModuleMark({ module, className = '' }: { module: Pick<ConsoleModule, 'icon' | 'accent'>; className?: string }) {
    const Icon = moduleIcon(module.icon);

    return (
        <span
            className={cn(
                'flex aspect-square size-8 shrink-0 items-center justify-center rounded-md',
                moduleAccent(module.accent).mark,
                className,
            )}
        >
            <Icon className="size-4" />
        </span>
    );
}

export function ModuleSwitcher() {
    const { modules, active } = useConsoleModules();
    const { routes, abilities } = usePage().props as {
        routes?: { extensions?: string | null };
        abilities?: { admin?: boolean };
    };
    const { state } = useSidebar();
    const isMobile = useIsMobile();

    if (!active) {
        return null;
    }

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton
                            size="lg"
                            tooltip={{ children: `Switch module (${active.label})` }}
                            className="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
                        >
                            <ModuleMark module={active} />
                            <span className="grid flex-1 text-left text-sm leading-tight">
                                <span className="truncate font-semibold">{active.label}</span>
                                {active.description ? (
                                    <span className="truncate text-xs text-muted-foreground">{active.description}</span>
                                ) : null}
                            </span>
                            <ChevronsUpDown className="ml-auto size-4" />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        className="w-(--radix-dropdown-menu-trigger-width) min-w-60 rounded-lg"
                        align="start"
                        side={isMobile ? 'bottom' : state === 'collapsed' ? 'right' : 'bottom'}
                        sideOffset={4}
                    >
                        <DropdownMenuLabel className="text-xs text-muted-foreground">Switch module</DropdownMenuLabel>
                        {modules.map((module) => (
                            <DropdownMenuItem key={module.key} asChild className="gap-2 p-2">
                                <Link href={module.href}>
                                    <ModuleMark module={module} className="size-6 rounded-sm [&_svg]:size-3.5" />
                                    <span className="flex-1 truncate">{module.label}</span>
                                    {module.kind === 'extension' ? (
                                        <span className="text-[10px] uppercase tracking-wide text-muted-foreground">Extension</span>
                                    ) : null}
                                    {module.key === active.key ? <Check className="size-4" /> : null}
                                </Link>
                            </DropdownMenuItem>
                        ))}
                        {abilities?.admin && routes?.extensions ? (
                            <>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem asChild className="gap-2 p-2">
                                    <Link href={routes.extensions}>
                                        <Puzzle className="size-4" />
                                        Manage extensions
                                    </Link>
                                </DropdownMenuItem>
                            </>
                        ) : null}
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
