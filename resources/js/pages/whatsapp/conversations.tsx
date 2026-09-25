import { Head, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';

const BUENOS_AIRES_TIME_ZONE = 'America/Argentina/Buenos_Aires';
const MESSAGE_LIMIT_INCREMENT = 50;
const MAX_MESSAGE_LIMIT = 300;

type Operator = {
    name?: string;
    email?: string;
};

type ConversationItem = {
    id: number;
    external_id: string;
    title: string | null;
    contact_name: string | null;
    last_message_body: string | null;
    last_message_direction: string | null;
    last_message_at: string | null;
};

type MessageItem = {
    id: number;
    external_id: string | null;
    direction: string;
    body: string | null;
    status: string;
    sent_at: string | null;
    received_at: string | null;
    created_at: string | null;
};

type Props = {
    operator?: Operator;
    conversations: ConversationItem[];
    selectedChatId: string | null;
    messageLimit: number;
    hasMoreMessages: boolean;
    messages: MessageItem[];
    emptyState?: string | null;
};

export default function Conversations({ operator, conversations, selectedChatId, messageLimit, hasMoreMessages, messages, emptyState }: Props) {
    const selectedConversation = conversations.find((conversation) => conversation.external_id === selectedChatId) ?? null;

    useEffect(() => {
        const interval = window.setInterval(() => {
            if (document.hidden) {
                return;
            }

            router.reload({
                only: ['conversations', 'messages', 'messageLimit', 'hasMoreMessages', 'emptyState'],
                preserveScroll: true,
            });
        }, 5000);

        return () => window.clearInterval(interval);
    }, []);

    return (
        <>
            <Head title="Bandeja WhatsApp" />

            <main className="min-h-screen bg-gray-50 p-4 text-black md:p-6">
                <section className="mx-auto flex h-[calc(100vh-3rem)] w-full max-w-7xl overflow-hidden rounded-2xl border border-gray-200 bg-white">
                    <aside className="flex w-full flex-col border-r border-gray-200 bg-white md:w-95 md:shrink-0">
                        <div className="border-b border-gray-200 bg-gray-50 p-4">
                            <div className="flex items-center justify-between gap-3">
                                <div>
                                    <h1 className="text-xl font-semibold text-black">Bandeja</h1>
                                    <p className="mt-1 text-xs text-gray-500">
                                        {operator?.name ?? 'Operador Demo'}
                                    </p>
                                </div>

                                <a
                                    href="/whatsapp/connect"
                                    className="rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-black transition hover:border-green-600 hover:text-green-700"
                                >
                                    Volver a la conexión
                                </a>
                            </div>

                            <SearchBox placeholder="Buscar chats o contactos" className="mt-4" />

                            <div className="mt-3 flex gap-2 text-xs font-medium">
                                <span className="rounded-full border border-green-600 bg-green-50 px-3 py-1 text-green-700">Todos</span>
                                <span className="rounded-full border border-gray-200 bg-white px-3 py-1 text-gray-500">No leídos</span>
                                <span className="rounded-full border border-gray-200 bg-white px-3 py-1 text-gray-500">Pendientes</span>
                            </div>
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

function ConversationRow({ conversation, active }: { conversation: ConversationItem; active: boolean }) {
    return (
        <a
            href={`/whatsapp/conversations?chat=${encodeURIComponent(conversation.external_id)}`}
            className={`flex gap-3 border-b border-gray-100 px-4 py-3 transition ${active ? 'bg-green-50' : 'bg-white hover:bg-gray-50'}`}
        >
            <div className="flex size-11 shrink-0 items-center justify-center rounded-full border border-gray-200 bg-gray-100 text-sm font-semibold text-gray-700">
                {initials(conversationTitle(conversation))}
            </div>

            <div className="min-w-0 flex-1">
                <div className="flex items-start justify-between gap-3">
                    <p className="truncate text-sm font-semibold text-black">{conversationTitle(conversation)}</p>
                    <time className="shrink-0 text-[11px] text-gray-500">{formatTime(conversation.last_message_at)}</time>
                </div>

                <p className="mt-1 truncate text-sm text-gray-600">
                    {conversation.last_message_direction === 'outbound' ? 'Vos: ' : ''}
                    {conversation.last_message_body ?? 'Sin vista previa'}
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
}: {
    conversation: ConversationItem;
    messages: MessageItem[];
    messageLimit: number;
    hasMoreMessages: boolean;
}) {
    const scrollContainerRef = useRef<HTMLDivElement>(null);
    const previousChatIdRef = useRef<string | null>(null);
    const previousLatestMessageKeyRef = useRef<string | null>(null);
    const isLoadingOlderRef = useRef(false);
    const previousScrollHeightRef = useRef(0);
    const previousScrollTopRef = useRef(0);
    const [isLoadingOlder, setIsLoadingOlder] = useState(false);

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
                message_limit: Math.min(messageLimit + MESSAGE_LIMIT_INCREMENT, MAX_MESSAGE_LIMIT),
            },
            {
                only: ['conversations', 'messages', 'messageLimit', 'hasMoreMessages', 'emptyState'],
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

    return (
        <>
            <header className="border-b border-gray-200 bg-white p-4">
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <h2 className="text-lg font-semibold text-black">{conversationTitle(conversation)}</h2>
                    </div>

                    <SearchBox placeholder="Buscar mensajes" className="w-72" />
                </div>
            </header>

            <div ref={scrollContainerRef} onScroll={handleMessagesScroll} className="min-h-0 flex-1 overflow-y-auto bg-gray-50 p-5">
                {messages.length === 0 ? (
                    <p className="rounded-xl border border-gray-200 bg-white p-4 text-sm text-gray-600">
                        No hay mensajes persistidos para este chat.
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
                            <MessageBubble key={message.id} message={message} />
                        ))}
                    </div>
                )}
            </div>

            <footer className="border-t border-gray-200 bg-white p-4">
                <div className="flex items-center gap-3 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-500">
                    <span className="flex-1">Campo para responder</span>
                    <button className="rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white opacity-60" disabled>
                        Enviar
                    </button>
                </div>
            </footer>
        </>
    );
}

function MessageBubble({ message }: { message: MessageItem }) {
    const fromMe = message.direction === 'outbound';
    const timestamp = message.sent_at ?? message.received_at ?? message.created_at;

    return (
        <article className={`flex ${fromMe ? 'justify-end' : 'justify-start'}`}>
            <div className={`max-w-[70%] rounded-xl border px-3 py-2 ${fromMe ? 'border-green-200 bg-green-50' : 'border-gray-200 bg-white'}`}>
                <p className="whitespace-pre-wrap text-sm leading-6 text-black">
                    {message.body ?? 'Mensaje sin texto visible'}
                </p>
                <p className="mt-1 text-right text-[11px] text-gray-500">
                    {formatTime(timestamp)} · {translateStatus(message.status)}
                </p>
            </div>
        </article>
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

function SearchBox({ placeholder, className = '' }: { placeholder: string; className?: string }) {
    return (
        <label className={`flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 py-2 ${className}`}>
            <Search className="size-4 text-gray-400" />
            <input
                type="search"
                placeholder={placeholder}
                className="w-full bg-transparent text-sm text-black outline-none placeholder:text-gray-400"
            />
        </label>
    );
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

function formatTime(value: string | null): string {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleTimeString('es-AR', {
        timeZone: BUENOS_AIRES_TIME_ZONE,
        hour: '2-digit',
        minute: '2-digit',
    });
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
