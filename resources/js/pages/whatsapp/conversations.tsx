import { Head, router, useForm, usePage } from '@inertiajs/react';
import { ChevronDown, ChevronLeft, ChevronRight, ChevronUp, Download, MailOpen, MoreVertical, Paperclip, Pause, Pencil, Play, Search, Send, Trash2, X, ZoomIn, ZoomOut } from 'lucide-react';
import { type ClipboardEvent, type CSSProperties, type DragEvent, type FormEvent, type KeyboardEvent, type PointerEvent, type ReactNode, type RefObject, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import toast from 'react-hot-toast';
import { ThemeToggle } from '../../theme';

const MESSAGE_LIMIT_INCREMENT = 50;
const MAX_MESSAGE_LIMIT = 300;
const COLLAPSED_MESSAGE_WORD_LIMIT = 80;
const COLLAPSED_MESSAGE_CHAR_LIMIT = 520;
const COMPOSER_TEXTAREA_MAX_HEIGHT = 144;
const MAX_FILES_PER_SEND = 3;
const MAX_FILE_BYTES = 25 * 1024 * 1024;
const MAX_TOTAL_FILE_BYTES = 50 * 1024 * 1024;
const CHAT_BACKGROUND_STYLE: CSSProperties = {
    backgroundImage: "linear-gradient(var(--chat-background-overlay), var(--chat-background-overlay)), url('/img/fondochats.webp')",
    backgroundRepeat: 'repeat',
    backgroundSize: '420px auto',
};

type Operator = {
    name?: string;
    email?: string;
    usuario?: string;
};

type AuthUser = {
    id: number | string;
    usuario: string;
    nombre: string | null;
};

type ConversationItem = {
    id: number;
    external_id: string;
    contact_id: number | null;
    title: string | null;
    contact_name: string | null;
    display_description: string | null;
    avatar_url: string | null;
    last_message_preview: string | null;
    last_message_body: string | null;
    last_message_direction: string | null;
    last_message_at: string | null;
    last_read_at: string | null;
    marked_unread_at: string | null;
    unread_count: number;
};

type MessageItem = {
    id: number;
    external_id: string | null;
    direction: string;
    body: string | null;
    type: 'text' | 'image' | 'video' | 'audio' | 'document' | string;
    status: string;
    error_message: string | null;
    edited_at: string | null;
    deleted_at: string | null;
    remote_edit_status: string | null;
    remote_delete_status: string | null;
    edit_error: string | null;
    delete_error: string | null;
    can_edit: boolean;
    can_delete: boolean;
    media_url: string | null;
    media_mime_type: string | null;
    media_filename: string | null;
    media_size_bytes: number | null;
    media_metadata: Record<string, unknown> | null;
    media_download_status: string | null;
    media_error: string | null;
    sent_at: string | null;
    received_at: string | null;
    created_at: string | null;
};

type ImagePreview = {
    id: number;
    url: string;
    filename: string;
};

type MessageRenderItem =
    | { type: 'divider'; key: string; text: string }
    | { type: 'message'; message: MessageItem }
    | { type: 'image-group'; key: string; messages: MessageItem[] };

type UploadProgress = {
    percentage?: number | null;
};

type MessageSubmitErrors = Partial<Record<'body' | 'media' | 'idempotency_key', string>> & Record<string, string>;

type ConnectionState = {
    status: string;
    isReady: boolean;
    qr: {
        qrCode?: string;
        status?: string;
    } | null;
    canStart: boolean;
    started: boolean;
    error?: string | null;
};

type Props = {
    operator?: Operator;
    auth?: {
        user: AuthUser | null;
    };
    conversations: ConversationItem[];
    selectedChatId: string | null;
    messageLimit: number;
    hasMoreMessages: boolean;
    firstUnreadMessageId: number | null;
    messages: MessageItem[];
    emptyState?: string | null;
    connection: ConnectionState;
    flash?: {
        success?: string | null;
        error?: string | null;
    };
    filters: {
        chat_search: string;
        message_search: string;
    };
};

const INBOX_RELOAD_PROPS = ['connection', 'conversations', 'messages', 'messageLimit', 'hasMoreMessages', 'firstUnreadMessageId', 'emptyState', 'filters', 'flash', 'selectedChatId'];
const requestedAvatarContactIds = new Set<number>();

function useCloseOnOutsidePointer<T extends HTMLElement>(ref: RefObject<T | null>, active: boolean, onClose: () => void) {
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

        return () => document.removeEventListener('pointerdown', closeOnOutsidePointer, true);
    }, [active, onClose, ref]);
}

