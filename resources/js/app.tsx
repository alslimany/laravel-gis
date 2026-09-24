import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import axios from 'axios';
import { TooltipProvider } from '@/components/ui/tooltip';

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios.defaults.withCredentials = true;

const token = document.head.querySelector('meta[name="csrf-token"]');
if (token) {
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
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
                <App {...props} />
            </TooltipProvider>,
        );
    },
    progress: {
        color: 'hsl(var(--primary))',
        showSpinner: false,
    },
});
