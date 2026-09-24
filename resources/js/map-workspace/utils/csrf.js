/**
 * Read Laravel CSRF token from the page meta tag.
 */
export function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

/**
 * Common JSON headers including CSRF for Laravel fetch calls.
 */
export function jsonHeaders(extra = {}) {
    return {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': getCsrfToken(),
        'X-Requested-With': 'XMLHttpRequest',
        ...extra,
    };
}