export default function Conversations({ operator, conversations, selectedChatId, messageLimit, hasMoreMessages, firstUnreadMessageId, messages, emptyState, filters, flash }: Props) {
    const { auth } = usePage<Props>().props;
    const selectedConversation = conversations.find((conversation) => conversation.external_id === selectedChatId) ?? null;
    const operatorName = operator?.name ?? auth?.user?.nombre ?? auth?.user?.usuario ?? 'Operador';
    const [chatSearch, setChatSearch] = useState(filters.chat_search);
    const chatSearchRef = useRef(chatSearch);
    const chatSearchHasLocalChangeRef = useRef(false);
    const disconnectForm = useForm({});
    const logoutForm = useForm({});

    useEffect(() => {
        chatSearchRef.current = chatSearch;
    }, [chatSearch]);

    useEffect(() => {
        const normalizedFilterSearch = normalizeSearch(filters.chat_search);
        const normalizedInputSearch = normalizeSearch(chatSearchRef.current);

        if (chatSearchHasLocalChangeRef.current) {
            if (normalizedFilterSearch === normalizedInputSearch) {
                chatSearchHasLocalChangeRef.current = false;
            }

            return;
        }

        if (chatSearchRef.current !== filters.chat_search) {
            setChatSearch(filters.chat_search);
        }
    }, [filters.chat_search]);

    useEffect(() => {
        const normalizedChatSearch = normalizeSearch(chatSearch);
        const normalizedFilterSearch = normalizeSearch(filters.chat_search);

        if (normalizedChatSearch === normalizedFilterSearch) {
            return;
        }

        const timeout = window.setTimeout(() => {
            const latestNormalizedSearch = normalizeSearch(chatSearchRef.current);

            if (latestNormalizedSearch === normalizeSearch(filters.chat_search)) {
                return;
            }

            router.cancelAll({ async: false, prefetch: false });

            router.get(
                '/whatsapp/conversations',
                compactQuery({
                    chat: selectedChatId,
                    chat_search: latestNormalizedSearch,
                }),
                {
                    only: INBOX_RELOAD_PROPS,
                    preserveScroll: true,
                    preserveState: true,
                    replace: true,
                },
            );
        }, 350);

        return () => window.clearTimeout(timeout);
    }, [chatSearch, filters.chat_search, selectedChatId]);

    useEffect(() => {
        // if (flash?.success) {
        //      toast.success(flash.success);
        // }

        if (flash?.error) {
            toast.error(flash.error);
        }
    }, [flash?.error, flash?.success]);

    useEffect(() => {
        if (!selectedChatId) {
            return;
        }

        const deselectConversation = (event: globalThis.KeyboardEvent) => {
            if (event.key !== 'Escape') {
                return;
            }

            event.preventDefault();

            router.get(
                '/whatsapp/conversations',
                compactQuery({
                    chat_search: filters.chat_search,
                }),
                {
                    only: INBOX_RELOAD_PROPS,
                    preserveScroll: true,
                    preserveState: true,
                    replace: true,
                },
            );
        };

        document.addEventListener('keydown', deselectConversation);

        return () => document.removeEventListener('keydown', deselectConversation);
    }, [filters.chat_search, selectedChatId]);

    useEffect(() => {
        const interval = window.setInterval(() => {
            if (document.hidden) {
                return;
            }

            router.reload({
                only: INBOX_RELOAD_PROPS,
                preserveScroll: true,
            });
        }, 5000);

        return () => window.clearInterval(interval);
    }, []);

    function disconnect(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        disconnectForm.post('/whatsapp/disconnect');
    }

    function logout(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        logoutForm.post('/logout');
    }

    return (
        <>
            <Head title="Chats" />

            <main className="app-shell min-h-screen">
                {/* <section className="mx-auto flex h-[calc(100vh-3rem)] w-full max-w-7xl overflow-hidden rounded-2xl border border-white/10 bg-zinc-950 shadow-2xl shadow-black/50"> */}
                <section className="app-chat-shell flex h-[calc(100vh-0rem)] w-full border border-(--app-border)">
                    <aside className="app-surface flex w-full flex-col border-r md:w-115 md:shrink-0">
                        <div className="app-surface border-b p-4">
                            <div className="flex items-center justify-between gap-3">
                                <div>
                                    <h1 className="text-xl font-semibold">Chats</h1>
                                    <p className="app-faint mt-1 text-xs">
                                        {operatorName}
                                    </p>
                                </div>

                                <div className="flex shrink-0 flex-wrap justify-end gap-2">
                                    <ThemeToggle />
                                    <form onSubmit={disconnect}>
                                        <button
                                            type="submit"
                                            disabled={disconnectForm.processing}
                                            className="app-button-danger rounded-lg border px-3 py-2 text-xs font-semibold transition disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            Cerrar sesión
                                        </button>
                                    </form>
                                    <form onSubmit={logout}>
                                        <button
                                            type="submit"
                                            disabled={logoutForm.processing}
                                            className="app-button rounded-lg border px-3 py-2 text-xs font-semibold transition disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            Salir
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <SearchForm
                                placeholder="Buscar chats o contactos"
                                value={chatSearch}
                                onChange={(value) => {
                                    chatSearchHasLocalChangeRef.current = true;
                                    setChatSearch(value);
                                }}
                                onSubmit={() => undefined}
                                onClear={() => {
                                    chatSearchHasLocalChangeRef.current = true;
                                    setChatSearch('');
                                }}
                                className="mt-4"
                            />

                            {/* <div className="mt-3 flex gap-2 text-xs font-medium">
                                <span className="rounded-full border border-green-600 bg-green-50 px-3 py-1 text-green-700">Todos</span>
                                <span className="rounded-full border border-gray-200 bg-white px-3 py-1 text-gray-500">No leídos</span>
                                <span className="rounded-full border border-gray-200 bg-white px-3 py-1 text-gray-500">Pendientes</span>
                            </div> */}
                        </div>

                        <div className="min-h-0 flex-1 overflow-y-auto">
                            {conversations.length === 0 ? (
                                <p className="app-muted p-4 text-sm leading-6">
                                    {emptyState ?? 'No hay conversaciones para mostrar todavía.'}
                                </p>
                            ) : (
                                conversations.map((conversation) => (
                                    <ConversationRow
                                        key={conversation.id}
                                        conversation={conversation}
                                        active={selectedChatId === conversation.external_id}
                                        filters={filters}
                                    />
                                ))
                            )}
                        </div>
                    </aside>

                    <section className="app-chat-shell hidden min-w-0 flex-1 flex-col md:flex" style={CHAT_BACKGROUND_STYLE}>
                        {selectedConversation ? (
                            <MessagePanel
                                conversation={selectedConversation}
                                messages={messages}
                                messageLimit={messageLimit}
                                hasMoreMessages={hasMoreMessages}
                                firstUnreadMessageId={firstUnreadMessageId}
                                filters={filters}
                            />
                        ) : (
                            <EmptyConversation />
                        )}
                    </section>
                </section>
            </main>
        </>
    );
}

