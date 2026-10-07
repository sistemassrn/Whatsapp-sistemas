import { Moon, Sun } from 'lucide-react';
import { useEffect, useState } from 'react';

type Theme = 'dark' | 'light';

const STORAGE_KEY = 'whatsapp_sistemas.theme';
const THEME_CHANGED_EVENT = 'whatsapp-sistemas-theme-changed';

function readStoredTheme(): Theme {
    if (typeof window === 'undefined') {
        return 'dark';
    }

    try {
        return window.localStorage.getItem(STORAGE_KEY) === 'light' ? 'light' : 'dark';
    } catch {
        return 'dark';
    }
}

function applyTheme(theme: Theme) {
    if (typeof document === 'undefined') {
        return;
    }

    document.documentElement.dataset.theme = theme;
    document.documentElement.classList.toggle('light', theme === 'light');
    document.documentElement.classList.toggle('dark', theme === 'dark');
}

export function initializeTheme() {
    applyTheme(readStoredTheme());
}

export function useTheme() {
    const [theme, setThemeState] = useState<Theme>(() => readStoredTheme());

    useEffect(() => {
        applyTheme(theme);
    }, [theme]);

    useEffect(() => {
        const syncTheme = () => setThemeState(readStoredTheme());

        window.addEventListener('storage', syncTheme);
        window.addEventListener(THEME_CHANGED_EVENT, syncTheme);

        return () => {
            window.removeEventListener('storage', syncTheme);
            window.removeEventListener(THEME_CHANGED_EVENT, syncTheme);
        };
    }, []);

    function setTheme(nextTheme: Theme) {
        try {
            window.localStorage.setItem(STORAGE_KEY, nextTheme);
        } catch {
            // Keep the in-memory theme even if private browsing blocks storage.
        }

        applyTheme(nextTheme);
        setThemeState(nextTheme);
        window.dispatchEvent(new Event(THEME_CHANGED_EVENT));
    }

    return {
        theme,
        toggleTheme: () => setTheme(theme === 'dark' ? 'light' : 'dark'),
    };
}

export function ThemeToggle({ className = '' }: { className?: string }) {
    const { theme, toggleTheme } = useTheme();
    const isLight = theme === 'light';

    return (
        <button
            type="button"
            onClick={toggleTheme}
            className={`theme-toggle inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-xs font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#00a884] ${className}`}
            aria-label={isLight ? 'Cambiar a modo oscuro' : 'Cambiar a modo claro'}
            aria-pressed={isLight}
        >
            {isLight ? <Moon className="size-4" /> : <Sun className="size-4" />}
            {/* <span>{isLight ? 'Oscuro' : 'Claro'}</span> */}
        </button>
    );
}
