import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Download, Paperclip, Search, Send, Smile, X, ZoomIn, ZoomOut } from 'lucide-react';
import { type FormEvent, type KeyboardEvent, type PointerEvent, useEffect, useLayoutEffect, useRef, useState } from 'react';

const MESSAGE_LIMIT_INCREMENT = 50;
const MAX_MESSAGE_LIMIT = 300;
const COMPOSER_TEXTAREA_MAX_HEIGHT = 144;

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
    title: string | null;
    contact_name: string | null;
    last_message_preview: string | null;
    last_message_body: string | null;
    last_message_direction: string | null;
    last_message_at: string | null;
};

type MessageItem = {
    id: number;
    external_id: string | null;
    direction: string;
    body: string | null;
    type: 'text' | 'image' | 'video' | 'audio' | 'document' | string;
    status: string;
    media_url: string | null;
    media_mime_type: string | null;
    media_filename: string | null;
    media_size_bytes: number | null;
    media_download_status: string | null;
    sent_at: string | null;
    received_at: string | null;
    created_at: string | null;
};

type ImagePreview = {
    url: string;
    filename: string;
};

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

const INBOX_RELOAD_PROPS = ['connection', 'conversations', 'messages', 'messageLimit', 'hasMoreMessages', 'emptyState', 'filters', 'selectedChatId'];