function ConversationRow({ conversation, active, filters }: { conversation: ConversationItem; active: boolean; filters: Props['filters'] }) {
    const [avatarUrl, setAvatarUrl] = useState(conversation.avatar_url);
    const [avatarPreview, setAvatarPreview] = useState<ImagePreview | null>(null);
    const [menuOpen, setMenuOpen] = useState(false);
    const menuRef = useRef<HTMLDivElement>(null);
    const hasUnread = conversation.unread_count > 0 || conversation.marked_unread_at !== null;
    const title = conversationTitle(conversation);

    useCloseOnOutsidePointer(menuRef, menuOpen, () => setMenuOpen(false));

    useEffect(() => {
        setAvatarUrl(conversation.avatar_url);
    }, [conversation.avatar_url]);

    useEffect(() => {
        if (conversation.contact_id === null || avatarUrl || requestedAvatarContactIds.has(conversation.contact_id)) {
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
            .then((response) => response.json() as Promise<{ avatar_url?: unknown }>)
            .then((payload) => {
                if (typeof payload.avatar_url === 'string' && payload.avatar_url !== '') {
                    setAvatarUrl(payload.avatar_url);
                }
            })
            .catch(() => undefined);
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
                    <p className="font-semibold text-(--app-text)">¿Eliminar chat?</p>
                    <p className="app-muted mt-1 leading-5">Solo se elimina de esta app. No borra WhatsApp.</p>
                    <div className="mt-4 flex justify-end gap-2">
                        <button type="button" onClick={() => toast.dismiss(toastInstance.id)} className="app-button-secondary rounded-full px-3 py-1.5 text-xs font-semibold">
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
        <div className={`relative border-b border-(--app-border) transition-all ${active ? 'm-1 rounded-2xl bg-(--app-surface-strong)' : 'm-1 bg-(--app-surface) hover:rounded-2xl hover:bg-(--app-surface-strong)'}`}>
            <div className="flex gap-3 px-4 py-3">
                <div className="shrink-0">
                    {avatarUrl ? (
                        <button
                            type="button"
                            onClick={() => setAvatarPreview({ id: conversation.id, url: avatarUrl, filename: `Foto de perfil de ${title}` })}
                            className="app-focus-ring block rounded-full"
                            title={`Ver foto de perfil de ${title}`}
                            aria-label={`Ver foto de perfil de ${title}`}
                        >
                            <img src={avatarUrl} alt={`Foto de perfil de ${title}`} className="size-11 rounded-full border border-(--app-border) object-cover" loading="lazy" />
                        </button>
                    ) : (
                        <div className="flex size-11 items-center justify-center rounded-full border border-(--app-border) bg-(--app-surface-strong) text-sm font-semibold text-(--app-text)">
                            {initials(title)}
                        </div>
                    )}
                </div>

                <a href={conversationHref(conversation.external_id, filters)} className="min-w-0 flex-1 pr-14">
                    <div className="flex items-start justify-between gap-3">
                        <p className="truncate text-sm font-semibold">{title}</p>
                        <time className="app-faint absolute top-3 right-3 shrink-0 text-[11px]">{formatConversationTimestamp(conversation.last_message_at)}</time>
                    </div>

                    {/* {conversation.display_description ? (
                        <p className="app-muted mt-0.5 truncate text-xs">
                            {conversation.display_description}
                        </p>
                    ) : null} */}

                    <p className="app-muted mt-1 truncate text-sm">
                        {conversation.last_message_direction === 'outbound' ? 'Vos: ' : ''}
                        {conversation.last_message_preview ?? conversation.last_message_body ?? 'Sin vista previa'}
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
                        <div className="app-menu absolute right-0 z-20 mt-2 w-48 rounded-xl border p-1 text-sm shadow-2xl shadow-black/30" role="menu">
                            <button type="button" onClick={markConversationUnread} className="app-menu-item flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left" role="menuitem">
                                <MailOpen className="size-4 text-(--app-accent)" />
                                <span>Marcar no leído</span>
                            </button>
                            <button type="button" onClick={hideConversation} className="app-menu-item flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left" role="menuitem">
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
        return <span className="size-2.5 shrink-0 rounded-full bg-(--app-accent)" aria-label="Mensajes no leídos" />;
    }

    return null;
}

function MessagePanel({
    conversation,
    messages,
    messageLimit,
    hasMoreMessages,
    firstUnreadMessageId,
    filters,
}: {
    conversation: ConversationItem;
    messages: MessageItem[];
    messageLimit: number;
    hasMoreMessages: boolean;
    firstUnreadMessageId: number | null;
    filters: Props['filters'];
}) {
    const scrollContainerRef = useRef<HTMLDivElement>(null);
    const previousChatIdRef = useRef<string | null>(null);
    const previousLatestMessageKeyRef = useRef<string | null>(null);
    const isLoadingOlderRef = useRef(false);
    const previousScrollHeightRef = useRef(0);
    const previousScrollTopRef = useRef(0);
    const [isLoadingOlder, setIsLoadingOlder] = useState(false);
    const [messageSearch, setMessageSearch] = useState('');
    const [activeMatchIndex, setActiveMatchIndex] = useState(0);
    const messageRefs = useRef<Record<number, HTMLElement | null>>({});
    const [imagePreview, setImagePreview] = useState<ImagePreview | null>(null);
    const [isDraggingFiles, setIsDraggingFiles] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);
    const textareaRef = useRef<HTMLTextAreaElement>(null);
    const composerBodyRef = useRef('');
    const composerMediaRef = useRef<File[]>([]);
    const [sendProgress, setSendProgress] = useState<number | null>(null);
    const { data, setData, errors, clearErrors, setError } = useForm<{
        body: string;
        media: File[];
        idempotency_key: string;
    }>({
        body: '',
        media: [],
        idempotency_key: generateIdempotencyKey(),
    });

    useEffect(() => {
        composerBodyRef.current = data.body;
        composerMediaRef.current = data.media;
    }, [data.body, data.media]);

    useEffect(() => {
        setMessageSearch('');
        setActiveMatchIndex(0);
    }, [conversation.external_id]);

    const normalizedMessageSearch = messageSearch.trim().toLocaleLowerCase();
    const searchMatches = useMemo(() => {
        if (normalizedMessageSearch === '') {
            return [];
        }

        return messages
            .filter((message) => (message.body ?? '').toLocaleLowerCase().includes(normalizedMessageSearch))
            .map((message) => message.id);
    }, [messages, normalizedMessageSearch]);

    useLayoutEffect(() => {
        setActiveMatchIndex(searchMatches.length > 0 ? searchMatches.length - 1 : 0);
    }, [searchMatches]);

    const activeMatchPosition = searchMatches.length === 0 ? 0 : Math.min(activeMatchIndex, searchMatches.length - 1);

    useEffect(() => {
        if (searchMatches.length === 0) {
            return;
        }

        const activeMessageId = searchMatches[activeMatchPosition];

        messageRefs.current[activeMessageId]?.scrollIntoView({
            behavior: 'smooth',
            block: 'center',
        });
    }, [activeMatchPosition, searchMatches]);

    useLayoutEffect(() => {
        const textarea = textareaRef.current;

        if (!textarea) {
            return;
        }

        textarea.style.height = 'auto';
        textarea.style.height = `${Math.min(textarea.scrollHeight, COMPOSER_TEXTAREA_MAX_HEIGHT)}px`;
        textarea.style.overflowY = textarea.scrollHeight > COMPOSER_TEXTAREA_MAX_HEIGHT ? 'auto' : 'hidden';
    }, [data.body]);

    const latestMessage = messages.length > 0 ? messages[messages.length - 1] : null;
    const latestMessageKey = latestMessage ? `${latestMessage.id}:${latestMessage.sent_at ?? latestMessage.received_at ?? latestMessage.created_at}` : null;
    const messageRenderItems = useMemo(
        () => groupedMessageRenderItems(messages, firstUnreadMessageId),
        [firstUnreadMessageId, messages],
    );
    const imageGallery = useMemo(
        () => imagePreview ? contiguousImageGallery(messages, imagePreview.id) : [],
        [imagePreview, messages],
    );
    const activeImageIndex = imagePreview ? imageGallery.findIndex((image) => image.id === imagePreview.id) : -1;

    useLayoutEffect(() => {
        const scrollContainer = scrollContainerRef.current;

        if (!scrollContainer) {
            return;
        }

        const chatChanged = previousChatIdRef.current !== conversation.external_id;
        const latestMessageChanged = previousLatestMessageKeyRef.current !== latestMessageKey;

        if (isLoadingOlderRef.current) {
            scrollContainer.scrollTop = scrollContainer.scrollHeight - previousScrollHeightRef.current + previousScrollTopRef.current;
            isLoadingOlderRef.current = false;
            setIsLoadingOlder(false);
        } else if (chatChanged || latestMessageChanged) {
            scrollContainer.scrollTop = scrollContainer.scrollHeight;
        }

        previousChatIdRef.current = conversation.external_id;
        previousLatestMessageKeyRef.current = latestMessageKey;
    }, [conversation.external_id, latestMessageKey, messages.length]);

    const loadOlderMessages = () => {
        const scrollContainer = scrollContainerRef.current;

        if (!scrollContainer || !hasMoreMessages || isLoadingOlderRef.current) {
            return;
        }

        isLoadingOlderRef.current = true;
        previousScrollHeightRef.current = scrollContainer.scrollHeight;
        previousScrollTopRef.current = scrollContainer.scrollTop;
        setIsLoadingOlder(true);

        router.get(
            '/whatsapp/conversations',
            {
                chat: conversation.external_id,
                chat_search: filters.chat_search,
                message_limit: Math.min(messageLimit + MESSAGE_LIMIT_INCREMENT, MAX_MESSAGE_LIMIT),
            },
            {
                only: INBOX_RELOAD_PROPS,
                preserveScroll: true,
                preserveState: true,
                replace: true,
                onError: () => {
                    isLoadingOlderRef.current = false;
                    setIsLoadingOlder(false);
                },
            },
        );
    };

    const handleMessagesScroll = () => {
        const scrollContainer = scrollContainerRef.current;

        if (scrollContainer && scrollContainer.scrollTop <= 80) {
            loadOlderMessages();
        }
    };

    const submitMessage = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        sendMessage();
    };

    const sendMessage = () => {
        const submittedBody = data.body;
        const submittedMedia = data.media;
        const submittedKey = data.idempotency_key;

        if (submittedBody.trim() === '' && submittedMedia.length === 0) {
            return;
        }

        clearErrors();
        setSendProgress(null);
        composerBodyRef.current = '';
        composerMediaRef.current = [];
        setData({
            body: '',
            media: [],
            idempotency_key: generateIdempotencyKey(),
        });

        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }

        router.post(`/whatsapp/conversations/${conversation.id}/messages`, {
            body: submittedBody,
            media: submittedMedia,
            idempotency_key: submittedKey,
        }, {
            async: true,
            forceFormData: true,
            preserveScroll: true,
            onProgress: (progress: UploadProgress) => {
                if (submittedMedia.length === 0) {
                    return;
                }

                setSendProgress(progress?.percentage ?? null);
            },
            onSuccess: () => {
                clearErrors();
            },
            onError: (submitErrors: MessageSubmitErrors) => {
                setError(submitErrors);

                if (composerBodyRef.current.trim() === '' && composerMediaRef.current.length === 0) {
                    composerBodyRef.current = submittedBody;
                    composerMediaRef.current = submittedMedia;
                    setData({
                        body: submittedBody,
                        media: submittedMedia,
                        idempotency_key: submittedKey,
                    });
                    return;
                }

                toast.error('No se pudo enviar el mensaje anterior. No reemplacé lo que estás escribiendo.');
            },
            onFinish: () => setSendProgress(null),
        });
    };

    const handleComposerKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if (event.key !== 'Enter' || event.shiftKey || event.nativeEvent.isComposing) {
            return;
        }

        event.preventDefault();
        sendMessage();
    };

    const addFiles = (incomingFiles: File[]) => {
        if (incomingFiles.length === 0) {
            return;
        }

        const nextFiles = [...data.media, ...incomingFiles];

        if (nextFiles.length > MAX_FILES_PER_SEND) {
            toast.error('Podés enviar hasta 3 archivos.');
            return;
        }

        if (incomingFiles.some((file) => file.size > MAX_FILE_BYTES)) {
            toast.error('Cada archivo puede pesar hasta 25 MB.');
            return;
        }

        if (nextFiles.reduce((total, file) => total + file.size, 0) > MAX_TOTAL_FILE_BYTES) {
            toast.error('Los archivos no pueden superar 50 MB.');
            return;
        }

        clearErrors('media');
        composerMediaRef.current = nextFiles;
        setData('media', nextFiles);
    };

    const removeFile = (index: number) => {
        const nextFiles = data.media.filter((_, fileIndex) => fileIndex !== index);

        composerMediaRef.current = nextFiles;
        setData('media', nextFiles);

        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    };

    const handleFileInputChange = (files: FileList | null) => {
        addFiles(Array.from(files ?? []));

        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    };

    const handleDrop = (event: DragEvent<HTMLElement>) => {
        event.preventDefault();
        setIsDraggingFiles(false);
        addFiles(Array.from(event.dataTransfer.files ?? []));
    };

    const handlePaste = (event: ClipboardEvent<HTMLTextAreaElement>) => {
        const files = Array.from(event.clipboardData.files ?? []);

        if (files.length === 0) {
            return;
        }

        addFiles(files);
    };

    const goToPreviousMatch = () => {
        if (searchMatches.length === 0) {
            return;
        }

        setActiveMatchIndex((index) => (index - 1 + searchMatches.length) % searchMatches.length);
    };

    const goToNextMatch = () => {
        if (searchMatches.length === 0) {
            return;
        }

        setActiveMatchIndex((index) => (index + 1) % searchMatches.length);
    };

    const showGalleryImage = (index: number) => {
        if (imageGallery.length === 0) {
            return;
        }

        setImagePreview(imageGallery[(index + imageGallery.length) % imageGallery.length]);
    };

    return (
        <>
            <header className="app-surface border-b p-3 px-5">
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <h2 className="text-lg font-semibold">{conversationTitle(conversation)}</h2>
                        {conversation.display_description ? <p className="app-muted mt-0.5 truncate text-sm">{conversation.display_description}</p> : null}
                    </div>

                    <div className="flex items-center gap-3">
                        <SearchForm
                            placeholder="Buscar mensajes"
                            value={messageSearch}
                            onChange={setMessageSearch}
                            onSubmit={goToNextMatch}
                            onClear={() => {
                                setMessageSearch('');
                                setActiveMatchIndex(0);
                            }}
                            actions={
                                <>
                                    {normalizedMessageSearch !== '' ? (
                                        <span className="app-faint text-xs">
                                            {searchMatches.length === 0 ? '0' : `${activeMatchPosition + 1}/${searchMatches.length}`}
                                        </span>
                                    ) : null}
                                    <button type="button" onClick={goToPreviousMatch} disabled={searchMatches.length === 0} className="app-muted rounded-md p-1 transition hover:bg-(--app-control-hover) hover:text-(--app-accent) disabled:cursor-not-allowed disabled:opacity-40" aria-label="Resultado anterior">
                                        <ChevronUp className="size-4" />
                                    </button>
                                    <button type="button" onClick={goToNextMatch} disabled={searchMatches.length === 0} className="app-muted rounded-md p-1 transition hover:bg-(--app-control-hover) hover:text-(--app-accent) disabled:cursor-not-allowed disabled:opacity-40" aria-label="Resultado siguiente">
                                        <ChevronDown className="size-4" />
                                    </button>
                                </>
                            }
                            className="w-72"
                        />
                    </div>
                </div>
            </header>

            <div
                ref={scrollContainerRef}
                onScroll={handleMessagesScroll}
                onDragEnter={(event) => {
                    if (event.dataTransfer.types.includes('Files')) {
                        setIsDraggingFiles(true);
                    }
                }}
                onDragOver={(event) => {
                    if (event.dataTransfer.types.includes('Files')) {
                        event.preventDefault();
                    }
                }}
                onDragLeave={(event) => {
                    if (!event.currentTarget.contains(event.relatedTarget as Node | null)) {
                        setIsDraggingFiles(false);
                    }
                }}
                onDrop={handleDrop}
                className={`app-chat-shell min-h-0 flex-1 overflow-y-auto p-5 transition-all ${isDraggingFiles ? 'app-drag-active' : ''}`}
                style={CHAT_BACKGROUND_STYLE}
            >
                {messages.length === 0 ? (
                    <p className="app-surface-soft rounded-xl border p-4 text-sm">
                        No hay mensajes para este chat.
                    </p>
                ) : (
                    <div className="space-y-2">
                        {normalizedMessageSearch !== '' ? (
                            <p className="app-faint text-center text-xs">Buscá en los mensajes cargados.</p>
                        ) : null}
                        {hasMoreMessages ? (
                            <div className="flex justify-center pb-2">
                                <button
                                    type="button"
                                    onClick={loadOlderMessages}
                                    disabled={isLoadingOlder}
                                    className="app-button rounded-full border px-3 py-1 text-xs font-medium transition disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {isLoadingOlder ? 'Cargando mensajes anteriores...' : 'Cargar mensajes anteriores'}
                                </button>
                            </div>
                        ) : null}
                        {firstUnreadMessageId !== null && !messages.some((message) => message.id === firstUnreadMessageId) ? <UnreadDivider text="Hay mensajes no leídos más arriba" /> : null}
                        {messageRenderItems.map((item) => {
                            if (item.type === 'divider') {
                                return <UnreadDivider key={item.key} text={item.text} />;
                            }

                            if (item.type === 'image-group') {
                                return (
                                    <ImageGroupBubble
                                        key={item.key}
                                        messages={item.messages}
                                        onOpenImage={setImagePreview}
                                        refCallback={(messageId, element) => {
                                            messageRefs.current[messageId] = element;
                                        }}
                                    />
                                );
                            }

                            return (
                                <MessageBubble
                                    key={item.message.id}
                                    message={item.message}
                                    onOpenImage={setImagePreview}
                                    searchQuery={messageSearch}
                                    activeSearchMatch={searchMatches[activeMatchPosition] === item.message.id}
                                    refCallback={(element) => {
                                        messageRefs.current[item.message.id] = element;
                                    }}
                                />
                            );
                        })}
                    </div>
                )}
            </div>

            <footer className="app-surface m-3 rounded-b-3xl rounded-t-3xl border p-1">
                <form onSubmit={submitMessage} className="space-y-2">
                    <input type="hidden" value={data.idempotency_key} readOnly />
                    <input
                        ref={fileInputRef}
                        type="file"
                        className="hidden"
                        multiple
                        onChange={(event) => handleFileInputChange(event.target.files)}
                    />
                    {data.media.length > 0 ? (
                        <div className="ml-14 flex max-w-[75%] flex-wrap gap-2">
                            {data.media.map((file, index) => (
                                <div key={`${file.name}-${file.size}-${index}`} className="flex max-w-full items-center gap-2 rounded-full bg-(--app-surface-strong) px-3 py-1 text-xs">
                                    <Paperclip className="size-3.5 shrink-0" />
                                    <span className="truncate">{file.name}</span>
                                    <span className="app-muted shrink-0">{formatBytes(file.size)}</span>
                                    <button
                                        type="button"
                                        onClick={() => removeFile(index)}
                                        className="rounded-full p-0.3 transition hover:bg-(--app-control-hover)"
                                        aria-label="Quitar adjunto"
                                    >
                                        <X className="size-3.5" />
                                    </button>
                                </div>
                            ))}
                        </div>
                    ) : null}
                    <div className="flex items-end gap-2">
                        <button
                            type="button"
                            onClick={() => fileInputRef.current?.click()}
                            className="app-button-primary grid size-10 shrink-0 place-items-center rounded-full transition"
                            aria-label="Adjuntar archivo"
                        >
                            <Paperclip className="size-4" />
                        </button>
                        <label className="sr-only" htmlFor="message-body">
                            Mensaje
                        </label>
                        <div className="flex min-h-10 flex-1 items-end">
                            <textarea
                                ref={textareaRef}
                                id="message-body"
                                value={data.body}
                                onChange={(event) => {
                                    composerBodyRef.current = event.target.value;
                                    setData('body', event.target.value);
                                }}
                                onKeyDown={handleComposerKeyDown}
                                onPaste={handlePaste}
                                rows={1}
                                maxLength={4000}
                                placeholder={data.media.length > 0 ? 'Agregá un comentario' : 'Escribí un mensaje'}
                                className=" max-h-36 min-h-10 flex-1 resize-none px-2 pt-3 pb-1.5 text-sm leading-5 outline-none transition"
                            />
                        </div>
                        <button
                            type="submit"
                            disabled={data.body.trim() === '' && data.media.length === 0}
                            className="app-button-primary grid size-10 shrink-0 place-items-center rounded-full transition disabled:cursor-not-allowed disabled:opacity-50"
                            aria-label="Enviar mensaje"
                        >
                            <Send className="size-4" />
                        </button>
                    </div>
                    {sendProgress !== null ? <p className="app-muted ml-14 text-xs">Subiendo archivos… {sendProgress}%</p> : null}
                    {errors.body ? <p className="mt-2 text-sm text-(--app-danger)">{errors.body}</p> : null}
                    {errors.media ? <p className="mt-2 text-sm text-(--app-danger)">{errors.media}</p> : null}
                    {errors.idempotency_key ? <p className="mt-2 text-sm text-(--app-danger)">{errors.idempotency_key}</p> : null}
                </form>
            </footer>
            {imagePreview ? (
                <ImageViewerModal
                    image={imagePreview}
                    positionLabel={activeImageIndex >= 0 ? `${activeImageIndex + 1}/${imageGallery.length}` : null}
                    canNavigate={imageGallery.length > 1}
                    onPrevious={() => showGalleryImage(activeImageIndex - 1)}
                    onNext={() => showGalleryImage(activeImageIndex + 1)}
                    onClose={() => setImagePreview(null)}
                />
            ) : null}
        </>
    );
}

