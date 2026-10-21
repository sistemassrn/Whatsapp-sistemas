import { useEffect } from 'react';

export function useEscapeClose(onClose: () => void) {
    useEffect(() => {
        const closeOnEscape = (event: globalThis.KeyboardEvent) => {
            if (event.key !== 'Escape') {
                return;
            }

            event.preventDefault();
            onClose();
        };

        document.addEventListener('keydown', closeOnEscape);

        return () => document.removeEventListener('keydown', closeOnEscape);
    }, [onClose]);
}