export default function Conversations({ operator, conversations, selectedChatId, messageLimit, hasMoreMessages, messages, emptyState, filters }: Props) {
    const { auth } = usePage<Props>().props;
    const selectedConversation = conversations.find((conversation) => conversation.external_id === selectedChatId) ?? null;
    const operatorName = operator?.name ?? auth?.user?.nombre ?? auth?.user?.usuario ?? 'Operador';
    const [chatSearch, setChatSearch] = useState(filters.chat_search);
    const disconnectForm = useForm({});
    const logoutForm = useForm({});

    useEffect(() => {
        setChatSearch(filters.chat_search);
    }, [filters.chat_search]);

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
                    message_search: filters.message_search,
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
    }, [filters.chat_search, filters.message_search, selectedChatId]);

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
            <Head title="Chats WhatsApp" />

            <main className="min-h-screen bg-gray-50 p-4 text-black md:p-6">
                <section className="mx-auto flex h-[calc(100vh-3rem)] w-full max-w-7xl overflow-hidden rounded-2xl border border-gray-200 bg-white">
                    <aside className="flex w-full flex-col border-r border-gray-200 bg-white md:w-95 md:shrink-0">
                        <div className="border-b border-gray-200 bg-gray-50 p-4">
                            <div className="flex items-center justify-between gap-3">
                                <div>
                                    <h1 className="text-xl font-semibold text-black">Chats</h1>
                                    <p className="mt-1 text-xs text-gray-500">
                                        {operatorName}
                                    </p>
                                </div>

                                <div className="flex shrink-0 gap-2">
                                    <form onSubmit={disconnect}>
                                        <button
                                            type="submit"
                                            disabled={disconnectForm.processing}
                                            className="rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-black transition hover:border-red-600 hover:text-red-700 disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            Cerrar sesión
                                        </button>
                                    </form>
                                    <form onSubmit={logout}>
                                        <button
                                            type="submit"
                                            disabled={logoutForm.processing}
                                            className="rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-black transition hover:border-black disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            Salir
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <SearchForm
                                placeholder="Buscar chats o contactos"
                                value={chatSearch}
                                onChange={setChatSearch}
                                onSubmit={() => {
                                    router.get(
                                        '/whatsapp/conversations',
                                        compactQuery({
                                            chat: selectedChatId,
                                            chat_search: chatSearch,
                                            message_search: filters.message_search,
                                        }),
                                        {
                                            only: INBOX_RELOAD_PROPS,
                                            preserveScroll: true,
                                            preserveState: true,
                                            replace: true,
                                        },
                                    );
                                }}
                                onClear={() => {
                                    setChatSearch('');
                                    router.get(
                                        '/whatsapp/conversations',
                                        compactQuery({
                                            chat: selectedChatId,
                                            message_search: filters.message_search,
                                        }),
                                        {
                                            only: INBOX_RELOAD_PROPS,
                                            preserveScroll: true,
                                            preserveState: true,
                                            replace: true,
                                        },
                                    );
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
                                <p className="p-4 text-sm leading-6 text-gray-600">
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

                    <section className="hidden min-w-0 flex-1 flex-col bg-gray-50 md:flex">
                        {selectedConversation ? (
                            <MessagePanel
                                conversation={selectedConversation}
                                messages={messages}
                                messageLimit={messageLimit}
                                hasMoreMessages={hasMoreMessages}
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
    return (
        <a
            href={conversationHref(conversation.external_id, filters)}
            className={`flex gap-3 border-b border-gray-100 px-4 py-3 transition ${active ? 'bg-green-50' : 'bg-white hover:bg-gray-50'}`}
        >
            <div className="flex size-11 shrink-0 items-center justify-center rounded-full border border-gray-200 bg-gray-100 text-sm font-semibold text-gray-700">
                {initials(conversationTitle(conversation))}
            </div>

            <div className="min-w-0 flex-1">
                <div className="flex items-start justify-between gap-3">
                    <p className="truncate text-sm font-semibold text-black">{conversationTitle(conversation)}</p>
                    <time className="shrink-0 text-[11px] text-gray-500">{formatWhatsAppTimestamp(conversation.last_message_at)}</time>
                </div>

                <p className="mt-1 truncate text-sm text-gray-600">
                    {conversation.last_message_direction === 'outbound' ? 'Vos: ' : ''}
                    {conversation.last_message_preview ?? conversation.last_message_body ?? 'Sin vista previa'}
                </p>
            </div>
        </a>
    );
}

function MessagePanel({
    conversation,
    messages,
    messageLimit,
    hasMoreMessages,
    filters,
}: {
    conversation: ConversationItem;
    messages: MessageItem[];
    messageLimit: number;
    hasMoreMessages: boolean;
    filters: Props['filters'];
}) {
    const scrollContainerRef = useRef<HTMLDivElement>(null);
    const previousChatIdRef = useRef<string | null>(null);
    const previousLatestMessageKeyRef = useRef<string | null>(null);
    const isLoadingOlderRef = useRef(false);
    const previousScrollHeightRef = useRef(0);
    const previousScrollTopRef = useRef(0);
    const [isLoadingOlder, setIsLoadingOlder] = useState(false);
    const [messageSearch, setMessageSearch] = useState(filters.message_search);
    const [imagePreview, setImagePreview] = useState<ImagePreview | null>(null);
    const fileInputRef = useRef<HTMLInputElement>(null);
    const textareaRef = useRef<HTMLTextAreaElement>(null);
    const { data, setData, post, processing, errors, clearErrors, progress } = useForm<{
        body: string;
        media: File | null;
        idempotency_key: string;
    }>({
        body: '',
        media: null,
        idempotency_key: generateIdempotencyKey(),
    });

    useEffect(() => {
        setMessageSearch(filters.message_search);
    }, [filters.message_search]);

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
                message_search: filters.message_search,
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
        if (processing || (data.body.trim() === '' && data.media === null)) {
            return;
        }

        post(`/whatsapp/conversations/${conversation.id}/messages`, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                clearErrors();
                setData({
                    body: '',
                    media: null,
                    idempotency_key: generateIdempotencyKey(),
                });

                if (fileInputRef.current) {
                    fileInputRef.current.value = '';
                }
            },
        });
    };

    const handleComposerKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if (event.key !== 'Enter' || event.shiftKey || event.nativeEvent.isComposing) {
            return;
        }

        event.preventDefault();
        sendMessage();
    };

    return (
        <>
            <header className="border-b border-gray-200 bg-white p-4">
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <h2 className="text-lg font-semibold text-black">{conversationTitle(conversation)}</h2>
                    </div>

                    <SearchForm
                        placeholder="Buscar mensajes"
                        value={messageSearch}
                        onChange={setMessageSearch}
                        onSubmit={() => {
                            router.get(
                                '/whatsapp/conversations',
                                compactQuery({
                                    chat: conversation.external_id,
                                    chat_search: filters.chat_search,
                                    message_search: messageSearch,
                                }),
                                {
                                    only: INBOX_RELOAD_PROPS,
                                    preserveScroll: true,
                                    preserveState: true,
                                    replace: true,
                                },
                            );
                        }}
                        onClear={() => {
                            setMessageSearch('');
                            router.get(
                                '/whatsapp/conversations',
                                compactQuery({
                                    chat: conversation.external_id,
                                    chat_search: filters.chat_search,
                                }),
                                {
                                    only: INBOX_RELOAD_PROPS,
                                    preserveScroll: true,
                                    preserveState: true,
                                    replace: true,
                                },
                            );
                        }}
                        className="w-72"
                    />
                </div>
            </header>

            <div ref={scrollContainerRef} onScroll={handleMessagesScroll} className="min-h-0 flex-1 overflow-y-auto bg-gray-50 p-5">
                {messages.length === 0 ? (
                    <p className="rounded-xl border border-gray-200 bg-white p-4 text-sm text-gray-600">
                        {filters.message_search === '' ? 'No hay mensajes para este chat.' : 'No hay mensajes que coincidan con la búsqueda.'}
                    </p>
                ) : (
                    <div className="space-y-2">
                        {hasMoreMessages ? (
                            <div className="flex justify-center pb-2">
                                <button
                                    type="button"
                                    onClick={loadOlderMessages}
                                    disabled={isLoadingOlder}
                                    className="rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-medium text-gray-600 transition hover:border-green-600 hover:text-green-700 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {isLoadingOlder ? 'Cargando mensajes anteriores...' : 'Cargar mensajes anteriores'}
                                </button>
                            </div>
                        ) : null}
                        {messages.map((message) => (
                            <MessageBubble key={message.id} message={message} onOpenImage={setImagePreview} />
                        ))}
                    </div>
                )}
            </div>

            <footer className="border-t border-gray-200 bg-white p-3">
                <form onSubmit={submitMessage} className="space-y-2">
                    <input type="hidden" value={data.idempotency_key} readOnly />
                    <input
                        ref={fileInputRef}
                        type="file"
                        className="hidden"
                        accept="image/*,video/*,audio/*,.pdf,.txt,.doc,.docx,.xls,.xlsx"
                        onChange={(event) => setData('media', event.target.files?.[0] ?? null)}
                    />
                    {data.media ? (
                        <div className="ml-14 flex w-fit max-w-[75%] items-center gap-2 rounded-full bg-gray-200 px-3 py-1 text-xs text-gray-900">
                            <Paperclip className="size-3.5" />
                            <span className="truncate">{data.media.name}</span>
                            <button
                                type="button"
                                onClick={() => {
                                    setData('media', null);

                                    if (fileInputRef.current) {
                                        fileInputRef.current.value = '';
                                    }
                                }}
                                className="rounded-full p-0.3 text-gray-900 transition hover:bg-white/10"
                                aria-label="Quitar adjunto"
                            >
                                <X className="size-3.5" />
                            </button>
                        </div>
                    ) : null}
                    <div className="flex items-end gap-2">
                        <button
                            type="button"
                            onClick={() => fileInputRef.current?.click()}
                            className="grid size-10 shrink-0 place-items-center rounded-full bg-[#00a884] text-white transition hover:bg-[#06cf9c]"
                            aria-label="Adjuntar archivo"
                        >
                            <Paperclip className="size-4" />
                        </button>
                        {/* <button
                            type="button"
                            className="grid size-11 shrink-0 place-items-center rounded-full text-gray-300 transition hover:bg-white/10 hover:text-white"
                            aria-label="Emoji"
                        >
                            <Smile className="size-5" />
                        </button> */}
                        <label className="sr-only" htmlFor="message-body">
                            Mensaje
                        </label>
                        <textarea
                            ref={textareaRef}
                            id="message-body"
                            value={data.body}
                            onChange={(event) => setData('body', event.target.value)}
                            onKeyDown={handleComposerKeyDown}
                            rows={1}
                            maxLength={4000}
                            placeholder={data.media ? 'Agregá un comentario' : 'Escribí un mensaje'}
                            className="max-h-36 min-h-10 flex-1 resize-none border-b border-transparent px-4 pt-4 pb-1.5 text-sm leading-5 text-black outline-none transition placeholder:text-gray-400 focus:border-b-[#06cf9c]"
                        />
                        <button
                            type="submit"
                            disabled={processing || (data.body.trim() === '' && data.media === null)}
                            className="grid size-10 shrink-0 place-items-center rounded-full bg-[#00a884] text-white transition hover:bg-[#06cf9c] disabled:cursor-not-allowed disabled:opacity-50"
                            aria-label="Enviar mensaje"
                        >
                            <Send className="size-4" />
                        </button>
                    </div>
                    {data.media && progress ? <p className="ml-14 text-xs text-gray-500">Subiendo archivo… {progress.percentage}%</p> : null}
                    {errors.body ? <p className="mt-2 text-sm text-red-600">{errors.body}</p> : null}
                    {errors.media ? <p className="mt-2 text-sm text-red-600">{errors.media}</p> : null}
                    {errors.idempotency_key ? <p className="mt-2 text-sm text-red-600">{errors.idempotency_key}</p> : null}
                </form>
            </footer>
            {imagePreview ? <ImageViewerModal image={imagePreview} onClose={() => setImagePreview(null)} /> : null}
        </>
    );
}

