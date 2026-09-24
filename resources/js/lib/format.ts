export function when(value?: string | number | Date | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return String(value);
    }

    return date.toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
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

    return number.toLocaleString();
}
