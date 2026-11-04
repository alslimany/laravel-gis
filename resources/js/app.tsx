import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { Direction } from 'radix-ui';
import axios from 'axios';
import { TooltipProvider } from '@/components/ui/tooltip';
import { setTranslationCatalog } from '@/lib/i18n';

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios.defaults.withCredentials = true;

const token = document.head.querySelector('meta[name="csrf-token"]');
if (token) {
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
}

type Dir = 'rtl' | 'ltr';

function pageDir(props: Record<string, unknown> | undefined): Dir {
    return props?.isRtl ? 'rtl' : 'ltr';
}

/**
 * Keep <html lang dir> in step with the page's resolved locale on EVERY render, including the
 * first load and client-side visits (an Inertia login redirect never reloads the Blade root, so
 * without this the guest page's lang="en" stayed after signing in as an Arabic user).
 */
function syncDocumentLocale(props: Record<string, unknown>): void {
    if (typeof document === 'undefined') return;
    const locale = typeof props.locale === 'string' && props.locale !== '' ? props.locale : null;
    const root = document.documentElement;
    if (locale && root.lang !== locale) root.lang = locale;
    const dir = pageDir(props);
    if (root.dir !== dir) root.dir = dir;
}

createInertiaApp({
    title: (title) => (title ? `${title} · Lumina GIS` : 'Lumina GIS'),
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.{tsx,jsx}', { eager: true }) as Record<
            string,
            { default: unknown }
        >;

        const candidates = [`./Pages/${name}.tsx`, `./Pages/${name}.jsx`];

        for (const key of candidates) {
            const page = pages[key];
            if (page) {
                return page;
            }
        }

        throw new Error(`Missing Inertia page: ${name}`);
    },
    setup({ el, App, props }) {
        createRoot(el).render(
            <TooltipProvider delayDuration={0}>
                <App {...props}>
                    {({ Component, props: pageProps, key }) => {
                        // Radix reads direction from context (defaults to LTR, which mirrored every
                        // menu/select wrongly in Arabic). Set per visit, so a language switch follows.
                        // <html dir lang> itself is rendered server-side in app.blade.php.
                        const dir = pageDir(pageProps as Record<string, unknown>);
                        // Plain (non-hook) __() helper reads the same catalog as useTranslation().
                        setTranslationCatalog(pageProps as Record<string, unknown>);
                        syncDocumentLocale(pageProps as Record<string, unknown>);

                        return (
                            <Direction.Provider dir={dir}>
                                <Component key={key} {...pageProps} />
                            </Direction.Provider>
                        );
                    }}
                </App>
            </TooltipProvider>,
        );
    },
    progress: {
        color: 'hsl(var(--primary))',
        showSpinner: false,
    },
});
