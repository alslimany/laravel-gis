import type { SharedPageProps } from '@/types';

declare global {
    interface Window {
        axios: typeof import('axios').default;
    }
}

declare module '@inertiajs/core' {
    interface PageProps extends SharedPageProps {}
}

export {};
