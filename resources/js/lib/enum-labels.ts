import { __ } from '@/lib/i18n';

/** Normalise a raw enum/code value to its catalog key part: "At Risk" / "at-risk" -> "at_risk". */
export function enumKey(value: unknown): string {
    return String(value ?? '')
        .trim()
        .toLowerCase()
        .replace(/[\s-]+/g, '_');
}

/** "at_risk" -> "At risk" (last-resort display when no translation exists). */
export function humanizeEnum(value: unknown): string {
    const text = String(value ?? '').replace(/_/g, ' ').trim();

    return text === '' ? '' : text.charAt(0).toUpperCase() + text.slice(1);
}

/**
 * Display label for a raw enum value (status, severity, risk level, site type, lifeline, CAP
 * fields, roles ...). Looks up `<prefix>.<value>` for each given prefix, then the shared
 * `enum.<value>` catalog (lang/{ar,en}.json), then a humanised fallback. Never shows snake_case.
 */
export function enumLabel(value: unknown, ...prefixes: string[]): string {
    if (value === null || value === undefined || value === '') return '';
    const key = enumKey(value);

    for (const prefix of [...prefixes, 'enum']) {
        const full = `${prefix}.${key}`;
        const hit = __(full);
        if (hit !== full) return hit;
    }

    return humanizeEnum(value);
}

/** Label for a data field / layer attribute key (capacity_mcm, basin_name ...) from `field.<key>`. */
export function fieldLabel(key: unknown): string {
    const k = String(key ?? '');
    const full = `field.${k}`;
    const hit = __(full);

    return hit !== full ? hit : humanizeEnum(k);
}
