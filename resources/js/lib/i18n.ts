import { usePage } from '@inertiajs/react';

type ReplaceValues = Record<string, string | number>;

type SharedLocaleProps = {
    locale?: string;
    translations?: Record<string, string>;
    fallbackTranslations?: Record<string, string>;
};

function interpolate(text: string, replace?: ReplaceValues): string {
    if (!replace) {
        return text;
    }

    return Object.entries(replace).reduce(
        (result, [key, value]) => result.replaceAll(`:${key}`, String(value)),
        text,
    );
}

export function useTranslation() {
    const { locale, translations, fallbackTranslations } = usePage().props as SharedLocaleProps;
    const catalog = translations ?? {};
    const fallback = fallbackTranslations ?? {};

    function t(key: string, replace?: ReplaceValues): string {
        const value = catalog[key] ?? fallback[key];

        if (typeof value === 'string' && value !== '') {
            return interpolate(value, replace);
        }

        return interpolate(key, replace);
    }

    return { t, locale: locale ?? 'en' };
}

let currentCatalog: Record<string, string> = {};
let currentFallback: Record<string, string> = {};

/** Called by the app root on every Inertia page render (see app.tsx). */
export function setTranslationCatalog(props: Record<string, unknown>): void {
    currentCatalog = (props.translations as Record<string, string>) ?? {};
    currentFallback = (props.fallbackTranslations as Record<string, string>) ?? {};
}

/**
 * Non-hook translate for plain JS modules and shared UI primitives (same catalog as
 * useTranslation). Falls back to `fallback` text, then the key.
 */
export function __(key: string, replace?: ReplaceValues, fallback?: string): string {
    const value = currentCatalog[key] ?? currentFallback[key];

    if (typeof value === 'string' && value !== '') {
        return interpolate(value, replace);
    }

    return interpolate(fallback ?? key, replace);
}
