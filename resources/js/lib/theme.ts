import { useState } from 'react';

export function useTheme() {
    const [theme, setTheme] = useState<'dark' | 'light'>(() =>
        document.documentElement.classList.contains('dark') ? 'dark' : 'light',
    );

    function toggle() {
        const next = theme === 'dark' ? 'light' : 'dark';
        document.documentElement.classList.toggle('dark', next === 'dark');
        document.documentElement.classList.toggle('light', next !== 'dark');
        try {
            localStorage.setItem('console.theme', next);
        } catch {
            // Preference is best-effort.
        }
        setTheme(next);
    }

    return { theme, toggle };
}
