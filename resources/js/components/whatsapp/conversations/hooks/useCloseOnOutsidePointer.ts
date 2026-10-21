import type { RefObject } from 'react';
import { useEffect } from 'react';

export function useCloseOnOutsidePointer<T extends HTMLElement>(
    ref: RefObject<T | null>,
    active: boolean,
    onClose: () => void,
) {
    useEffect(() => {
        if (!active) {
            return;
        }

        const closeOnOutsidePointer = (event: globalThis.PointerEvent) => {
            const target = event.target;

            if (!(target instanceof Node) || ref.current?.contains(target)) {
                return;
            }

            onClose();
        };

        document.addEventListener('pointerdown', closeOnOutsidePointer, true);

        return () =>
            document.removeEventListener(
                'pointerdown',
                closeOnOutsidePointer,
                true,
            );
    }, [active, onClose, ref]);
}
