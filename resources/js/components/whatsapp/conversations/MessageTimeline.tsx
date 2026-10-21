import { router } from '@inertiajs/react';
import { ChevronDown, ChevronUp } from 'lucide-react';
import {
    type DragEventHandler,
    useEffect,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import { SearchForm } from './SearchForm';
import { CHAT_BACKGROUND_STYLE, INBOX_RELOAD_PROPS, MAX_MESSAGE_LIMIT, MESSAGE_LIMIT_INCREMENT } from './constants';
import { MessageBubble, MessageStatusIndicator } from './MessageBubble';
import { ImageGroupBubble } from './MessageMedia';
import type { ConversationItem, ImagePreview, MessageItem, MessageRenderItem, Props } from './types';

export function MessageTimeline({
    conversation,
    messages,
    messageLimit,
    hasMoreMessages,
    firstUnreadMessageId,
    chatSearch,
    isDraggingFiles,
    onOpenImage,
    onReply,
    onDragEnter,
    onDragOver,
    onDragLeave,
    onDrop,
}: {
    conversation: ConversationItem;
    messages: MessageItem[];
    messageLimit: number;
    hasMoreMessages: boolean;
    firstUnreadMessageId: number | null;
    chatSearch: Props['filters']['chat_search'];
    isDraggingFiles: boolean;
    onOpenImage: (image: ImagePreview) => void;
    onReply: (message: MessageItem) => void;
    onDragEnter: DragEventHandler<HTMLDivElement>;
    onDragOver: DragEventHandler<HTMLDivElement>;
    onDragLeave: DragEventHandler<HTMLDivElement>;
    onDrop: DragEventHandler<HTMLDivElement>;
}) {
    const scrollContainerRef = useRef<HTMLDivElement>(null);
    const previousChatIdRef = useRef<string | null>(null);
    const previousLatestMessageKeyRef = useRef<string | null>(null);
    const isLoadingOlderRef = useRef(false);
    const previousScrollHeightRef = useRef(0);
    const previousScrollTopRef = useRef(0);
    const keepScrolledToBottomRef = useRef(false);
    const [isLoadingOlder, setIsLoadingOlder] = useState(false);
    const [messageSearch, setMessageSearch] = useState('');
    const [activeMatchIndex, setActiveMatchIndex] = useState(0);
    const messageRefs = useRef<Record<number, HTMLElement | null>>({});

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
            .filter((message) =>
                (message.body ?? '')
                    .toLocaleLowerCase()
                    .includes(normalizedMessageSearch),
            )
            .map((message) => message.id);
    }, [messages, normalizedMessageSearch]);

    useLayoutEffect(() => {
        setActiveMatchIndex(
            searchMatches.length > 0 ? searchMatches.length - 1 : 0,
        );
    }, [searchMatches]);

    const activeMatchPosition =
        searchMatches.length === 0
            ? 0
            : Math.min(activeMatchIndex, searchMatches.length - 1);

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

    const latestMessage =
        messages.length > 0 ? messages[messages.length - 1] : null;
    const latestMessageKey = latestMessage
        ? `${latestMessage.id}:${latestMessage.sent_at ?? latestMessage.received_at ?? latestMessage.created_at}`
        : null;
    const messageRenderItems = useMemo(
        () => groupedMessageRenderItems(messages, firstUnreadMessageId),
        [firstUnreadMessageId, messages],
    );

    useLayoutEffect(() => {
        const scrollContainer = scrollContainerRef.current;

        if (!scrollContainer) {
            return;
        }

        const chatChanged =
            previousChatIdRef.current !== conversation.external_id;
        const latestMessageChanged =
            previousLatestMessageKeyRef.current !== latestMessageKey;

        if (isLoadingOlderRef.current) {
            scrollContainer.scrollTop =
                scrollContainer.scrollHeight -
                previousScrollHeightRef.current +
                previousScrollTopRef.current;
            isLoadingOlderRef.current = false;
            keepScrolledToBottomRef.current = false;
            setIsLoadingOlder(false);
        } else if (chatChanged || latestMessageChanged) {
            scrollContainer.scrollTop = scrollContainer.scrollHeight;
            keepScrolledToBottomRef.current = true;
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
                chat_search: chatSearch,
                message_limit: Math.min(
                    messageLimit + MESSAGE_LIMIT_INCREMENT,
                    MAX_MESSAGE_LIMIT,
                ),
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

        if (!scrollContainer) {
            return;
        }

        const distanceFromBottom =
            scrollContainer.scrollHeight -
            scrollContainer.scrollTop -
            scrollContainer.clientHeight;

        if (distanceFromBottom > 80) {
            keepScrolledToBottomRef.current = false;
        }

        if (scrollContainer.scrollTop <= 80) {
            loadOlderMessages();
        }
    };

    const keepMessagesAtBottom = () => {
        const scrollContainer = scrollContainerRef.current;

        if (
            !scrollContainer ||
            isLoadingOlderRef.current ||
            !keepScrolledToBottomRef.current
        ) {
            return;
        }

        window.requestAnimationFrame(() => {
            if (
                scrollContainerRef.current &&
                keepScrolledToBottomRef.current &&
                !isLoadingOlderRef.current
            ) {
                scrollContainerRef.current.scrollTop =
                    scrollContainerRef.current.scrollHeight;
            }
        });
    };

    const goToPreviousMatch = () => {
        if (searchMatches.length === 0) {
            return;
        }

        setActiveMatchIndex(
            (index) =>
                (index - 1 + searchMatches.length) % searchMatches.length,
        );
    };

    const goToNextMatch = () => {
        if (searchMatches.length === 0) {
            return;
        }

        setActiveMatchIndex((index) => (index + 1) % searchMatches.length);
    };

    return (
        <>
            <header className="app-surface border-b p-3 px-5">
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <h2 className="text-lg font-semibold">
                            {conversationTitle(conversation)}
                        </h2>
                        {conversation.display_description ? (
                            <p className="app-muted mt-0.5 truncate text-sm">
                                {conversation.display_description}
                            </p>
                        ) : null}
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
                                            {searchMatches.length === 0
                                                ? '0'
                                                : `${activeMatchPosition + 1}/${searchMatches.length}`}
                                        </span>
                                    ) : null}
                                    <button
                                        type="button"
                                        onClick={goToPreviousMatch}
                                        disabled={searchMatches.length === 0}
                                        className="app-muted rounded-md p-1 transition hover:bg-(--app-control-hover) hover:text-(--app-accent) disabled:cursor-not-allowed disabled:opacity-40"
                                        aria-label="Resultado anterior"
                                    >
                                        <ChevronUp className="size-4" />
                                    </button>
                                    <button
                                        type="button"
                                        onClick={goToNextMatch}
                                        disabled={searchMatches.length === 0}
                                        className="app-muted rounded-md p-1 transition hover:bg-(--app-control-hover) hover:text-(--app-accent) disabled:cursor-not-allowed disabled:opacity-40"
                                        aria-label="Resultado siguiente"
                                    >
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
                onLoadCapture={keepMessagesAtBottom}
                onDragEnter={onDragEnter}
                onDragOver={onDragOver}
                onDragLeave={onDragLeave}
                onDrop={onDrop}
                className={`app-chat-shell min-h-0 flex-1 overflow-y-auto p-5 transition-all ${isDraggingFiles ? 'app-drag-active' : ''}`}
                style={CHAT_BACKGROUND_STYLE}
            >
                {messages.length === 0 ? (
                    <p className="app-message-text flex items-center justify-center text-xs">
                        No hay mensajes para este chat.
                    </p>
                ) : (
                    <div className="space-y-2">
                        {normalizedMessageSearch !== '' ? (
                            <p className="app-faint text-center text-xs">
                                Buscá en los mensajes cargados.
                            </p>
                        ) : null}
                        {hasMoreMessages ? (
                            <div className="flex justify-center pb-2">
                                <button
                                    type="button"
                                    onClick={loadOlderMessages}
                                    disabled={isLoadingOlder}
                                    className="app-button rounded-full border px-3 py-1 text-xs font-medium transition disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {isLoadingOlder
                                        ? 'Cargando mensajes anteriores...'
                                        : 'Cargar mensajes anteriores'}
                                </button>
                            </div>
                        ) : null}
                        {firstUnreadMessageId !== null &&
                        !messages.some(
                            (message) => message.id === firstUnreadMessageId,
                        ) ? (
                            <UnreadDivider text="Hay mensajes no leídos más arriba" />
                        ) : null}
                        {messageRenderItems.map((item) => {
                            if (item.type === 'divider') {
                                return (
                                    <UnreadDivider
                                        key={item.key}
                                        text={item.text}
                                    />
                                );
                            }

                            if (item.type === 'date-divider') {
                                return (
                                    <DateDivider
                                        key={item.key}
                                        text={item.text}
                                    />
                                );
                            }

                            if (item.type === 'image-group') {
                                return (
                                    <ImageGroupBubble
                                        key={item.key}
                                        messages={item.messages}
                                        onOpenImage={onOpenImage}
                                        renderStatus={(status) => (
                                            <MessageStatusIndicator
                                                status={status}
                                            />
                                        )}
                                        refCallback={(messageId, element) => {
                                            messageRefs.current[messageId] =
                                                element;
                                        }}
                                    />
                                );
                            }

                            return (
                                <MessageBubble
                                    key={item.message.id}
                                    message={item.message}
                                    conversation={conversation}
                                    onOpenImage={onOpenImage}
                                    searchQuery={messageSearch}
                                    activeSearchMatch={
                                        searchMatches[activeMatchPosition] ===
                                        item.message.id
                                    }
                                    onReply={onReply}
                                    refCallback={(element) => {
                                        messageRefs.current[item.message.id] =
                                            element;
                                    }}
                                />
                            );
                        })}
                    </div>
                )}
            </div>
        </>
    );
}