function MessageBubble({ message, onOpenImage }: { message: MessageItem; onOpenImage: (image: ImagePreview) => void }) {
    const fromMe = message.direction === 'outbound';
    const timestamp = message.sent_at ?? message.received_at ?? message.created_at;

    return (
        <article className={`flex ${fromMe ? 'justify-end' : 'justify-start'}`}>
            <div className={`max-w-[70%] rounded-xl border px-3 py-2 ${fromMe ? 'border-green-200 bg-green-50' : 'border-gray-200 bg-white'}`}>
                <MessageMedia message={message} onOpenImage={onOpenImage} />
                {message.body ? (
                    <p className="whitespace-pre-wrap text-sm leading-6 text-black">
                        {message.body}
                    </p>
                ) : message.type === 'text' ? (
                    <p className="whitespace-pre-wrap text-sm leading-6 text-black">Mensaje sin texto visible</p>
                ) : null}
                <p className="mt-1 text-right text-[11px] text-gray-500">
                    {formatWhatsAppTimestamp(timestamp)} · {translateStatus(message.status)}
                </p>
            </div>
        </article>
    );
}

function MessageMedia({ message, onOpenImage }: { message: MessageItem; onOpenImage: (image: ImagePreview) => void }) {
    if (message.type === 'text') {
        return null;
    }

    if (!message.media_url) {
        return (
            <p className="mb-1 rounded-lg bg-black/5 px-3 py-2 text-sm text-gray-700">
                {mediaLabel(message.type)} {message.media_download_status ? `(${translateMediaStatus(message.media_download_status)})` : '(pendiente)'}
            </p>
        );
    }

    if (message.type === 'image') {
        return (
            <button
                type="button"
                onClick={() => onOpenImage({ url: message.media_url!, filename: message.media_filename ?? 'imagen-whatsapp' })}
                className="mb-2 block overflow-hidden rounded-lg text-left"
                aria-label="Abrir imagen"
            >
                <img src={message.media_url} alt={message.media_filename ?? 'Imagen adjunta'} className="max-h-80 object-contain transition hover:brightness-95" loading="lazy" />
            </button>
        );
    }

    if (message.type === 'video') {
        return <video src={message.media_url} controls className="mb-2 max-h-80 rounded-lg" />;
    }

    if (message.type === 'audio') {
        return <audio src={message.media_url} controls className="mb-2 w-72 max-w-full" />;
    }

    return (
        <a href={message.media_url} target="_blank" rel="noreferrer" className="mb-2 flex items-center gap-2 rounded-lg bg-black/5 px-3 py-2 text-sm font-medium text-gray-800 transition hover:bg-black/10">
            <Paperclip className="size-4" />
            <span className="truncate">{message.media_filename ?? 'Documento adjunto'}</span>
            {message.media_size_bytes ? <span className="shrink-0 text-xs text-gray-500">{formatBytes(message.media_size_bytes)}</span> : null}
        </a>
    );
}

