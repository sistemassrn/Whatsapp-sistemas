import { Search } from 'lucide-react';
import type { ReactNode } from 'react';

export function SearchForm({
    placeholder,
    value,
    onChange,
    onSubmit,
    onClear,
    actions,
    className = '',
}: {
    placeholder: string;
    value: string;
    onChange: (value: string) => void;
    onSubmit: () => void;
    onClear: () => void;
    actions?: ReactNode;
    className?: string;
}) {
    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                onSubmit();
            }}
            className={`app-input-shell flex items-center gap-2 rounded-xl border px-3 py-2 ${className}`}
        >
            <Search className="app-faint size-4" />
            <input
                type="search"
                value={value}
                onChange={(event) => onChange(event.target.value)}
                placeholder={placeholder}
                className="w-full bg-transparent text-sm text-(--app-control-text) outline-none placeholder:text-(--app-faint)"
            />
            {value.trim() !== '' ? (
                <button
                    type="button"
                    onClick={onClear}
                    className="app-muted text-xs font-medium transition hover:text-(--app-accent)"
                >
                    Limpiar
                </button>
            ) : null}
            {actions}
        </form>
    );
}
