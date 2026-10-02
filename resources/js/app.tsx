import { createInertiaApp, type ResolvedComponent } from '@inertiajs/react';
import type { ComponentType } from 'react';
import { createRoot } from 'react-dom/client';
import { Toaster } from 'react-hot-toast';
import { initializeTheme, useTheme } from './theme';

const appName = import.meta.env.VITE_APP_NAME;
const pages = import.meta.glob<ResolvedComponent>('./pages/**/*.tsx');

initializeTheme();

function ThemedToaster() {
    const { theme } = useTheme();
    const isLight = theme === 'light';

    return (
        <Toaster
            position="top-right"
            toastOptions={{
                duration: 4000,
                style: {
                    background: isLight ? '#ffffff' : '#18181b',
                    border: isLight ? '1px solid #e4e4e7' : '1px solid rgba(255,255,255,0.1)',
                    borderRadius: '12px',
                    color: isLight ? '#18181b' : '#f4f4f5',
                    fontSize: '14px',
                },
            }}
        />
    );
}

void createInertiaApp({
    title: (title: string) => (title ? `${title} - ${appName}` : appName),
    resolve: (name: string) => {
        const page = pages[`./pages/${name}.tsx`];

        if (!page) {
            throw new Error(`Page not found: ${name}`);
        }

        return page();
    },
    strictMode: true,
    setup({ el, App, props }: { el: HTMLElement | null; App: ComponentType<Record<string, unknown>>; props: Record<string, unknown> }) {
        if (el === null) {
            return;
        }

        createRoot(el).render(
            <>
                <App {...props} />
                <ThemedToaster />
            </>,
        );
    },
    progress: {
        color: '#4B5563',
    },
});
