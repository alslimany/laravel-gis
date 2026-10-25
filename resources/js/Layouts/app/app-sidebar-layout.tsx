import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeaderBar } from '@/components/app-sidebar-header-bar';
import { Flash } from '@/components/gis';
import { moduleAccent, useConsoleModules } from '@/lib/console-modules';
import { cn } from '@/lib/utils';
import type { SidebarLayoutProps } from '@/types';

export default function AppSidebarLayout({ children, breadcrumbs = [] }: SidebarLayoutProps) {
    const { active } = useConsoleModules();

    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar" className="min-w-0 overflow-x-clip">
                <div aria-hidden="true" className={cn('h-0.5 shrink-0 md:rounded-t-xl', moduleAccent(active?.accent).bar)} />
                <AppSidebarHeaderBar breadcrumbs={breadcrumbs} />
                <div className="flex flex-1 flex-col gap-4 p-4">
                    <Flash />
                    {children}
                </div>
            </AppContent>
        </AppShell>
    );
}