function isGalleryImage(message: MessageItem): boolean {
    return message.type === 'image' && message.media_url !== null;
}

function contiguousImageGallery(messages: MessageItem[], imageId: number): ImagePreview[] {
    const clickedIndex = messages.findIndex((message) => message.id === imageId);

    if (clickedIndex === -1 || !isGalleryImage(messages[clickedIndex])) {
        return [];
    }

    let startIndex = clickedIndex;
    let endIndex = clickedIndex;

    while (startIndex > 0 && isGalleryImage(messages[startIndex - 1])) {
        startIndex -= 1;
    }

    while (endIndex < messages.length - 1 && isGalleryImage(messages[endIndex + 1])) {
        endIndex += 1;
    }

    return messages.slice(startIndex, endIndex + 1).map((message) => ({
        id: message.id,
        url: message.media_url!,
        filename: message.media_filename ?? 'imagen',
    }));
}

function groupedMessageRenderItems(messages: MessageItem[], firstUnreadMessageId: number | null): MessageRenderItem[] {
    const items: MessageRenderItem[] = [];
    let index = 0;

    while (index < messages.length) {
        const message = messages[index];

        if (message.id === firstUnreadMessageId) {
            items.push({ type: 'divider', key: `unread-${message.id}`, text: 'Mensajes no leídos' });
        }

        if (!isGalleryImage(message)) {
            items.push({ type: 'message', message });
            index += 1;
            continue;
        }

        const imageMessages: MessageItem[] = [message];
        let nextIndex = index + 1;

        while (nextIndex < messages.length && isGalleryImage(messages[nextIndex]) && messages[nextIndex].id !== firstUnreadMessageId) {
            imageMessages.push(messages[nextIndex]);
            nextIndex += 1;
        }

        if (imageMessages.length === 1) {
            items.push({ type: 'message', message });
        } else {
            items.push({ type: 'image-group', key: `images-${imageMessages.map((imageMessage) => imageMessage.id).join('-')}`, messages: imageMessages });
        }

        index = nextIndex;
    }

    return items;
}