export function isGalleryImage(message: MessageItem): boolean {
    return message.type === 'image' && message.media_url !== null;
}

function canReplyToMessage(message: MessageItem): boolean {
    return (
        message.deleted_at === null &&
        message.external_id !== null &&
        message.external_id.trim() !== ''
    );
}

export function isGroupableGalleryImage(message: MessageItem): boolean {
    return (
        isGalleryImage(message) &&
        !canReplyToMessage(message) &&
        (message.body === null || message.body.trim() === '')
    );
}

function messageTimestampValue(message: MessageItem): string | null {
    return message.sent_at ?? message.received_at ?? message.created_at;
}

function messageDateKey(message: MessageItem): string | null {
    const value = messageTimestampValue(message);

    if (!value) {
        return null;
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return null;
    }

    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function groupedMessageRenderItems(
    messages: MessageItem[],
    firstUnreadMessageId: number | null,
): MessageRenderItem[] {
    const items: MessageRenderItem[] = [];
    let index = 0;
    let currentDateKey: string | null = null;

    while (index < messages.length) {
        const message = messages[index];
        const dateKey = messageDateKey(message);

        if (dateKey !== null && dateKey !== currentDateKey) {
            currentDateKey = dateKey;
            items.push({
                type: 'date-divider',
                key: `date-${dateKey}`,
                text: formatMessageDateDivider(messageTimestampValue(message)),
            });
        }

        if (message.id === firstUnreadMessageId) {
            items.push({
                type: 'divider',
                key: `unread-${message.id}`,
                text: 'Mensajes no leídos',
            });
        }

        if (!isGroupableGalleryImage(message)) {
            items.push({ type: 'message', message });
            index += 1;
            continue;
        }

        const imageMessages: MessageItem[] = [message];
        let nextIndex = index + 1;

        while (
            nextIndex < messages.length &&
            isGroupableGalleryImage(messages[nextIndex]) &&
            messages[nextIndex].id !== firstUnreadMessageId
        ) {
            imageMessages.push(messages[nextIndex]);
            nextIndex += 1;
        }

        if (imageMessages.length === 1) {
            items.push({ type: 'message', message });
        } else {
            items.push({
                type: 'image-group',
                key: `images-${imageMessages.map((imageMessage) => imageMessage.id).join('-')}`,
                messages: imageMessages,
            });
        }

        index = nextIndex;
    }

    return items;
}

function UnreadDivider({ text }: { text: string }) {
    return (
        <div
            className="my-3 flex items-center gap-3"
            role="separator"
            aria-label={text}
        >
            <span className="app-accent-line h-px flex-1" />
            <span className="app-unread-chip rounded-full border px-3 py-1 text-[11px] font-semibold shadow-sm">
                {text}
            </span>
            <span className="app-accent-line h-px flex-1" />
        </div>
    );
}

function DateDivider({ text }: { text: string }) {
    return (
        <div
            className="my-4 flex items-center justify-center gap-3"
            role="separator"
            aria-label={text}
        >
            <span className="app-date-line h-px w-16 max-w-[22vw]" />
            <span className="app-date-chip rounded-full border px-3 py-1 text-[11px] font-semibold shadow-sm backdrop-blur">
                {text}
            </span>
            <span className="app-date-line h-px w-16 max-w-[22vw]" />
        </div>
    );
}

function conversationTitle(conversation: ConversationItem): string {
    return (
        conversation.title ??
        conversation.contact_name ??
        conversation.external_id
    );
}

function formatMessageDateDivider(value: string | null): string {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    const today = new Date();
    const yesterday = new Date();
    yesterday.setDate(today.getDate() - 1);

    if (date.toDateString() === today.toDateString()) {
        return 'Hoy';
    }

    if (date.toDateString() === yesterday.toDateString()) {
        return 'Ayer';
    }

    return date.toLocaleDateString('es-AR', {
        day: '2-digit',
        month: 'long',
        year:
            date.getFullYear() === today.getFullYear() ? undefined : 'numeric',
    });
}
