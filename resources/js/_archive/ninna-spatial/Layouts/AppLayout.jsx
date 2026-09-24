import { useEffect, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Flash } from '../Components/ui';
import { Avatar, IconButton, Text } from '@ninna-ui/primitives';
import { Container, HStack, VStack } from '@ninna-ui/layout';
import { useTheme } from '../lib/theme';
import {
    Building2,
    ChartColumn,
    ChevronDown,
    ChevronRight,
    FolderKanban,
    FormInput,
    KeyRound,
    Layers,
    LayoutDashboard,
    Library,
    LogOut,
    Map,
    Menu,
    Moon,
    Sun,
    Upload,
    Users,
    UsersRound,
    X,
} from 'lucide-react';

const ICONS = {
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

const linkButton =
    'inline-flex h-9 w-full items-center gap-2 rounded-md px-2.5 text-left text-[13px] font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40';

function itemPath(href) {
    try {
        return new URL(href, window.location.origin).pathname.replace(/\/$/, '') || '/';
    } catch {
        return href;
    }
}

function isCurrent(item, currentPath) {
    const hrefPath = itemPath(item.href);
    const here = (currentPath || '/').replace(/\/$/, '') || '/';

    return here === hrefPath || (hrefPath !== '/' && here.startsWith(`${hrefPath}/`)) || (item.match && here.startsWith(item.match));
}

function NavItem({ item, currentPath, onNavigate }) {
    const Icon = ICONS[item.icon] || Layers;
    const active = isCurrent(item, currentPath);

    return (
        <Link
            href={item.href}
            onClick={onNavigate}
            aria-current={active ? 'page' : undefined}
            className={`${linkButton} ${
                active
                    ? 'bg-primary text-primary-content'
                    : 'text-base-content hover:bg-base-200'
            }`}
        >
            <Icon className="h-4 w-4 shrink-0" strokeWidth={1.75} aria-hidden />
            <span className="truncate">{item.label}</span>
        </Link>
    );
}

export default function AppLayout({ title, children }) {
    const { brand, nav = [], organization, routes, user } = usePage().props;
    const appName = brand?.name || organization?.name || 'GIS Console';
    const currentPath = typeof window !== 'undefined' ? window.location.pathname : '/';
    const workspace = nav.filter((item) => item.group !== 'organize');
    const organize = nav.filter((item) => item.group === 'organize');
    const [mobileOpen, setMobileOpen] = useState(false);
    const [organizeOpen, setOrganizeOpen] = useState(() => {
        try {
            return localStorage.getItem('console.organizeOpen') === '1';
        } catch {
            return false;
        }
    });
    const { theme, toggle } = useTheme();

    useEffect(() => {
        try {
            localStorage.setItem('console.organizeOpen', organizeOpen ? '1' : '0');
        } catch {
            // Preference is best-effort.
        }
    }, [organizeOpen]);

    function logout() {
        router.post(routes.logout);
    }

    const rail = (
        <VStack className="h-full gap-0 bg-base-50">
            <div className="border-b border-border px-3 py-3">
                <HStack align="center" className="gap-2">
                    <Avatar name={appName} shape="square" color="primary" size="sm" />
                    <Text truncate className="text-[13px] font-semibold">
                        {appName}
                    </Text>
                </HStack>
            </div>
            <nav className="min-h-0 flex-1 space-y-4 overflow-y-auto px-2 py-3" aria-label="Console">
                <div>
                    <p className="mb-1 px-2 text-[10px] font-medium uppercase tracking-wider text-base-600">Workspace</p>
                    <VStack className="gap-0.5">
                        {workspace.map((item) => (
                            <NavItem key={item.href} item={item} currentPath={currentPath} onNavigate={() => setMobileOpen(false)} />
                        ))}
                    </VStack>
                </div>
                {organize.length ? (
                    <div>
                        <button
                            type="button"
                            className={`${linkButton} text-base-600 hover:bg-base-200 hover:text-base-content`}
                            aria-expanded={organizeOpen}
                            onClick={() => setOrganizeOpen((open) => !open)}
                        >
                            {organizeOpen ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
                            <span className="flex-1 truncate text-[10px] font-medium uppercase tracking-wider">Organize</span>
                            <span className="text-[10px] tabular-nums">{organize.length}</span>
                        </button>
                        {organizeOpen ? (
                            <VStack className="mt-0.5 gap-0.5">
                                {organize.map((item) => (
                                    <NavItem key={item.href} item={item} currentPath={currentPath} onNavigate={() => setMobileOpen(false)} />
                                ))}
                            </VStack>
                        ) : null}
                    </div>
                ) : null}
            </nav>
            <div className="border-t border-border px-2 py-3">
                <HStack align="center" className="mb-2 gap-2 px-1">
                    <Avatar name={user?.name || appName} color="neutral" size="sm" />
                    <div className="min-w-0">
                        <Text truncate className="text-[13px] font-medium">
                            {user?.name}
                        </Text>
                        <Text muted truncate className="text-[11px]">
                            {user?.email}
                        </Text>
                    </div>
                </HStack>
                <button
                    type="button"
                    className={`${linkButton} text-base-content hover:bg-base-200`}
                    onClick={logout}
                >
                    <LogOut className="h-4 w-4" strokeWidth={1.75} aria-hidden />
                    Log out
                </button>
            </div>
        </VStack>
    );

    return (
        <div className="flex h-screen bg-base-100 text-base-content">
            <Head title={title} />
            <aside className="hidden w-56 shrink-0 border-r border-border lg:block">{rail}</aside>
            {mobileOpen ? (
                <div className="fixed inset-0 z-40 lg:hidden">
                    <button type="button" className="absolute inset-0 bg-black/50" aria-label="Close navigation" onClick={() => setMobileOpen(false)} />
                    <div className="relative z-10 h-full w-64 border-r border-border bg-base-50">{rail}</div>
                </div>
            ) : null}
            <div className="flex min-w-0 flex-1 flex-col">
                <header className="shrink-0 border-b border-border bg-base-50">
                    <Container maxWidth="full" className="!px-3 !py-2">
                        <HStack align="center" justify="between" className="gap-2">
                            <IconButton
                                className="lg:hidden"
                                variant="outline"
                                color="neutral"
                                size="sm"
                                aria-label={mobileOpen ? 'Close navigation' : 'Open navigation'}
                                icon={mobileOpen ? <X className="h-full w-full" /> : <Menu className="h-full w-full" />}
                                onClick={() => setMobileOpen((open) => !open)}
                            />
                            <HStack align="center" className="ml-auto gap-2">
                                <IconButton
                                    variant="outline"
                                    color="neutral"
                                    size="sm"
                                    aria-label={theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'}
                                    icon={theme === 'dark' ? <Sun className="h-full w-full" /> : <Moon className="h-full w-full" />}
                                    onClick={toggle}
                                />
                                {routes.mapBuilder ? (
                                    <Link
                                        href={routes.mapBuilder}
                                        className="inline-flex h-9 items-center rounded-md bg-primary px-3 text-[13px] font-medium text-primary-content focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                                    >
                                        Open map builder
                                    </Link>
                                ) : null}
                            </HStack>
                        </HStack>
                    </Container>
                </header>
                <main className="min-h-0 flex-1 overflow-auto">
                    <Container maxWidth="full" className="!px-4 !py-4">
                        <Flash />
                        <VStack className="gap-4">{children}</VStack>
                    </Container>
                </main>
            </div>
        </div>
    );
}