function UnreadDivider({ text }: { text: string }) {
    return (
        <div className="my-3 flex items-center gap-3" role="separator" aria-label={text}>
            <span className="app-accent-line h-px flex-1" />
            <span className="app-unread-chip rounded-full border px-3 py-1 text-[11px] font-semibold shadow-sm">
                {text}
            </span>
            <span className="app-accent-line h-px flex-1" />
        </div>
    );
}

function ImageGroupBubble({ messages, onOpenImage, refCallback }: { messages: MessageItem[]; onOpenImage: (image: ImagePreview) => void; refCallback: (messageId: number, element: HTMLElement | null) => void }) {
    if (messages.length === 0) {
        return null;
    }

    const fromMe = messages[0]?.direction === 'outbound';
    const lastMessage = messages[messages.length - 1];
    const timestamp = lastMessage.sent_at ?? lastMessage.received_at ?? lastMessage.created_at;
    const visibleMessages = messages.slice(0, 4);
    const hiddenCount = messages.length - visibleMessages.length;

    return (
        <article className={`flex min-w-0 ${fromMe ? 'justify-end' : 'justify-start'}`}>
            <div
                ref={(element) => {
                    messages.forEach((message) => refCallback(message.id, element));
                }}
                className={`app-message-bubble relative min-w-0 max-w-[70%] rounded-xl border p-1.5 shadow-sm transition ${fromMe ? 'app-message-bubble-out' : ''}`}
            >
                <div className={`app-image-group app-image-group-${Math.min(messages.length, 4)}`}>
                    {visibleMessages.map((message, index) => {
                        const isLastVisible = index === visibleMessages.length - 1;

                        return (
                            <button
                                key={message.id}
                                type="button"
                                onClick={() => onOpenImage({ id: message.id, url: message.media_url!, filename: message.media_filename ?? 'imagen' })}
                                className={`app-image-group-item ${messages.length === 3 && index === 0 ? 'app-image-group-item-large' : ''}`}
                                aria-label="Abrir imagen"
                            >
                                <img src={message.media_url!} alt={message.media_filename ?? 'Imagen adjunta'} loading="lazy" />
                                {hiddenCount > 0 && isLastVisible ? (
                                    <span className="app-image-group-more">+{hiddenCount}</span>
                                ) : null}
                            </button>
                        );
                    })}
                </div>
                <p className="app-muted mt-1 flex items-center justify-end gap-1 px-1 text-[11px]">
                    <time>{formatMessageTimestamp(timestamp)}</time>
                    <MessageStatusIndicator status={lastMessage.status} />
                </p>
            </div>
        </article>
    );
}

