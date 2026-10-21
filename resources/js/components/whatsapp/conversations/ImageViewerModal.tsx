import {
    ChevronLeft,
    ChevronRight,
    Download,
    X,
    ZoomIn,
    ZoomOut,
} from 'lucide-react';
import { type PointerEvent, useEffect, useRef, useState } from 'react';
import type { ImagePreview } from './types';

export function ImageViewerModal({
    image,
    positionLabel,
    canNavigate,
    onPrevious,
    onNext,
    onClose,
}: {
    image: ImagePreview;
    positionLabel: string | null;
    canNavigate: boolean;
    onPrevious: () => void;
    onNext: () => void;
    onClose: () => void;
}) {
    const [scale, setScale] = useState(1);
    const [position, setPosition] = useState({ x: 0, y: 0 });
    const dragStartRef = useRef<{
        pointerId: number;
        x: number;
        y: number;
        positionX: number;
        positionY: number;
    } | null>(null);

    useEffect(() => {
        setScale(1);
        setPosition({ x: 0, y: 0 });
    }, [image.id]);

    useEffect(() => {
        const handleViewerKeyDown = (event: globalThis.KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                onClose();
                return;
            }

            if (event.key === 'ArrowLeft' && canNavigate) {
                event.preventDefault();
                event.stopPropagation();
                onPrevious();
                return;
            }

            if (event.key === 'ArrowRight' && canNavigate) {
                event.preventDefault();
                event.stopPropagation();
                onNext();
            }
        };

        window.addEventListener('keydown', handleViewerKeyDown, true);

        return () =>
            window.removeEventListener('keydown', handleViewerKeyDown, true);
    }, [canNavigate, onClose, onNext, onPrevious]);

    const zoom = (direction: 'in' | 'out') => {
        setScale((currentScale) => {
            const nextScale =
                direction === 'in'
                    ? Math.min(currentScale + 0.25, 4)
                    : Math.max(currentScale - 0.25, 1);

            if (nextScale === 1) {
                setPosition({ x: 0, y: 0 });
            }

            return nextScale;
        });
    };

    const startDrag = (event: PointerEvent<HTMLDivElement>) => {
        if (scale <= 1) {
            return;
        }

        event.currentTarget.setPointerCapture(event.pointerId);
        dragStartRef.current = {
            pointerId: event.pointerId,
            x: event.clientX,
            y: event.clientY,
            positionX: position.x,
            positionY: position.y,
        };
    };

    const drag = (event: PointerEvent<HTMLDivElement>) => {
        const dragStart = dragStartRef.current;

        if (!dragStart || dragStart.pointerId !== event.pointerId) {
            return;
        }

        setPosition({
            x: dragStart.positionX + event.clientX - dragStart.x,
            y: dragStart.positionY + event.clientY - dragStart.y,
        });
    };

    const stopDrag = (event: PointerEvent<HTMLDivElement>) => {
        if (dragStartRef.current?.pointerId === event.pointerId) {
            dragStartRef.current = null;
        }
    };

    return (
        <div
            className="theme-preserve-dark fixed inset-0 z-50 flex flex-col bg-black/90 text-white"
            role="dialog"
            aria-modal="true"
            aria-label="Vista ampliada de imagen"
        >
            <header className="flex items-center justify-between gap-3 border-b border-white/10 px-4 py-3">
                <div className="min-w-0">
                    <p className="truncate text-sm font-medium">
                        {image.filename}
                    </p>
                    {positionLabel ? (
                        <p className="mt-0.5 text-xs text-zinc-400">
                            {positionLabel}
                        </p>
                    ) : null}
                </div>
                <div className="flex items-center gap-2">
                    <button
                        type="button"
                        onClick={onPrevious}
                        disabled={!canNavigate}
                        className="grid size-10 place-items-center rounded-full transition hover:bg-white/10 disabled:cursor-not-allowed disabled:opacity-40"
                        aria-label="Imagen anterior"
                    >
                        <ChevronLeft className="size-5" />
                    </button>
                    <button
                        type="button"
                        onClick={onNext}
                        disabled={!canNavigate}
                        className="grid size-10 place-items-center rounded-full transition hover:bg-white/10 disabled:cursor-not-allowed disabled:opacity-40"
                        aria-label="Imagen siguiente"
                    >
                        <ChevronRight className="size-5" />
                    </button>
                    <a
                        href={image.url}
                        download={image.filename}
                        className="grid size-10 place-items-center rounded-full transition hover:bg-white/10"
                        aria-label="Descargar imagen"
                    >
                        <Download className="size-5" />
                    </a>
                    <button
                        type="button"
                        onClick={() => zoom('out')}
                        className="grid size-10 place-items-center rounded-full transition hover:bg-white/10"
                        aria-label="Alejar imagen"
                    >
                        <ZoomOut className="size-5" />
                    </button>
                    <button
                        type="button"
                        onClick={() => zoom('in')}
                        className="grid size-10 place-items-center rounded-full transition hover:bg-white/10"
                        aria-label="Acercar imagen"
                    >
                        <ZoomIn className="size-5" />
                    </button>
                    <button
                        type="button"
                        onClick={onClose}
                        className="grid size-10 place-items-center rounded-full transition hover:bg-white/10"
                        aria-label="Cerrar imagen"
                    >
                        <X className="size-5" />
                    </button>
                </div>
            </header>
            <div
                className={`flex min-h-0 flex-1 items-center justify-center overflow-hidden ${scale > 1 ? 'cursor-grab active:cursor-grabbing' : ''}`}
                onPointerDown={startDrag}
                onPointerMove={drag}
                onPointerUp={stopDrag}
                onPointerCancel={stopDrag}
            >
                <img
                    src={image.url}
                    alt={image.filename}
                    draggable={false}
                    className="max-h-full max-w-full object-contain p-4 transition-transform duration-75 select-none"
                    style={{
                        transform: `translate(${position.x}px, ${position.y}px) scale(${scale})`,
                    }}
                />
            </div>
        </div>
    );
}
