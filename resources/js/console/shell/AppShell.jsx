import { useMemo } from 'react';
import Sidebar from './Sidebar';
import Topbar from './Topbar';

export default function AppShell({
    children,
    user,
    organization,
    nav,
    routes,
    brand,
    csrf,
    currentPath,
    sidebarOpen,
    setSidebarOpen,
    collapsed,
    setCollapsed,
}) {
    const primary = brand?.primaryColor || '#0f766e';

    const style = useMemo(
        () => ({
            '--org-primary': primary,
        }),
        [primary]
    );

    return (
        <div className="console-app flex min-h-full bg-paper text-ink" style={style}>
            {sidebarOpen ? (
                <button
                    type="button"
                    className="fixed inset-0 z-30 bg-ink/40 lg:hidden"
                    aria-label="Close navigation"
                    onClick={() => setSidebarOpen(false)}
                />
            ) : null}

            <Sidebar
                nav={nav}
                routes={routes}
                brand={brand}
                organization={organization}
                currentPath={currentPath}
                open={sidebarOpen}
                collapsed={collapsed}
                onCollapseToggle={() => setCollapsed((v) => !v)}
                onNavigate={() => setSidebarOpen(false)}
            />

            <div
                className={`flex min-h-full min-w-0 flex-1 flex-col transition-[margin] duration-200 ${
                    collapsed ? 'lg:ml-[72px]' : 'lg:ml-64'
                }`}
            >
                <Topbar
                    user={user}
                    organization={organization}
                    routes={routes}
                    csrf={csrf}
                    currentPath={currentPath}
                    onMenuClick={() => setSidebarOpen(true)}
                    collapsed={collapsed}
                />
                <main className="flex-1 px-4 py-5 sm:px-6 lg:px-8">{children}</main>
            </div>
        </div>
    );
}