function MessageBubble({ message, onOpenImage, searchQuery, activeSearchMatch, refCallback }: { message: MessageItem; onOpenImage: (image: ImagePreview) => void; searchQuery: string; activeSearchMatch: boolean; refCallback: (element: HTMLElement | null) => void }) {
    const fromMe = message.direction === 'outbound';
    const timestamp = message.sent_at ?? message.received_at ?? message.created_at;
    const bubbleRef = useRef<HTMLDivElement>(null);
    const [actionsOpen, setActionsOpen] = useState(false);
    const [actionMode, setActionMode] = useState<'list' | 'edit' | 'delete'>('list');
    const [editBody, setEditBody] = useState(message.body ?? '');
    const [processingMutation, setProcessingMutation] = useState(false);
    const [messageExpanded, setMessageExpanded] = useState(false);
    const [audioTimeLabel, setAudioTimeLabel] = useState<string | null>(() => message.type === 'audio' ? formatAudioTime(audioMetadataDuration(message.media_metadata)) : null);
    const deleted = message.deleted_at !== null;
    const canShowMenu = !deleted && (message.can_edit || message.can_delete);

    useCloseOnOutsidePointer(bubbleRef, actionsOpen, () => closeActions());

    useEffect(() => {
        if (actionMode !== 'edit') {
            setEditBody(message.body ?? '');
        }
    }, [actionMode, message.body]);

    useEffect(() => {
        setAudioTimeLabel(message.type === 'audio' ? formatAudioTime(audioMetadataDuration(message.media_metadata)) : null);
    }, [message.media_metadata, message.type]);

    const closeActions = () => {
        setActionsOpen(false);
        setActionMode('list');
        setEditBody(message.body ?? '');
    };

    const submitEdit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (processingMutation || editBody.trim() === '') {
            return;
        }

        setProcessingMutation(true);

        router.patch(
            `/whatsapp/messages/${message.id}`,
            { body: editBody },
            {
                preserveScroll: true,
                onSuccess: () => {
                    closeActions();
                },
                onFinish: () => setProcessingMutation(false),
            },
        );
    };

    const deleteMessage = () => {
        if (processingMutation) {
            return;
        }

        setProcessingMutation(true);

        router.delete(`/whatsapp/messages/${message.id}`, {
            preserveScroll: true,
            onSuccess: closeActions,
            onFinish: () => setProcessingMutation(false),
        });
    };

    return (
        <article className={`flex min-w-0 ${fromMe ? 'justify-end' : 'justify-start'}`}>
            <div
                ref={(element) => {
                    bubbleRef.current = element;
                    refCallback(element);
                }}
                className={`app-message-bubble group relative min-w-0 max-w-[70%] overflow-visible rounded-xl border px-3 py-2 shadow-sm transition ${fromMe ? 'app-message-bubble-out' : ''}`}
            >
                {canShowMenu ? (
                    <div className="absolute top-1 right-1">
                        <button
                            type="button"
                            onClick={() => {
                                setActionsOpen(true);
                                setActionMode('list');
                            }}
                            className="app-muted grid size-7 place-items-center rounded-full opacity-70 transition hover:bg-(--app-control-hover) hover:text-(--app-accent) hover:opacity-100 group-hover:opacity-100"
                            aria-label="Opciones del mensaje"
                        >
                            <MoreVertical className="size-4" />
                        </button>
                    </div>
                ) : null}

                {deleted ? (
                    <p className="app-message-text app-muted pr-6 text-sm leading-6 italic">Mensaje eliminado</p>
                ) : (
                    <>
                        <MessageMedia message={message} onAudioTimeLabelChange={setAudioTimeLabel} onOpenImage={onOpenImage} />
                        {message.body ? (
                            <MessageBody
                                text={message.body}
                                searchQuery={searchQuery}
                                activeSearchMatch={activeSearchMatch}
                                expanded={messageExpanded}
                                onToggleExpanded={() => setMessageExpanded((current) => !current)}
                            />
                        ) : message.type === 'text' ? (
                            <p className="app-message-text app-muted pr-6 text-sm leading-6 italic">Mensaje sin contenido</p>
                        ) : null}
                    </>
                )}
                <p className={`app-muted mt-1 flex items-center text-[11px] ${message.type === 'audio' ? 'justify-between gap-4' : 'justify-end gap-1'}`}>
                    {message.type === 'audio' && audioTimeLabel !== null ? <span className="shrink-0 tabular-nums">{audioTimeLabel}</span> : null}
                    <span className="flex min-w-0 items-center justify-end gap-1">
                        <time>{formatMessageTimestamp(timestamp)}</time>
                        <MessageStatusIndicator status={message.status} />
                        {message.edited_at && !deleted ? ' · Editado' : ''}
                    </span>
                </p>
                {message.status === 'failed' && message.error_message ? (
                    <p className="app-message-text app-alert-danger mt-1 rounded-lg border px-2 py-1 text-xs leading-5">
                        {message.error_message}
                    </p>
                ) : null}
                {message.remote_edit_status === 'failed' && message.edit_error ? (
                    <p className="app-message-text app-alert-danger mt-1 rounded-lg border px-2 py-1 text-xs leading-5">Edición fallida: {message.edit_error}</p>
                ) : null}
                {message.remote_delete_status === 'failed' && message.delete_error ? (
                    <p className="app-message-text app-alert-danger mt-1 rounded-lg border px-2 py-1 text-xs leading-5">Eliminación fallida: {message.delete_error}</p>
                ) : null}
                {actionsOpen ? (
                    <MessageActionsPanel
                        message={message}
                        mode={actionMode}
                        editBody={editBody}
                        processing={processingMutation}
                        onModeChange={setActionMode}
                        onEditBodyChange={setEditBody}
                        onSubmitEdit={submitEdit}
                        onDelete={deleteMessage}
                        onClose={closeActions}
                    />
                ) : null}
            </div>
        </article>
    );
}

function MessageBody({ text, searchQuery, activeSearchMatch, expanded, onToggleExpanded }: { text: string; searchQuery: string; activeSearchMatch: boolean; expanded: boolean; onToggleExpanded: () => void }) {
    const words = text.trim().split(/\s+/).filter(Boolean);
    const shouldTruncate = !activeSearchMatch && (words.length > COLLAPSED_MESSAGE_WORD_LIMIT || text.length > COLLAPSED_MESSAGE_CHAR_LIMIT);

    return (
        <div className="pr-6">
            <p className={`app-message-text text-sm leading-6 ${shouldTruncate && !expanded ? 'app-message-text-collapsed' : ''}`}>
                {activeSearchMatch ? <HighlightedText text={text} query={searchQuery} /> : text}
            </p>
            {shouldTruncate ? (
                <button
                    type="button"
                    onClick={onToggleExpanded}
                    className="app-read-more mt-1 text-xs font-semibold transition hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-(--app-focus) focus-visible:ring-offset-2 focus-visible:ring-offset-(--app-chat-in)"
                >
                    {expanded ? 'Leer menos' : 'Leer más'}
                </button>
            ) : null}
        </div>
    );
}

