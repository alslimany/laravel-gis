import { Menu, LogOut, MapPlus, Library } from 'lucide-react';

export default function Topbar({ user, organization, routes, csrf, onMenuClick, currentPath }) {
    const initials = (user?.name || '?')
        .split(/\s+/)
        .map((part) => part[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();

    return (
        <header className="sticky top-0 z-20 flex h-14 items-center gap-3 border-b border-line bg-panel px-4 sm:px-6">
            <button
                type="button"
                className="inline-flex h-11 w-11 items-center justify-center rounded-lg border border-line text-slate lg:hidden"
                onClick={onMenuClick}
                aria-label="Open navigation"
            >
                <Menu className="h-4 w-4" />
            </button>

            <div className="hidden min-w-0 flex-1 items-center md:flex">
                {routes?.catalog ? (
                    <a
                        href={routes.catalog}
                        className="catalog-jump"
                        aria-current={
                            (currentPath || '').replace(/\/$/, '') ===
                            new URL(routes.catalog, window.location.origin).pathname.replace(/\/$/, '')
                                ? 'page'
                                : undefined
                        }
                    >
                        <Library className="h-4 w-4" aria-hidden="true" strokeWidth={1.75} />
                        Browse catalog
                    </a>
                ) : (
                    <span className="text-sm text-muted">{organization?.name}</span>
                )}
            </div>

            <div className="ml-auto flex items-center gap-2">
                {routes?.mapBuilder ? (
                    <a
                        href={routes.mapBuilder}
                        className="inline-flex h-11 items-center gap-2 rounded-lg bg-terra px-3 text-sm font-medium text-white transition hover:bg-terra-dim"
                    >
                        <MapPlus className="h-4 w-4" />
                        <span className="hidden sm:inline">Open map builder</span>
                    </a>
                ) : null}

                <div className="hidden items-center gap-2 rounded-lg border border-line bg-panel-soft px-2.5 py-1.5 sm:flex">
                    <span className="flex h-7 w-7 items-center justify-center rounded-md bg-ink text-[11px] font-semibold text-white">
                        {initials}
                    </span>
                    <div className="min-w-0 leading-tight">
                        <div className="truncate text-xs font-semibold text-ink">{user?.name}</div>
                        <div className="truncate text-[11px] text-muted">
                            {organization?.name || 'No organization'}
                        </div>
                    </div>
                </div>

                {routes?.logout ? (
                    <form method="POST" action={routes.logout}>
                        <input type="hidden" name="_token" value={csrf} />
                        <button
                            type="submit"
                            className="inline-flex h-11 w-11 items-center justify-center rounded-lg border border-line text-muted transition hover:border-signal/30 hover:bg-signal/5 hover:text-signal"
                            aria-label="Log out"
                            title="Log out"
                        >
                            <LogOut className="h-4 w-4" aria-hidden="true" />
                        </button>
                    </form>
                ) : null}
            </div>
        </header>
    );
}
