/** All user-facing times are shown in the operations timezone, never UTC/ISO. */
export const DISPLAY_TIME_ZONE = 'Africa/Tripoli';

function uiLocale(): string {
    const lang = typeof document !== 'undefined' ? document.documentElement.lang : '';
    // Arabic with Latin digits (matches the rest of the UI and the PDFs).
    return lang === 'ar' ? 'ar-LY-u-nu-latn' : lang || 'en';
}

function toDate(value?: string | number | Date | null): Date | null {
    if (value === null || value === undefined || value === '') {
        return null;
    }
    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? null : date;
}

export function when(value?: string | number | Date | null): string {
    const date = toDate(value);
    if (!date) {
        return value ? String(value) : '—';
    }

    return date.toLocaleDateString(uiLocale(), {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        timeZone: DISPLAY_TIME_ZONE,
    });
}

/** Date + time (24h) in Africa/Tripoli, e.g. «1 نوفمبر 2026، 13:31». */
export function whenTime(value?: string | number | Date | null): string {
    const date = toDate(value);
    if (!date) {
        return value ? String(value) : '—';
    }

    return date.toLocaleString(uiLocale(), {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
        timeZone: DISPLAY_TIME_ZONE,
    });
}

/** Value for <input type="datetime-local"> in Africa/Tripoli (YYYY-MM-DDTHH:mm). */
export function localInputValue(value: string | number | Date = new Date()): string {
    const date = toDate(value) ?? new Date();
    const parts = Object.fromEntries(
        new Intl.DateTimeFormat('en-CA', {
            timeZone: DISPLAY_TIME_ZONE,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
        })
            .formatToParts(date)
            .map((part) => [part.type, part.value]),
    );

    return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
}

export function bytes(value?: number | string | null): string {
    const size = Number(value) || 0;
    if (size >= 1048576) {
        return `${(size / 1048576).toFixed(1)} MB`;
    }

    return `${Math.round(size / 1024)} KB`;
}

export function count(value?: number | string | null): string {
    const number = Number(value);
    if (!Number.isFinite(number)) {
        return '—';
    }

    return number.toLocaleString(uiLocale());
}