function MessageActionsPanel({
    message,
    mode,
    editBody,
    processing,
    onModeChange,
    onEditBodyChange,
    onSubmitEdit,
    onDelete,
    onClose,
}: {
    message: MessageItem;
    mode: 'list' | 'edit' | 'delete';
    editBody: string;
    processing: boolean;
    onModeChange: (mode: 'list' | 'edit' | 'delete') => void;
    onEditBodyChange: (value: string) => void;
    onSubmitEdit: (event: FormEvent<HTMLFormElement>) => void;
    onDelete: () => void;
    onClose: () => void;
}) {
    return (
        <div className="absolute right-1 bottom-[calc(100%+0.5rem)] z-20 w-60 max-w-[calc(100vw-2rem)]" role="dialog" aria-label="Opciones del mensaje">
            <div className="app-surface rounded-2xl border p-2 text-sm shadow-2xl shadow-black/20">
                <div className="mb-3 flex items-center justify-between gap-3">
                    <h3 className="font-semibold pl-1">Opciones del mensaje</h3>
                    <button type="button" onClick={onClose} className="app-muted grid size-8 place-items-center rounded-full transition hover:bg-(--app-control-hover) hover:text-(--app-accent)" aria-label="Cerrar opciones">
                        <X className="size-4" />
                    </button>
                </div>

                {mode === 'list' ? (
                    <div className="space-y-1">
                        {message.can_edit ? (
                            <button type="button" onClick={() => onModeChange('edit')} className="flex w-full items-center gap-3 rounded-xl px-2 py-2 text-left transition hover:bg-(--app-control-hover)">
                                <Pencil className="size-3 text-(--app-accent)" />
                                <span>Editar</span>
                            </button>
                        ) : null}
                        {message.can_delete ? (
                            <button type="button" onClick={() => onModeChange('delete')} className="flex w-full items-center gap-3 rounded-xl px-2 py-2 text-left text-(--app-danger) transition hover:bg-(--app-danger-bg)">
                                <Trash2 className="size-3" />
                                <span>Eliminar</span>
                            </button>
                        ) : null}
                    </div>
                ) : null}

                {mode === 'edit' ? (
                    <form onSubmit={onSubmitEdit} className="space-y-3">
                        <textarea
                            value={editBody}
                            onChange={(event) => onEditBodyChange(event.target.value)}
                            maxLength={4000}
                            rows={5}
                            className="app-input-control h-32 w-full resize-none rounded-xl border border-(--app-control-border) px-3 py-2 text-sm outline-none focus:border-(--app-focus)"
                        />
                        <div className="flex justify-end gap-2 text-xs font-semibold items-center">
                            <p className="app-faint text-right text-xs">{editBody.length}/2000</p>
                            <button type="button" onClick={() => onModeChange('list')} className="app-muted rounded-full px-2 py-1 transition hover:bg-(--app-control-hover)">
                                Cancelar
                            </button>
                            <button type="submit" disabled={processing || editBody.trim() === ''} className="app-button-primary rounded-full px-2 py-1 transition disabled:opacity-50">
                                Guardar
                            </button>
                        </div>
                    </form>
                ) : null}

                {mode === 'delete' ? (
                    <div className="app-alert-danger space-y-4 rounded-xl border p-4">
                        <p className="text-sm">¿Eliminar este mensaje?</p>
                        <div className="flex justify-end gap-2 text-xs font-semibold">
                            <button type="button" onClick={() => onModeChange('list')} className="rounded-full px-2 py-1 transition hover:bg-(--app-control-hover)">
                                Cancelar
                            </button>
                            <button type="button" onClick={onDelete} disabled={processing} className="rounded-full bg-(--app-danger) px-2 py-1 text-white transition disabled:opacity-50">
                                Eliminar
                            </button>
                        </div>
                    </div>
                ) : null}
            </div>
        </div>
    );
}

function HighlightedText({ text, query }: { text: string; query: string }) {
    const needle = query.trim();

    if (needle === '') {
        return text;
    }

    const parts = text.split(new RegExp(`(${escapeRegExp(needle)})`, 'gi'));

    return parts.map((part, index) => part.toLocaleLowerCase() === needle.toLocaleLowerCase() ? (
        <mark key={`${part}-${index}`} className="app-search-highlight rounded px-0.5">
            {part}
        </mark>
    ) : part);
}

function MessageMedia({ message, onAudioTimeLabelChange, onOpenImage }: { message: MessageItem; onAudioTimeLabelChange?: (label: string) => void; onOpenImage: (image: ImagePreview) => void }) {
    if (message.type === 'text') {
        return null;
    }

    if (!message.media_url) {
        const status = message.media_download_status ? translateMediaStatus(message.media_download_status) : 'pendiente';
        const mediaError = message.media_error ?? unavailableMediaLabel(message.type);

        return (
            <div className="app-surface-soft mb-1 rounded-lg border px-3 py-2 text-sm">
                <p>{mediaError ?? mediaLabel(message.type)}</p>
                <p className="app-muted mt-0.5 text-xs">Estado: {status}</p>
            </div>
        );
    }

    if (message.type === 'image') {
        return (
            <button
                type="button"
                onClick={() => onOpenImage({ id: message.id, url: message.media_url!, filename: message.media_filename ?? 'imagen' })}
                className="mb-2 block overflow-hidden rounded-lg text-left"
                aria-label="Abrir imagen"
            >
                <img src={message.media_url} alt={message.media_filename ?? 'Imagen adjunta'} className="max-h-80 object-contain transition hover:brightness-110" loading="lazy" />
            </button>
        );
    }

    if (message.type === 'video') {
        return (
            <video controls className="mb-2 max-h-80 rounded-lg">
                <source src={message.media_url} type={message.media_mime_type ?? undefined} />
            </video>
        );
    }

    if (message.type === 'audio') {
        return <AudioMessagePlayer message={message} onTimeLabelChange={onAudioTimeLabelChange} />;
    }

    return (
        <a href={message.media_url} target="_blank" rel="noreferrer" className="app-surface-soft mb-2 flex items-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium transition hover:bg-(--app-control-hover)">
            <Paperclip className="size-4" />
            <span className="truncate">{message.media_filename ?? 'Documento adjunto'}</span>
            {message.media_size_bytes ? <span className="app-muted shrink-0 text-xs">{formatBytes(message.media_size_bytes)}</span> : null}
        </a>
    );
}

function AudioMessagePlayer({ message, onTimeLabelChange }: { message: MessageItem; onTimeLabelChange?: (label: string) => void }) {
    const audioRef = useRef<HTMLAudioElement>(null);
    const [isPlaying, setIsPlaying] = useState(false);
    const [duration, setDuration] = useState(() => audioMetadataDuration(message.media_metadata));
    const [currentTime, setCurrentTime] = useState(0);
    const progress = duration > 0 ? Math.min(currentTime / duration, 1) : 0;

    useEffect(() => {
        setIsPlaying(false);
        setDuration(audioMetadataDuration(message.media_metadata));
        setCurrentTime(0);
    }, [message.media_metadata, message.media_url]);

    useEffect(() => {
        const audio = audioRef.current;

        return () => {
            audio?.pause();
        };
    }, []);

    useEffect(() => {
        onTimeLabelChange?.(formatAudioTime(isPlaying ? currentTime : duration));
    }, [currentTime, duration, isPlaying, onTimeLabelChange]);

    const updateDuration = (audio: HTMLAudioElement) => {
        if (Number.isFinite(audio.duration) && audio.duration > 0) {
            setDuration(audio.duration);
        }
    };

    const togglePlayback = () => {
        const audio = audioRef.current;

        if (!audio) {
            return;
        }

        if (audio.paused) {
            document.querySelectorAll<HTMLAudioElement>('audio[data-chat-audio="true"]').forEach((otherAudio) => {
                if (otherAudio !== audio) {
                    otherAudio.pause();
                }
            });

            void audio.play();
            return;
        }

        audio.pause();
    };

    return (
        <div className="app-audio-player mb-1">
            <audio
                data-chat-audio="true"
                ref={audioRef}
                preload="metadata"
                onLoadedMetadata={(event) => updateDuration(event.currentTarget)}
                onLoadedData={(event) => updateDuration(event.currentTarget)}
                onDurationChange={(event) => updateDuration(event.currentTarget)}
                onTimeUpdate={(event) => setCurrentTime(event.currentTarget.currentTime)}
                onPlay={() => setIsPlaying(true)}
                onPause={() => setIsPlaying(false)}
                onEnded={() => {
                    setIsPlaying(false);
                    setCurrentTime(0);
                }}
            >
                <source src={message.media_url!} type={message.media_mime_type ?? undefined} />
            </audio>
            <button type="button" onClick={togglePlayback} className="app-audio-button" aria-label={isPlaying ? 'Pausar audio' : 'Reproducir audio'}>
                {isPlaying ? <Pause className="size-4" /> : <Play className="size-4 translate-x-px" />}
            </button>
            <div className="min-w-0 flex-1">
                <div className="app-audio-waveform" aria-hidden="true">
                    {Array.from({ length: 24 }, (_, index) => (
                        <span
                            key={index}
                            className={index / 23 <= progress ? 'app-audio-bar app-audio-bar-active' : 'app-audio-bar'}
                            style={{ height: `${6 + ((index * 7) % 14)}px` }}
                        />
                    ))}
                </div>
            </div>
        </div>
    );
}

