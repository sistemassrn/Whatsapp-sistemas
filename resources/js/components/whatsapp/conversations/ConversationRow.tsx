import { router } from '@inertiajs/react';
import { MailOpen, MoreVertical, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import toast from 'react-hot-toast';
import { ImageViewerModal } from './ImageViewerModal';
import { INBOX_RELOAD_PROPS } from './constants';
import { useAvatarSource } from './hooks/useAvatarSource';
import { useCloseOnOutsidePointer } from './hooks/useCloseOnOutsidePointer';
import type { ConversationItem, ImagePreview, Props } from './types';
import { compactQuery, conversationHref, isAvatarUrl } from './utils';

const requestedAvatarContactIds = new Set<number>();

export function ConversationRow({
    conversation,
    active,
    filters,
    onSelect,
}: {
    conversation: ConversationItem;
    active: boolean;
    filters: Props['filters'];
    onSelect: (chatId: string) => void;
}) {
    const [avatarUrl, setAvatarUrl] = useState(conversation.avatar_url);
    const { avatarUrl: displayedAvatarUrl, handleAvatarError } =
        useAvatarSource(avatarUrl, conversation.contact_id);
    const [avatarPreview, setAvatarPreview] = useState<ImagePreview | null>(
        null,
    );
    const [menuOpen, setMenuOpen] = useState(false);
    const menuRef = useRef<HTMLDivElement>(null);
    const hasUnread =
        conversation.unread_count > 0 || conversation.marked_unread_at !== null;
    const title = conversationTitle(conversation);

    useCloseOnOutsidePointer(menuRef, menuOpen, () => setMenuOpen(false));

    useEffect(() => {
        setAvatarUrl(conversation.avatar_url);
    }, [conversation.avatar_url]);

    useEffect(() => {
        let active = true;

        if (
            conversation.contact_id === null ||
            avatarUrl ||
            requestedAvatarContactIds.has(conversation.contact_id)
        ) {
            return;
        }

        requestedAvatarContactIds.add(conversation.contact_id);

        void fetch(`/whatsapp/contacts/${conversation.contact_id}/avatar`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({}),
        })
            .then(
                (response) =>
                    response.json() as Promise<{ avatar_url?: unknown }>,
            )
            .then((payload) => {
                if (active && isAvatarUrl(payload.avatar_url)) {
                    setAvatarUrl(payload.avatar_url);
                }
            })
            .catch(() => undefined);

        return () => {
            active = false;
        };
    }, [avatarUrl, conversation.contact_id, conversation.avatar_url]);

    const markConversationUnread = () => {
        setMenuOpen(false);

        router.post(
            `/whatsapp/conversations/${conversation.id}/mark-unread`,
            compactQuery({
                chat: active ? conversation.external_id : null,
                chat_search: filters.chat_search,
            }),
            {
                only: INBOX_RELOAD_PROPS,
                preserveScroll: true,
            },
        );
    };

    const hideConversation = () => {
        setMenuOpen(false);

        toast.custom(
            (toastInstance) => (
                <div className="app-menu w-80 rounded-2xl border p-4 text-sm shadow-2xl shadow-black/30">
                    <p className="font-semibold text-(--app-text)">
                        ¿Eliminar chat?
                    </p>
                    <p className="app-muted mt-1 leading-5">
                        Solo se elimina de esta app. No borra WhatsApp.
                    </p>
                    <div className="mt-4 flex justify-end gap-2">
                        <button
                            type="button"
                            onClick={() => toast.dismiss(toastInstance.id)}
                            className="app-button-secondary rounded-full px-3 py-1.5 text-xs font-semibold"
                        >
                            Cancelar
                        </button>
                        <button
                            type="button"
                            onClick={() => {
                                toast.dismiss(toastInstance.id);
                                router.post(
                                    `/whatsapp/conversations/${conversation.id}/hide`,
                                    compactQuery({
                                        chat_search: filters.chat_search,
                                    }),
                                    {
                                        only: INBOX_RELOAD_PROPS,
                                        preserveScroll: true,
                                    },
                                );
                            }}
                            className="rounded-full bg-red-500 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-red-600"
                        >
                            Eliminar
                        </button>
                    </div>
                </div>
            ),
            { duration: Infinity },
        );
    };

    return (
        <div
            className={`relative border-b border-(--app-border) transition-all ${active ? 'm-1 rounded-2xl bg-(--app-surface-strong)' : 'm-1 bg-(--app-surface) hover:rounded-2xl hover:bg-(--app-surface-strong)'}`}
        >
            <div className="flex gap-3 px-4 py-3">
                <div className="shrink-0">
                    {displayedAvatarUrl ? (
                        <button
                            type="button"
                            onClick={() =>
                                setAvatarPreview({
                                    id: conversation.id,
                                    url: displayedAvatarUrl,
                                    filename: `Foto de perfil de ${title}`,
                                })
                            }
                            className="app-focus-ring block rounded-full"
                            title={`Ver foto de perfil de ${title}`}
                            aria-label={`Ver foto de perfil de ${title}`}
                        >
                            <img
                                src={displayedAvatarUrl}
                                alt={`Foto de perfil de ${title}`}
                                className="size-11 rounded-full border border-(--app-border) object-cover"
                                loading="lazy"
                                onError={handleAvatarError}
                            />
                        </button>
                    ) : (
                        <div className="flex size-11 items-center justify-center rounded-full border border-(--app-border) bg-(--app-surface-strong) text-sm font-semibold text-(--app-text)">
                            {initials(title)}
                        </div>
                    )}
                </div>

                <a
                    href={conversationHref(conversation.external_id, filters)}
                    onClick={(event) => {
                        event.preventDefault();
                        onSelect(conversation.external_id);
                    }}
                    className="min-w-0 flex-1 pr-14 outline-none focus:outline-none"
                >
                    <div className="flex items-start justify-between gap-3">
                        <p className="truncate text-sm font-semibold">
                            {title}
                        </p>
                        <time className="app-faint absolute top-3 right-3 shrink-0 text-[11px]">
                            {formatConversationTimestamp(
                                conversation.last_message_at,
                            )}
                        </time>
                    </div>

                    {/* {conversation.display_description ? (
                        <p className="app-muted mt-0.5 truncate text-xs">
                            {conversation.display_description}
                        </p>
                    ) : null} */}

                    <p className="app-muted mt-1 truncate text-sm">
                        {conversation.last_message_direction === 'outbound'
                            ? 'Vos: '
                            : ''}
                        {conversation.last_message_preview ??
                            conversation.last_message_body ??
                            'Sin vista previa'}
                    </p>
                </a>
            </div>

            <div className="absolute top-9 right-1 flex items-center gap-2">
                <UnreadBadge hasUnread={hasUnread} />
                <div ref={menuRef} className="relative">
                    <button
                        type="button"
                        onClick={() => setMenuOpen((current) => !current)}
                        className="app-faint app-focus-ring grid size-7 place-items-center rounded-full transition hover:bg-(--app-control-hover) hover:text-(--app-accent)"
                        aria-label="Opciones de la conversación"
                        aria-expanded={menuOpen}
                        aria-haspopup="menu"
                    >
                        <MoreVertical className="size-4" />
                    </button>
                    {menuOpen ? (
                        <div
                            className="app-menu absolute right-0 z-20 mt-2 w-48 rounded-xl border p-1 text-sm shadow-2xl shadow-black/30"
                            role="menu"
                        >
                            <button
                                type="button"
                                onClick={markConversationUnread}
                                className="app-menu-item flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left"
                                role="menuitem"
                            >
                                <MailOpen className="size-4 text-(--app-accent)" />
                                <span>Marcar no leído</span>
                            </button>
                            <button
                                type="button"
                                onClick={hideConversation}
                                className="app-menu-item flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left"
                                role="menuitem"
                            >
                                <Trash2 className="size-4 text-red-400" />
                                <span>Eliminar chat</span>
                            </button>
                        </div>
                    ) : null}
                </div>
            </div>
            {avatarPreview ? (
                <ImageViewerModal
                    image={avatarPreview}
                    positionLabel={null}
                    canNavigate={false}
                    onPrevious={() => undefined}
                    onNext={() => undefined}
                    onClose={() => setAvatarPreview(null)}
                />
            ) : null}
        </div>
    );
}

function UnreadBadge({ hasUnread }: { hasUnread: boolean }) {
    if (hasUnread) {
        return (
            <span
                className="size-2.5 shrink-0 rounded-full bg-(--app-accent)"
                aria-label="Mensajes no leídos"
            />
        );
    }

    return null;
}

function conversationTitle(conversation: ConversationItem): string {
    return (
        conversation.title ??
        conversation.contact_name ??
        conversation.external_id
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

function formatConversationTimestamp(value: string | null): string {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    if (date.toDateString() === new Date().toDateString()) {
        return formatTime(value);
    }

    return formatDate(value, {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    });
}

function formatDate(
    value: string,
    options: Intl.DateTimeFormatOptions,
): string {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleDateString('es-AR', options);
}

function formatTime(value: string, includeSeconds = false): string {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleTimeString('es-AR', {
            hour: 'numeric',
            minute: '2-digit',
            second: includeSeconds ? '2-digit' : undefined,
            hour12: true,
        });
}

function csrfToken(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}
