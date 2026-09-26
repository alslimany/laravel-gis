export function filtersFromSearch(search = '') {
    const params = new URLSearchParams(search || '');
    const next = {};
    for (const [key, value] of params.entries()) {
        const match = key.match(/^filters\[(.+)\]$/);
        if (match && value && value !== '__all__') {
            next[match[1]] = value;
        }
    }
    return next;
}

export function writeFiltersToSearch(filters) {
    if (typeof window === 'undefined') return;
    const params = new URLSearchParams(window.location.search);
    for (const key of [...params.keys()]) {
        if (/^filters\[.+\]$/.test(key)) params.delete(key);
    }
    Object.entries(filters || {}).forEach(([layerId, value]) => {
        if (value) params.set(`filters[${layerId}]`, value);
    });
    const query = params.toString();
    const next = `${window.location.pathname}${query ? `?${query}` : ''}${window.location.hash}`;
    window.history.replaceState(window.history.state, '', next);
}