function ImageViewerModal({ image, positionLabel, canNavigate, onPrevious, onNext, onClose }: { image: ImagePreview; positionLabel: string | null; canNavigate: boolean; onPrevious: () => void; onNext: () => void; onClose: () => void }) {
    const [scale, setScale] = useState(1);
    const [position, setPosition] = useState({ x: 0, y: 0 });
    const dragStartRef = useRef<{ pointerId: number; x: number; y: number; positionX: number; positionY: number } | null>(null);

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

        return () => window.removeEventListener('keydown', handleViewerKeyDown, true);
    }, [canNavigate, onClose, onNext, onPrevious]);

    const zoom = (direction: 'in' | 'out') => {
        setScale((currentScale) => {
            const nextScale = direction === 'in' ? Math.min(currentScale + 0.25, 4) : Math.max(currentScale - 0.25, 1);

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
        <div className="theme-preserve-dark fixed inset-0 z-50 flex flex-col bg-black/90 text-white" role="dialog" aria-modal="true" aria-label="Vista ampliada de imagen">
            <header className="flex items-center justify-between gap-3 border-b border-white/10 px-4 py-3">
                <div className="min-w-0">
                    <p className="truncate text-sm font-medium">{image.filename}</p>
                    {positionLabel ? <p className="mt-0.5 text-xs text-zinc-400">{positionLabel}</p> : null}
                </div>
                <div className="flex items-center gap-2">
                    <button type="button" onClick={onPrevious} disabled={!canNavigate} className="grid size-10 place-items-center rounded-full transition hover:bg-white/10 disabled:cursor-not-allowed disabled:opacity-40" aria-label="Imagen anterior">
                        <ChevronLeft className="size-5" />
                    </button>
                    <button type="button" onClick={onNext} disabled={!canNavigate} className="grid size-10 place-items-center rounded-full transition hover:bg-white/10 disabled:cursor-not-allowed disabled:opacity-40" aria-label="Imagen siguiente">
                        <ChevronRight className="size-5" />
                    </button>
                    <a href={image.url} download={image.filename} className="grid size-10 place-items-center rounded-full transition hover:bg-white/10" aria-label="Descargar imagen">
                        <Download className="size-5" />
                    </a>
                    <button type="button" onClick={() => zoom('out')} className="grid size-10 place-items-center rounded-full transition hover:bg-white/10" aria-label="Alejar imagen">
                        <ZoomOut className="size-5" />
                    </button>
                    <button type="button" onClick={() => zoom('in')} className="grid size-10 place-items-center rounded-full transition hover:bg-white/10" aria-label="Acercar imagen">
                        <ZoomIn className="size-5" />
                    </button>
                    <button type="button" onClick={onClose} className="grid size-10 place-items-center rounded-full transition hover:bg-white/10" aria-label="Cerrar imagen">
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
                    className="max-h-full max-w-full select-none object-contain transition-transform duration-75 p-4"
                    style={{ transform: `translate(${position.x}px, ${position.y}px) scale(${scale})` }}
                />
            </div>
        </div>
    );
}

function EmptyConversation() {
    return (
        <div className="grid h-full place-items-center p-8 text-center" style={CHAT_BACKGROUND_STYLE}>
            <div className="app-surface-soft max-w-md rounded-2xl border p-8">
                <p className="text-sm leading-6">
                    Elegí un chat para ver los mensajes.
                </p>
            </div>
        </div>
    );
}

function SearchForm({
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
                <button type="button" onClick={onClear} className="app-muted text-xs font-medium transition hover:text-(--app-accent)">
                    Limpiar
                </button>
            ) : null}
            {actions}
        </form>
    );
}

function compactQuery(query: Record<string, string | number | null | undefined>): Record<string, string | number> {
    return Object.fromEntries(
        Object.entries(query).filter(([, value]) => value !== null && value !== undefined && String(value).trim() !== ''),
    ) as Record<string, string | number>;
}

function normalizeSearch(value: string): string {
    return value.trim();
}

function conversationHref(chatId: string, filters: Props['filters']): string {
    const query = new URLSearchParams();

    query.set('chat', chatId);

    if (filters.chat_search !== '') {
        query.set('chat_search', filters.chat_search);
    }

    return `/whatsapp/conversations?${query.toString()}`;
}

function conversationTitle(conversation: ConversationItem): string {
    return conversation.title ?? conversation.contact_name ?? conversation.external_id;
}

function initials(value: string): string {
    return value
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('') || '?';
}

function generateIdempotencyKey(): string {
    if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) {
        return crypto.randomUUID();
    }

    return `${Date.now()}-${Math.random().toString(36).slice(2)}`;
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
        return date.toLocaleTimeString('es-AR', {
            hour: '2-digit',
            minute: '2-digit',
        });
    }

    return date.toLocaleDateString('es-AR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    });
}

function formatMessageTimestamp(value: string | null): string {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleTimeString('es-AR', {
        hour: '2-digit',
        minute: '2-digit',
    });
}

function audioMetadataDuration(metadata: Record<string, unknown> | null): number {
    const rawDuration = metadata?.duration;

    if (typeof rawDuration === 'number' && Number.isFinite(rawDuration) && rawDuration > 0) {
        return rawDuration;
    }

    if (typeof rawDuration === 'string') {
        const parsedDuration = Number(rawDuration);

        if (Number.isFinite(parsedDuration) && parsedDuration > 0) {
            return parsedDuration;
        }
    }

    return 0;
}

function formatAudioTime(value: number): string {
    if (!Number.isFinite(value) || value <= 0) {
        return '0:00';
    }

    const totalSeconds = Math.floor(value);
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;

    return `${minutes}:${seconds.toString().padStart(2, '0')}`;
}

function MessageStatusIndicator({ status }: { status: string }) {
    const statusKey = status.toLowerCase();
    const statusLabels: Record<string, string> = {
        accepted: 'Enviado',
        delivered: 'Enviado',
        failed: 'Fallido',
        pending: 'Pendiente',
        read: 'Enviado',
        received: 'Recibido',
        seen: 'Enviado',
        sent: 'Enviado',
    };

    if (statusKey === 'pending') {
        return (
            <span title={statusLabels.pending} aria-label={statusLabels.pending} role="img" className="inline-flex size-3.5 items-center justify-center rounded-full border border-(--app-faint) text-[9px] leading-none text-(--app-faint)">
                ◷
            </span>
        );
    }

    if (statusKey === 'failed') {
        return (
            <span title={statusLabels.failed} aria-label={statusLabels.failed} role="img" className="inline-flex size-3.5 items-center justify-center rounded-full border border-(--app-danger) text-[10px] font-bold leading-none text-(--app-danger)">
                !
            </span>
        );
    }

    const label = statusLabels[statusKey] ?? status;

    return (
        <span title={label} aria-label={label} className="inline-flex items-center font-medium text-(--app-faint)">
            {label}
        </span>
    );
}

function mediaLabel(type: string): string {
    const labels: Record<string, string> = {
        image: 'Imagen',
        video: 'Video',
        audio: 'Audio',
        document: 'Documento',
    };

    return labels[type] ?? 'Archivo';
}

function unavailableMediaLabel(type: string): string {
    return type === 'audio' ? 'Audio no disponible' : 'Archivo no disponible';
}

function translateMediaStatus(status: string): string {
    const statuses: Record<string, string> = {
        stored: 'guardado',
        pending: 'pendiente',
        omitted: 'omitido',
        failed: 'falló la descarga',
    };

    return statuses[status] ?? status;
}

function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }

    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

function escapeRegExp(value: string): string {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}
