import { Head, router, useForm, usePage } from '@inertiajs/react';
import {
    type FormEvent,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import toast from 'react-hot-toast';
import { AgendaModal } from '@/components/whatsapp/conversations/ContactModals';
import { ImageViewerModal } from '@/components/whatsapp/conversations/ImageViewerModal';
import {
    isGalleryImage,
    isGroupableGalleryImage,
    MessageTimeline,
} from '@/components/whatsapp/conversations/MessageTimeline';
import { MaintenanceModal } from '@/components/whatsapp/conversations/MaintenanceModal';
import {
    CHAT_BACKGROUND_STYLE,
    INBOX_RELOAD_PROPS,
    INCOMING_MESSAGE_SOUND_COOLDOWN_MS,
    INCOMING_MESSAGE_SOUND_URL,
} from '@/components/whatsapp/conversations/constants';
import type {
    ConversationItem,
    ImagePreview,
    MessageItem,
    Props,
} from '@/components/whatsapp/conversations/types';
import {
    compactQuery,
    incomingConversationSoundKey,
    normalizeSearch,
} from '@/components/whatsapp/conversations/utils';
import { ConversationSidebar } from '@/components/whatsapp/conversations/ConversationSidebar';
import { MessageComposer } from '@/components/whatsapp/conversations/MessageComposer';
import { useMessageComposer } from '@/components/whatsapp/conversations/hooks/useMessageComposer';

export default function Conversations({
    operator,
    conversations,
    selectedChatId,
    messageLimit,
    hasMoreMessages,
    firstUnreadMessageId,
    messages,
    emptyState,
    filters,
    flash,
    maintenance,
}: Props) {
    const { auth } = usePage<Props>().props;
    const selectedConversation =
        conversations.find(
            (conversation) => conversation.external_id === selectedChatId,
        ) ?? null;
    const operatorName =
        operator?.name ??
        auth?.user?.nombre ??
        auth?.user?.usuario ??
        'Operador';
    const [chatSearch, setChatSearch] = useState(filters.chat_search);
    const chatSearchRef = useRef(chatSearch);
    const chatSearchHasLocalChangeRef = useRef(false);
    const incomingMessageSoundRef = useRef<HTMLAudioElement | null>(null);
    const incomingMessageSoundInitializedRef = useRef(false);
    const incomingMessageSeenKeysRef = useRef<Set<string>>(new Set());
    const incomingMessageSoundLastPlayedAtRef = useRef(0);
    const incomingMessageSoundStartedAtRef = useRef(Date.now() - 30000);
    const conversationListRef = useRef<HTMLDivElement>(null);
    const disconnectForm = useForm({});
    const logoutForm = useForm({});
    const syncMaintenanceForm = useForm({});
    const [maintenanceModalOpen, setMaintenanceModalOpen] = useState(false);
    const [agendaOpen, setAgendaOpen] = useState(false);
    const maintenanceIsRunning = maintenance.status === 'running';
    const maintenanceIsBusy =
        maintenance.status === 'running' || maintenance.status === 'queued';

    useEffect(() => {
        incomingMessageSoundRef.current = new Audio(INCOMING_MESSAGE_SOUND_URL);
        incomingMessageSoundRef.current.preload = 'auto';

        const warmIncomingSound = () => {
            const audio = incomingMessageSoundRef.current;

            if (!audio) {
                return;
            }

            const previousMuted = audio.muted;
            const previousVolume = audio.volume;

            audio.muted = true;
            audio.volume = 0.01;

            void audio
                .play()
                .then(() => {
                    audio.pause();
                    audio.currentTime = 0;
                })
                .catch(() => undefined)
                .finally(() => {
                    audio.muted = previousMuted;
                    audio.volume = previousVolume;
                });
        };

        document.addEventListener('pointerdown', warmIncomingSound, {
            once: true,
        });
        document.addEventListener('keydown', warmIncomingSound, { once: true });

        return () => {
            document.removeEventListener('pointerdown', warmIncomingSound);
            document.removeEventListener('keydown', warmIncomingSound);
            incomingMessageSoundRef.current?.pause();
            incomingMessageSoundRef.current = null;
        };
    }, []);

    useEffect(() => {
        const incomingKeys = conversations
            .filter(
                (conversation) =>
                    conversation.last_message_direction === 'inbound' &&
                    conversation.last_message_at !== null,
            )
            .map((conversation) => incomingConversationSoundKey(conversation))
            .filter((key): key is string => key !== null);

        if (!incomingMessageSoundInitializedRef.current) {
            incomingMessageSeenKeysRef.current = new Set(incomingKeys);
            incomingMessageSoundInitializedRef.current = true;

            return;
        }

        const newIncomingKeys = incomingKeys.filter(
            (key) => !incomingMessageSeenKeysRef.current.has(key),
        );
        incomingKeys.forEach((key) =>
            incomingMessageSeenKeysRef.current.add(key),
        );

        if (
            newIncomingKeys.length === 0 ||
            document.visibilityState === 'visible' ||
            document.hasFocus()
        ) {
            return;
        }

        const hasNewMessageAfterPageOpen = newIncomingKeys.some((key) => {
            const timestamp = Number(key.split('|')[1] ?? 0);

            return (
                Number.isFinite(timestamp) &&
                timestamp >= incomingMessageSoundStartedAtRef.current
            );
        });

        if (!hasNewMessageAfterPageOpen) {
            return;
        }

        const now = Date.now();

        if (
            now - incomingMessageSoundLastPlayedAtRef.current <
            INCOMING_MESSAGE_SOUND_COOLDOWN_MS
        ) {
            return;
        }

        incomingMessageSoundLastPlayedAtRef.current = now;

        const audio = incomingMessageSoundRef.current;

        if (!audio) {
            return;
        }

        audio.currentTime = 0;
        void audio.play().catch(() => undefined);
    }, [conversations]);

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
            const latestNormalizedSearch = normalizeSearch(
                chatSearchRef.current,
            );

            if (
                latestNormalizedSearch === normalizeSearch(filters.chat_search)
            ) {
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
        if (!selectedChatId || maintenanceModalOpen) {
            return;
        }

        const deselectConversation = (event: globalThis.KeyboardEvent) => {
            if (event.defaultPrevented) {
                return;
            }

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

        return () =>
            document.removeEventListener('keydown', deselectConversation);
    }, [filters.chat_search, maintenanceModalOpen, selectedChatId]);

    useEffect(() => {
        const interval = window.setInterval(() => {
            if (document.hidden) {
                return;
            }

            router.reload({
                only: INBOX_RELOAD_PROPS,
                preserveScroll: true,
            });
        }, 15000);

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

    function syncMaintenance(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        syncMaintenanceForm.post('/whatsapp/sync-maintenance', {
            only: INBOX_RELOAD_PROPS,
            preserveScroll: true,
        });
    }

    function openConversation(chatId: string) {
        const conversationListScrollTop =
            conversationListRef.current?.scrollTop ?? 0;

        router.get(
            '/whatsapp/conversations',
            compactQuery({
                chat: chatId,
                chat_search: filters.chat_search,
            }),
            {
                only: INBOX_RELOAD_PROPS,
                preserveScroll: true,
                preserveState: true,
                replace: true,
                onFinish: () => {
                    window.requestAnimationFrame(() => {
                        if (conversationListRef.current) {
                            conversationListRef.current.scrollTop =
                                conversationListScrollTop;
                        }
                    });
                },
            },
        );
    }

    return (
        <>
            <Head title="Chats" />

            <main className="app-shell min-h-screen">
                {/* <section className="mx-auto flex h-[calc(100vh-3rem)] w-full max-w-7xl overflow-hidden rounded-2xl border border-white/10 bg-zinc-950 shadow-2xl shadow-black/50"> */}
                <section className="app-chat-shell flex h-[calc(100vh-0rem)] w-full border border-(--app-border)">
                    <ConversationSidebar
                        operatorName={operatorName}
                        chatSearch={chatSearch}
                        onChatSearchChange={(value) => {
                            chatSearchHasLocalChangeRef.current = true;
                            setChatSearch(value);
                        }}
                        onChatSearchClear={() => {
                            chatSearchHasLocalChangeRef.current = true;
                            setChatSearch('');
                        }}
                        onOpenAgenda={() => setAgendaOpen(true)}
                        onOpenMaintenance={() => setMaintenanceModalOpen(true)}
                        conversations={conversations}
                        selectedChatId={selectedChatId}
                        filters={filters}
                        emptyState={emptyState}
                        conversationListRef={conversationListRef}
                        onSelect={openConversation}
                    />

                    <section
                        className="app-chat-shell hidden min-w-0 flex-1 flex-col md:flex"
                        style={CHAT_BACKGROUND_STYLE}
                    >
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

                    {maintenanceModalOpen ? (
                        <MaintenanceModal
                            maintenance={maintenance}
                            isRunning={maintenanceIsRunning}
                            isBusy={maintenanceIsBusy}
                            syncing={syncMaintenanceForm.processing}
                            disconnecting={disconnectForm.processing}
                            loggingOut={logoutForm.processing}
                            onClose={() => setMaintenanceModalOpen(false)}
                            onSync={syncMaintenance}
                            onDisconnect={disconnect}
                            onLogout={logout}
                        />
                    ) : null}

                    {agendaOpen ? (
                        <AgendaModal onClose={() => setAgendaOpen(false)} />
                    ) : null}
                </section>
            </main>
        </>
    );
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
    const [imagePreview, setImagePreview] = useState<ImagePreview | null>(null);
    const composer = useMessageComposer(conversation);

    const imageGallery = useMemo(
        () =>
            imagePreview
                ? contiguousImageGallery(messages, imagePreview.id)
                : [],
        [imagePreview, messages],
    );
    const activeImageIndex = imagePreview
        ? imageGallery.findIndex((image) => image.id === imagePreview.id)
        : -1;


    const showGalleryImage = (index: number) => {
        if (imageGallery.length === 0) {
            return;
        }

        setImagePreview(
            imageGallery[(index + imageGallery.length) % imageGallery.length],
        );
    };

    return (
        <>
            <MessageTimeline
                conversation={conversation}
                messages={messages}
                messageLimit={messageLimit}
                hasMoreMessages={hasMoreMessages}
                firstUnreadMessageId={firstUnreadMessageId}
                chatSearch={filters.chat_search}
                isDraggingFiles={composer.isDraggingFiles}
                onOpenImage={setImagePreview}
                onReply={composer.replyToMessage}
                onDragEnter={composer.handleDragEnter}
                onDragOver={composer.handleDragOver}
                onDragLeave={composer.handleDragLeave}
                onDrop={composer.handleDrop}
            />
            <MessageComposer conversation={conversation} composer={composer} />
            {imagePreview ? (
                <ImageViewerModal
                    image={imagePreview}
                    positionLabel={
                        activeImageIndex >= 0
                            ? `${activeImageIndex + 1}/${imageGallery.length}`
                            : null
                    }
                    canNavigate={imageGallery.length > 1}
                    onPrevious={() => showGalleryImage(activeImageIndex - 1)}
                    onNext={() => showGalleryImage(activeImageIndex + 1)}
                    onClose={() => setImagePreview(null)}
                />
            ) : null}
        </>
    );
}

function contiguousImageGallery(
    messages: MessageItem[],
    imageId: number,
): ImagePreview[] {
    const clickedIndex = messages.findIndex(
        (message) => message.id === imageId,
    );

    if (clickedIndex === -1 || !isGalleryImage(messages[clickedIndex])) {
        return [];
    }

    if (!isGroupableGalleryImage(messages[clickedIndex])) {
        const message = messages[clickedIndex];

        return [
            {
                id: message.id,
                url: message.media_url!,
                filename: message.media_filename ?? 'imagen',
            },
        ];
    }

    let startIndex = clickedIndex;
    let endIndex = clickedIndex;

    while (
        startIndex > 0 &&
        isGroupableGalleryImage(messages[startIndex - 1])
    ) {
        startIndex -= 1;
    }

    while (
        endIndex < messages.length - 1 &&
        isGroupableGalleryImage(messages[endIndex + 1])
    ) {
        endIndex += 1;
    }

    return messages.slice(startIndex, endIndex + 1).map((message) => ({
        id: message.id,
        url: message.media_url!,
        filename: message.media_filename ?? 'imagen',
    }));
}

function EmptyConversation() {
    return (
        <div
            className="grid h-full place-items-center p-8 text-center"
            style={CHAT_BACKGROUND_STYLE}
        >
            {/* <div className="app-surface-soft max-w-md rounded-2xl border p-8">
                <p className="text-sm leading-6">
                    Elegí un chat para ver los mensajes.
                </p>
            </div> */}
        </div>
    );
}
