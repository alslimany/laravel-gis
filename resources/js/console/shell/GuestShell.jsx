import { Compass } from 'lucide-react';

function currentPath() {
    if (typeof window === 'undefined') {
        return '';
    }

    return window.location.pathname.replace(/\/$/, '') || '/';
}

export default function GuestShell({ children, brand, routes }) {
    const name = brand?.name || 'Lumina GIS';
    const path = currentPath();
    const onLogin = path === '/login';
    const onRegister = path === '/register';
    const showLogin = Boolean(routes?.login) && !onLogin;
    const showRegister = Boolean(routes?.register) && !onRegister;
    const line =
        name === 'Lumina GIS'
            ? 'Maps, layers, and imports stay with this organization.'
            : 'Maps, layers, and imports stay here.';

    return (
        <div className="guest-shell">
            <aside className="guest-rail">
                <a href={routes?.login || '/login'} className="guest-brand" aria-label={name}>
                    {brand?.logoUrl ? (
                        <img src={brand.logoUrl} alt="" className="guest-mark" />
                    ) : (
                        <span className="guest-mark guest-mark-fallback" aria-hidden="true">
                            <Compass className="h-4 w-4" strokeWidth={2.25} />
                        </span>
                    )}
                </a>
                <div className="guest-rail-copy">
                    <h1>{name}</h1>
                    <p>{line}</p>
                    <ol className="guest-loop">
                        <li>Import</li>
                        <li>Publish</li>
                        <li>Map</li>
                    </ol>
                </div>
            </aside>
            <div className="guest-stage">
                {showLogin || showRegister ? (
                    <div className="guest-stage-bar">
                        {showLogin ? (
                            <a href={routes.login} className="guest-text-link">
                                Log in
                            </a>
                        ) : null}
                        {showRegister ? (
                            <a href={routes.register} className="guest-text-link">
                                Create an account
                            </a>
                        ) : null}
                    </div>
                ) : null}
                <main className="guest-stage-main">{children}</main>
            </div>
        </div>
    );
}