function ImageViewerModal({ image, onClose }: { image: ImagePreview; onClose: () => void }) {
    const [scale, setScale] = useState(1);
    const [position, setPosition] = useState({ x: 0, y: 0 });
    const dragStartRef = useRef<{ pointerId: number; x: number; y: number; positionX: number; positionY: number } | null>(null);

    useEffect(() => {
        const closeOnEscape = (event: globalThis.KeyboardEvent) => {
            if (event.key === 'Escape') {
                onClose();
            }
        };

        window.addEventListener('keydown', closeOnEscape);

        return () => window.removeEventListener('keydown', closeOnEscape);
    }, [onClose]);

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
        <div className="fixed inset-0 z-50 flex flex-col bg-black/90 text-white" role="dialog" aria-modal="true" aria-label="Vista ampliada de imagen">
            <header className="flex items-center justify-between gap-3 border-b border-white/10 px-4 py-3">
                <p className="truncate text-sm font-medium">{image.filename}</p>
                <div className="flex items-center gap-2">
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
                    className="max-h-full max-w-full select-none object-contain transition-transform duration-75"
                    style={{ transform: `translate(${position.x}px, ${position.y}px) scale(${scale})` }}
                />
            </div>
        </div>
    );
}

function EmptyConversation() {
    return (
        <div className="grid h-full place-items-center bg-gray-50 p-8 text-center">
            <div className="max-w-md rounded-2xl border border-gray-200 bg-white p-8">
                <h2 className="text-xl font-semibold text-black">Seleccioná una conversación</h2>
                <p className="mt-3 text-sm leading-6 text-gray-600">
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
    className = '',
}: {
    placeholder: string;
    value: string;
    onChange: (value: string) => void;
    onSubmit: () => void;
    onClear: () => void;
    className?: string;
}) {
    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                onSubmit();
            }}
            className={`flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 py-2 ${className}`}
        >
            <Search className="size-4 text-gray-400" />
            <input
                type="search"
                value={value}
                onChange={(event) => onChange(event.target.value)}
                placeholder={placeholder}
                className="w-full bg-transparent text-sm text-black outline-none placeholder:text-gray-400"
            />
            {value.trim() !== '' ? (
                <button type="button" onClick={onClear} className="text-xs font-medium text-gray-500 transition hover:text-green-700">
                    Limpiar
                </button>
            ) : null}
        </form>
    );
}

