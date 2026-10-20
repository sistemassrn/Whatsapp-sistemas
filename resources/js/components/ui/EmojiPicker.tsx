
import { useEffect, useRef } from "react";
import data from "@emoji-mart/data";

type EmojiPickerProps = {
    onSelect: (emoji: string) => void;
};

export default function EmojiPicker({ onSelect }: EmojiPickerProps) {
    const containerRef = useRef<HTMLDivElement>(null);
    const onSelectRef = useRef(onSelect);

    useEffect(() => {
        onSelectRef.current = onSelect;
    }, [onSelect]);

    useEffect(() => {
        const container = containerRef.current;

        if (!container) return;

        let cancelled = false;
        let pickerElement: HTMLElement | null = null;

        const loadPicker = async () => {
            const module = await import("emoji-mart");

            if (cancelled) return;

            const PickerClass = module.Picker;

            const picker = new PickerClass({
                data,
                locale: "es",
                theme: "dark",
                previewPosition: "none",
                onEmojiSelect: (emoji: { native: string }) => {
                    onSelectRef.current(emoji.native);
                },
            }) as unknown as HTMLElement;

            pickerElement = picker;
            container.appendChild(picker);
        };

        void loadPicker();

        return () => {
            cancelled = true;
            pickerElement?.remove();
        };
    }, []);

    return <div ref={containerRef} />;
}
