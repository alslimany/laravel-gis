import { useEffect, useState } from 'react';
import AppShell from './shell/AppShell';
import DashboardHome from './pages/DashboardHome';
import BladeHost from './pages/BladeHost';
import GuestShell from './shell/GuestShell';

export default function App(props) {
    const {
        page = 'blade',
        user,
        organization,
        nav,
        routes,
        flash,
        stats,
        projects,
        csrf,
        brand,
    } = props;

    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [collapsed, setCollapsed] = useState(() => {
        try {
            return localStorage.getItem('console.sidebarCollapsed') === '1';
        } catch {
            return false;
        }
    });

    useEffect(() => {
        try {
            localStorage.setItem('console.sidebarCollapsed', collapsed ? '1' : '0');
        } catch {
            /* ignore */
        }
    }, [collapsed]);

    if (!user) {
        return (
            <GuestShell brand={brand} routes={routes}>
                <BladeHost />
            </GuestShell>
        );
    }

    const content =
        page === 'dashboard' ? (
            <DashboardHome
                user={user}
                organization={organization}
                stats={stats}
                projects={projects}
                routes={routes}
                flash={flash}
            />
        ) : (
            <BladeHost />
        );

    return (
        <AppShell
            user={user}
            organization={organization}
            nav={nav}
            routes={routes}
            brand={brand}
            csrf={csrf}
            currentPath={typeof window !== 'undefined' ? window.location.pathname : '/'}
            sidebarOpen={sidebarOpen}
            setSidebarOpen={setSidebarOpen}
            collapsed={collapsed}
            setCollapsed={setCollapsed}
        >
            {content}
        </AppShell>
    );
}
