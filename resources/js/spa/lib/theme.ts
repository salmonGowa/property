import { useCallback, useEffect, useState } from 'react';

export type Theme = 'light' | 'dark' | 'system';

function readTheme(): Theme {
    try {
        const stored = localStorage.getItem('theme');

        return stored === 'light' || stored === 'dark' ? stored : 'system';
    } catch {
        return 'system';
    }
}

function apply(theme: Theme): void {
    const dark = theme === 'dark' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.classList.toggle('dark', dark);
}

/** Light / dark / follow-the-OS, remembered per browser. */
export function useTheme(): [Theme, (theme: Theme) => void] {
    const [theme, setThemeState] = useState<Theme>(readTheme);

    useEffect(() => {
        apply(theme);

        if (theme !== 'system') {
            return;
        }

        const media = window.matchMedia('(prefers-color-scheme: dark)');
        const listener = () => apply('system');
        media.addEventListener('change', listener);

        return () => media.removeEventListener('change', listener);
    }, [theme]);

    const setTheme = useCallback((next: Theme) => {
        try {
            if (next === 'system') {
                localStorage.removeItem('theme');
            } else {
                localStorage.setItem('theme', next);
            }
        } catch {
            // Storage unavailable (private mode); the choice simply won't persist.
        }

        setThemeState(next);
    }, []);

    return [theme, setTheme];
}
