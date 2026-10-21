import { router } from '@inertiajs/react';
import { X } from 'lucide-react';
import { type ReactNode, type RefObject, useRef, useState } from 'react';
import { SearchForm } from './SearchForm';
import { useAvatarSource } from './hooks/useAvatarSource';
import { useCloseOnOutsidePointer } from './hooks/useCloseOnOutsidePointer';
import { useContactsSearch } from './hooks/useContactsSearch';
import { useEscapeClose } from './hooks/useEscapeClose';
import type { AgendaContact } from './types';

export function AgendaModal({ onClose }: { onClose: () => void }) {
    const modalRef = useRef<HTMLDivElement>(null);
    const [search, setSearch] = useState('');
    const [openingContactId, setOpeningContactId] = useState<number | null>(
        null,
    );
    const { contacts, loading, error } = useContactsSearch(search);

    useCloseOnOutsidePointer(modalRef, true, onClose);
    useEscapeClose(onClose);

    const openContact = (contact: AgendaContact) => {
        if (openingContactId !== null) {
            return;
        }

        setOpeningContactId(contact.id);

        router.post(
            `/whatsapp/contacts/${contact.id}/open-conversation`,
            {},
            {
                preserveScroll: true,
                onSuccess: onClose,
                onFinish: () => setOpeningContactId(null),
            },
        );
    };

    return (
        <ContactModalFrame
            modalRef={modalRef}
            title="Agenda"
            titleId="agenda-title"
            closeLabel="Cerrar agenda"
            onClose={onClose}
        >
            <ContactSearchInput search={search} onSearchChange={setSearch} />
            <ContactList
                contacts={contacts}
                loading={loading}
                error={error}
                actionLabel={(contact) =>
                    openingContactId === contact.id ? 'Abriendo…' : null
                }
                disabled={() => openingContactId !== null}
                disabledClassName="disabled:cursor-wait disabled:opacity-70"
                onSelect={openContact}
            />
        </ContactModalFrame>
    );
}

export function ContactSelectorModal({
    sendingContactId,
    onClose,
    onSelect,
}: {
    sendingContactId: number | null;
    onClose: () => void;
    onSelect: (contact: AgendaContact) => void;
}) {
    const modalRef = useRef<HTMLDivElement>(null);
    const [search, setSearch] = useState('');
    const { contacts, loading, error } = useContactsSearch(search);

    useCloseOnOutsidePointer(modalRef, true, onClose);
    useEscapeClose(onClose);

    return (
        <ContactModalFrame
            modalRef={modalRef}
            title="Enviar contacto"
            titleId="contact-selector-title"
            description="Elegí un único contacto para compartir en este chat."
            closeLabel="Cerrar selector de contactos"
            onClose={onClose}
        >
            <ContactSearchInput search={search} onSearchChange={setSearch} />
            <ContactList
                contacts={contacts}
                loading={loading}
                error={error}
                actionLabel={(contact) =>
                    sendingContactId === contact.id ? 'Enviando…' : null
                }
                disabled={(contact) =>
                    sendingContactId !== null || !contact.usable_phone
                }
                disabledClassName="disabled:cursor-not-allowed disabled:opacity-60"
                footer={(contact) =>
                    !contact.usable_phone ? (
                        <span className="mt-0.5 block truncate text-xs text-(--app-danger)">
                            Sin teléfono
                        </span>
                    ) : null
                }
                onSelect={onSelect}
            />
        </ContactModalFrame>
    );
}

function ContactModalFrame({
    modalRef,
    title,
    titleId,
    description,
    closeLabel,
    onClose,
    children,
}: {
    modalRef: RefObject<HTMLDivElement | null>;
    title: string;
    titleId: string;
    description?: string;
    closeLabel: string;
    onClose: () => void;
    children: ReactNode;
}) {
    return (
        <div
            className="fixed inset-0 z-40 flex items-center justify-center bg-black/45 p-4"
            role="presentation"
        >
            <section
                ref={modalRef}
                className="app-surface flex max-h-[85vh] w-full max-w-lg flex-col rounded-3xl border p-5 shadow-2xl shadow-black/30"
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
            >
                <header className="flex items-start justify-between gap-4">
                    <div>
                        <h2 id={titleId} className="text-lg font-semibold">
                            {title}
                        </h2>
                        {description ? (
                            <p className="app-muted mt-1 text-sm">
                                {description}
                            </p>
                        ) : null}
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="app-button-secondary grid size-9 place-items-center rounded-full transition"
                        aria-label={closeLabel}
                    >
                        <X className="size-4" />
                    </button>
                </header>
                {children}
            </section>
        </div>
    );
}