function compactQuery(query: Record<string, string | number | null | undefined>): Record<string, string | number> {
    return Object.fromEntries(
        Object.entries(query).filter(([, value]) => value !== null && value !== undefined && String(value).trim() !== ''),
    ) as Record<string, string | number>;
}

function conversationHref(chatId: string, filters: Props['filters']): string {
    const query = new URLSearchParams();

    query.set('chat', chatId);

    if (filters.chat_search !== '') {
        query.set('chat_search', filters.chat_search);
    }

    if (filters.message_search !== '') {
        query.set('message_search', filters.message_search);
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

function formatWhatsAppTimestamp(value: string | null): string {
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

function mediaLabel(type: string): string {
    const labels: Record<string, string> = {
        image: 'Imagen',
        video: 'Video',
        audio: 'Audio',
        document: 'Documento',
    };

    return labels[type] ?? 'Archivo';
}

function translateMediaStatus(status: string): string {
    const statuses: Record<string, string> = {
        stored: 'guardado',
        pending: 'pendiente',
        omitted: 'omitido por OpenWA',
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

function translateStatus(status: string): string {
    const statuses: Record<string, string> = {
        received: 'Recibido',
        pending: 'Pendiente',
        accepted: 'Aceptado',
        failed: 'Fallido',
    };

    return statuses[status] ?? status;
}
