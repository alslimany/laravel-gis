import { __ } from '@/lib/i18n';

/**
 * Plugin publishers store metadata.description_key (e.g. layers.desc.incidents); translate by
 * that key and fall back to the stored description text (user layers, or a missing key).
 */
export function layerDescription(description?: string | null, metadata?: { description_key?: string | null } | null): string {
    const key = metadata?.description_key;
    if (key) {
        const text = __(key);
        if (text !== key) {
            return text;
        }
    }

    return description ?? '';
}