function ContactSearchInput({
    search,
    onSearchChange,
}: {
    search: string;
    onSearchChange: (value: string) => void;
}) {
    return (
        <SearchForm
            placeholder="Buscar por nombre o teléfono"
            value={search}
            onChange={onSearchChange}
            onSubmit={() => undefined}
            onClear={() => onSearchChange('')}
            className="mt-4"
        />
    );
}

function ContactList({
    contacts,
    loading,
    error,
    actionLabel,
    disabled,
    disabledClassName,
    footer,
    onSelect,
}: {
    contacts: AgendaContact[];
    loading: boolean;
    error: string | null;
    actionLabel: (contact: AgendaContact) => string | null;
    disabled: (contact: AgendaContact) => boolean;
    disabledClassName: string;
    footer?: (contact: AgendaContact) => ReactNode;
    onSelect: (contact: AgendaContact) => void;
}) {
    return (
        <div
            className="mt-4 min-h-0 flex-1 overflow-y-auto rounded-2xl border border-(--app-border)"
            aria-busy={loading}
        >
            {error ? (
                <p className="app-alert-danger m-3 rounded-xl border p-3 text-sm">
                    {error}
                </p>
            ) : null}
            {loading && contacts.length === 0 ? (
                <p className="app-muted p-4 text-sm">Cargando contactos…</p>
            ) : null}
            {loading && contacts.length > 0 ? (
                <p className="app-faint px-4 py-2 text-xs">
                    Actualizando agenda…
                </p>
            ) : null}
            {!loading && contacts.length === 0 && !error ? (
                <p className="app-muted p-4 text-sm">
                    No encontramos contactos.
                </p>
            ) : null}
            {contacts.map((contact) => (
                <button
                    key={contact.id}
                    type="button"
                    onClick={() => onSelect(contact)}
                    disabled={disabled(contact)}
                    className={`flex w-full items-center gap-3 border-b border-(--app-border) px-4 py-3 text-left transition last:border-b-0 hover:bg-(--app-surface-strong) ${disabledClassName}`}
                >
                    <ContactAvatar contact={contact} />
                    <span className="min-w-0 flex-1">
                        <span className="block truncate text-sm font-semibold">
                            {contact.display_name}
                        </span>
                        {contact.secondary_label ? (
                            <span className="app-muted mt-0.5 block truncate text-xs">
                                {contact.secondary_label}
                            </span>
                        ) : null}
                        {footer?.(contact)}
                    </span>
                    {actionLabel(contact) ? (
                        <span className="app-faint text-xs">
                            {actionLabel(contact)}
                        </span>
                    ) : null}
                </button>
            ))}
        </div>
    );
}

function ContactAvatar({ contact }: { contact: AgendaContact }) {
    const { avatarUrl, handleAvatarError } = useAvatarSource(
        contact.avatar_url,
        contact.id,
    );

    if (avatarUrl) {
        return (
            <img
                src={avatarUrl}
                alt={`Foto de perfil de ${contact.display_name}`}
                className="size-10 rounded-full border border-(--app-border) object-cover"
                loading="lazy"
                onError={handleAvatarError}
            />
        );
    }

    return (
        <span className="flex size-10 shrink-0 items-center justify-center rounded-full border border-(--app-border) bg-(--app-surface-strong) text-sm font-semibold">
            {contact.initials || initials(contact.display_name)}
        </span>
    );
}

function initials(value: string): string {
    return (
        value
            .split(' ')
            .filter(Boolean)
            .slice(0, 2)
            .map((part) => part[0]?.toUpperCase())
            .join('') || '?'
    );
}
