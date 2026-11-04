import { usePage } from '@inertiajs/react';
import { useEffect, type ReactNode } from 'react';

export function RtlSync({ children }: { children: ReactNode }) {
    const { isRtl, locale } = usePage().props as { isRtl?: boolean; locale?: string };

    useEffect(() => {
        document.documentElement.dir = isRtl ? 'rtl' : 'ltr';
        if (locale) document.documentElement.lang = locale;
    }, [isRtl, locale]);

    return children;
}
