import { Head, Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import type { ReactNode } from 'react';

export default function GuestLayout({ title, children }: { title?: string; children: ReactNode }) {
    const { brand, routes } = usePage().props;
    const name = brand?.name || 'Lumina GIS';
    const onLogin = typeof window !== 'undefined' && window.location.pathname === '/login';
    const onRegister = typeof window !== 'undefined' && window.location.pathname === '/register';

    return (
        <div className="lumina-field grid min-h-screen lg:grid-cols-[minmax(280px,1fr)_minmax(320px,440px)]">
            {title ? <Head title={title} /> : null}
            <aside className="flex flex-col justify-between gap-8 p-8 sm:p-12">
                <div className="flex items-center gap-3">
                    <div className="flex size-10 items-center justify-center rounded-md border border-white/30 text-white">
                        <AppLogoIcon className="size-6" />
                    </div>
                    <div>
                        <p className="text-sm font-semibold tracking-[0.18em] text-white">{name.toUpperCase()}</p>
                        <p className="text-[11px] tracking-[0.22em] text-white/60">SPATIAL INTELLIGENCE</p>
                    </div>
                </div>
                <div className="max-w-md">
                    <h1 className="text-4xl font-semibold tracking-tight text-white">{name}</h1>
                    <p className="mt-3 text-lg text-white/80">Illuminating spatial intelligence.</p>
                    <p className="mt-4 max-w-prose text-sm leading-6 text-white/60">
                        Import a dataset, publish a layer, and compose a map for this organization.
                    </p>
                </div>
            </aside>
            <main className="flex items-center justify-center p-6 sm:p-10">
                <div className="w-full max-w-sm space-y-6 rounded-lg border border-white/15 bg-black/80 p-6 text-white">
                    {children}
                    <div className="flex gap-4 text-sm">
                        {routes.login && !onLogin ? (
                            <Link href={routes.login} className="text-white underline-offset-4 hover:underline">
                                Sign in
                            </Link>
                        ) : null}
                        {routes.register && !onRegister ? (
                            <Link href={routes.register} className="text-white/70 hover:text-white">
                                Register
                            </Link>
                        ) : null}
                    </div>
                </div>
            </main>
        </div>
    );
}
