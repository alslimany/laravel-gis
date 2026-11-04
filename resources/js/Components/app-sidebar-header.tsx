import { Link } from '@inertiajs/react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { ModuleMark } from '@/components/module-switcher';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useConsoleModules } from '@/lib/console-modules';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const { active } = useConsoleModules();

    return (
        <header className="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/50 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4">
            <div className="flex min-w-0 items-center gap-2">
                <SidebarTrigger className="-ms-1" />
                {active ? (
                    <Link href={active.href} className="flex shrink-0 items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground">
                        <ModuleMark module={active} className="size-5 rounded-sm [&_svg]:size-3" />
                        <span className="font-medium">{active.label}</span>
                        {breadcrumbs.length ? <span aria-hidden="true">/</span> : null}
                    </Link>
                ) : null}
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
        </header>
    );
}
