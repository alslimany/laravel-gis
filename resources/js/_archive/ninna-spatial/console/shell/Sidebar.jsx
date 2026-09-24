import { useState } from 'react';
import {
    LayoutDashboard,
    FolderKanban,
    Upload,
    Layers,
    Map,
    Library,
    ChartColumn,
    FormInput,
    UsersRound,
    KeyRound,
    Building2,
    Users,
    PanelLeftClose,
    PanelLeftOpen,
    Compass,
    ChevronDown,
    ChevronRight,
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

    return (
        here === hrefPath ||
        (hrefPath !== '/' && here.startsWith(`${hrefPath}/`)) ||
        (item.match && here.startsWith(item.match))
    );
}

function NavLink({ item, currentPath, collapsed, onNavigate }) {
    const Icon = ICONS[item.icon] || Layers;
    const active = isCurrent(item, currentPath);

    return (
        <a
            href={item.href}
            onClick={onNavigate}
            aria-current={active ? 'page' : undefined}
            aria-label={collapsed ? item.label : undefined}
            title={collapsed ? item.label : undefined}
            className={`group flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm no-underline transition-colors ${
                collapsed ? 'justify-center px-2' : ''
            } ${
                active
                    ? 'bg-terra text-white shadow-[inset_0_0_0_1px_rgba(255,255,255,0.08)]'
                    : 'text-white/75 hover:bg-white/5 hover:text-white'
            }`}
        >
            <Icon className="h-[18px] w-[18px] shrink-0" strokeWidth={1.75} />
            {!collapsed ? <span className="truncate">{item.label}</span> : null}
        </a>
    );
}

export default function Sidebar({
    nav = [],
    brand,
    organization,
    currentPath,
    open,
    collapsed,
    onCollapseToggle,
    onNavigate,
}) {
    const appName = brand?.name || organization?.name || 'GIS Console';
    const workspace = nav.filter((item) => item.group !== 'organize');
    const organize = nav.filter((item) => item.group === 'organize');
    const organizeActive = organize.some((item) => isCurrent(item, currentPath));
    const [organizeOpen, setOrganizeOpen] = useState(organizeActive);
    const organizeShown = organizeOpen || organizeActive;
    const organizeListVisible = organizeShown && !collapsed;

    function toggleOrganize() {
        if (collapsed) {
            setOrganizeOpen(true);
            onCollapseToggle();
            return;
        }

        if (!organizeActive) {
            setOrganizeOpen((open) => !open);
        }
    }

    return (
        <aside
            className={`fixed inset-y-0 left-0 z-40 flex flex-col border-r border-white/10 bg-ink text-white transition-all duration-200 ${
                collapsed ? 'w-[72px]' : 'w-64'
            } ${open ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'}`}
        >
            <div
                className={`flex h-14 items-center gap-3 border-b border-white/10 px-4 ${
                    collapsed ? 'justify-center px-2' : ''
                }`}
            >
                {brand?.logoUrl ? (
                    <img
                        src={brand.logoUrl}
                        alt=""
                        className="h-8 w-8 rounded-md object-cover ring-1 ring-white/20"
                    />
                ) : (
                    <span className="flex h-8 w-8 items-center justify-center rounded-md bg-terra text-white">
                        <Compass className="h-4 w-4" strokeWidth={2.25} aria-hidden="true" />
                    </span>
                )}
                {collapsed ? <span className="visually-hidden">{appName}</span> : null}
                {!collapsed ? (
                    <div className="min-w-0">
                        <div className="truncate text-sm font-semibold tracking-tight">{appName}</div>
                    </div>
                ) : null}
            </div>

            <nav className="flex-1 space-y-1 overflow-y-auto px-2 py-3" aria-label="Workspace">
                {workspace.map((item) => (
                    <NavLink
                        key={item.href + item.label}
                        item={item}
                        currentPath={currentPath}
                        collapsed={collapsed}
                        onNavigate={onNavigate}
                    />
                ))}
                {organize.length > 0 ? (
                    <div className={collapsed ? 'pt-2' : 'pt-4'}>
                        <button
                            type="button"
                            className={`nav-disclose ${collapsed ? 'is-collapsed' : ''}`}
                            aria-expanded={organizeListVisible}
                            aria-controls={organizeListVisible ? 'nav-organize' : undefined}
                            aria-label={collapsed ? 'Organize' : undefined}
                            onClick={toggleOrganize}
                            title={collapsed ? 'Organize' : undefined}
                        >
                            {collapsed ? (
                                <ChevronRight className="h-4 w-4" aria-hidden="true" />
                            ) : (
                                <>
                                    <span>Organize</span>
                                    {organizeShown ? (
                                        <ChevronDown className="h-4 w-4" />
                                    ) : (
                                        <ChevronRight className="h-4 w-4" />
                                    )}
                                </>
                            )}
                        </button>
                        {organizeListVisible ? (
                            <div id="nav-organize" className="mt-1 space-y-1">
                                {organize.map((item) => (
                                    <NavLink
                                        key={item.href + item.label}
                                        item={item}
                                        currentPath={currentPath}
                                        collapsed={false}
                                        onNavigate={onNavigate}
                                    />
                                ))}
                            </div>
                        ) : null}
                    </div>
                ) : null}
            </nav>

            <div className="border-t border-white/10 p-2">
                <button
                    type="button"
                    onClick={onCollapseToggle}
                    className="hidden w-full items-center justify-center gap-2 rounded-lg px-3 py-2 text-xs text-white/55 transition hover:bg-white/5 hover:text-white lg:flex"
                    aria-label={collapsed ? 'Expand navigation' : 'Collapse navigation'}
                    title={collapsed ? 'Expand navigation' : undefined}
                >
                    {collapsed ? (
                        <PanelLeftOpen className="h-4 w-4" aria-hidden="true" />
                    ) : (
                        <>
                            <PanelLeftClose className="h-4 w-4" aria-hidden="true" />
                            <span>Collapse</span>
                        </>
                    )}
                </button>
            </div>
        </aside>
    );
}
