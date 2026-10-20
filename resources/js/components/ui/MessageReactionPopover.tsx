import {
    FloatingFocusManager,
    FloatingPortal,
    autoUpdate,
    flip,
    offset,
    shift,
    useDismiss,
    useFloating,
    useInteractions,
    useRole,
    type Placement,
} from "@floating-ui/react";
import { useEffect, useState } from "react";
import EmojiPicker from "./EmojiPicker";

type MessageReactionPopoverProps = {
    open: boolean;
    reference: Element | null;
    placement: Placement;
    processing: boolean;
    reactions: readonly string[];
    onOpenChange: (open: boolean) => void;
    onSelect: (emoji: string) => void;
};

export default function MessageReactionPopover({
    open,
    reference,
    placement,
    processing,
    reactions,
    onOpenChange,
    onSelect,
}: MessageReactionPopoverProps) {
    const [fullPickerOpen, setFullPickerOpen] = useState(false);
    const { context, floatingStyles, refs } = useFloating({
        open,
        onOpenChange(nextOpen, event, reason) {
            if (reason === "escape-key") {
                event?.preventDefault();
            }

            onOpenChange(nextOpen);
        },
        placement,
        middleware: [offset(8), flip({ padding: 8 }), shift({ padding: 8 })],
        whileElementsMounted: autoUpdate,
    });
    const dismiss = useDismiss(context, {
        outsidePressEvent: "pointerdown",
        capture: { escapeKey: true },
    });
    const role = useRole(context, { role: "dialog" });
    const { getFloatingProps } = useInteractions([dismiss, role]);

    useEffect(() => {
        refs.setReference(reference);
    }, [reference, refs]);

    useEffect(() => {
        if (!open) {
            setFullPickerOpen(false);
        }
    }, [open]);

    const selectReaction = (emoji: string) => {
        onOpenChange(false);
        onSelect(emoji);
    };

    if (!open || !reference) {
        return null;
    }

    return (
        <FloatingPortal>
            <FloatingFocusManager
                context={context}
                modal={false}
                returnFocus={false}
            >
                <div
                    ref={refs.setFloating}
                    style={floatingStyles}
                    className="app-menu z-40 max-h-[calc(100vh-1rem)] overflow-y-auto rounded-xl border p-1.5 shadow-2xl shadow-black/30"
                    aria-label="Elegir reacción"
                    {...getFloatingProps()}
                >
                    {fullPickerOpen ? (
                        <EmojiPicker onSelect={selectReaction} />
                    ) : (
                        <div className="flex items-center gap-0.5">
                            {reactions.map((emoji) => (
                                <button
                                    key={emoji}
                                    type="button"
                                    onClick={() => selectReaction(emoji)}
                                    disabled={processing}
                                    className="app-focus-ring grid size-8 place-items-center rounded-lg text-lg leading-none transition hover:bg-(--app-control-hover) disabled:cursor-wait disabled:opacity-60"
                                    aria-label={`Reaccionar con ${emoji}`}
                                >
                                    {emoji}
                                </button>
                            ))}
                            <button
                                type="button"
                                onClick={() => setFullPickerOpen(true)}
                                disabled={processing}
                                className="app-focus-ring app-muted grid size-8 place-items-center rounded-lg text-lg leading-none transition hover:bg-(--app-control-hover) disabled:cursor-wait disabled:opacity-60"
                                aria-label="Elegir otra reacción"
                            >
                                +
                            </button>
                        </div>
                    )}
                </div>
            </FloatingFocusManager>
        </FloatingPortal>
    );
}
