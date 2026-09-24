import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeaderBar } from '@/components/app-sidebar-header-bar';
import { Flash } from '@/components/gis';
import type { SidebarLayoutProps } from '@/types';

export default function AppSidebarLayout({ children, breadcrumbs = [] }: SidebarLayoutProps) {
    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar" className="min-w-0 overflow-x-clip">
                <AppSidebarHeaderBar breadcrumbs={breadcrumbs} />
                <div className="flex flex-1 flex-col gap-4 p-4">
                    <Flash />
                    {children}
                </div>
            </AppContent>
        </AppShell>
    );
}
