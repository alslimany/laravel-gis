/** Literal fallbacks mirror app.css :root / .dark. Used only when a token is missing. */
const CHART_FALLBACKS = {
    light: {
        palette: [
            'oklch(0.55 0.12 210)',
            'oklch(0.58 0.11 160)',
            'oklch(0.55 0.10 280)',
            'oklch(0.65 0.12 75)',
            'oklch(0.58 0.14 25)',
        ],
        foreground: 'oklch(0.22 0.025 255)',
        mutedForeground: 'oklch(0.48 0.02 255)',
        border: 'oklch(0.90 0.01 250)',
    },
    dark: {
        palette: [
            'oklch(0.72 0.11 210)',
            'oklch(0.72 0.10 160)',
            'oklch(0.70 0.10 280)',
            'oklch(0.78 0.11 75)',
            'oklch(0.72 0.12 25)',
        ],
        foreground: 'oklch(0.93 0.01 250)',
        mutedForeground: 'oklch(0.70 0.02 250)',
        border: 'oklch(0.30 0.02 255)',
    },
};

export function themeColors() {
    const mode = document.documentElement.classList.contains('dark') ? 'dark' : 'light';
    const fallback = CHART_FALLBACKS[mode];
    const styles = getComputedStyle(document.documentElement);
    const read = (name, value) => styles.getPropertyValue(name).trim() || value;
    const palette = fallback.palette.map((color, index) => read(`--chart-${index + 1}`, color));

    return {
        series: palette[0],
        muted: read('--muted-foreground', fallback.mutedForeground),
        copy: read('--foreground', fallback.foreground),
        line: read('--border', fallback.border),
        palette,
    };
}
