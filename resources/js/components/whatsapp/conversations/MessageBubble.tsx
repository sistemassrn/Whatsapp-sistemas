import { router } from '@inertiajs/react';
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
} from '@floating-ui/react';
import { MoreVertical, Pencil, Reply, Smile, Trash2, X } from 'lucide-react';
import { type FormEvent, useEffect, useState } from 'react';
import toast from 'react-hot-toast';
import MessageReactionPopover from '@/components/ui/MessageReactionPopover';
import {
    COLLAPSED_MESSAGE_CHAR_LIMIT,
    COLLAPSED_MESSAGE_WORD_LIMIT,
    REACTION_EMOJIS,
} from './constants';
import { MessageMedia } from './MessageMedia';
import type {
    ConversationItem,
    ImagePreview,
    MessageItem,
    MessageMetadata,
    QuotedMessage,
} from './types';

export function MessageBubble({
    message,
    conversation,
    onOpenImage,
    onReply,
    searchQuery,
    activeSearchMatch,
    refCallback,
}: {
    message: MessageItem;
    conversation: ConversationItem;
    onOpenImage: (image: ImagePreview) => void;
    onReply: (message: MessageItem) => void;
    searchQuery: string;
    activeSearchMatch: boolean;
    refCallback: (element: HTMLElement | null) => void;
}) {
    const fromMe = message.direction === 'outbound';
    const timestamp =
        message.sent_at ?? message.received_at ?? message.created_at;
    const [actionsOpen, setActionsOpen] = useState(false);
    const [actionsReference, setActionsReference] = useState<Element | null>(
        null,
    );
    const [actionMode, setActionMode] = useState<'list' | 'edit' | 'delete'>(
        'list',
    );
    const [editBody, setEditBody] = useState(message.body ?? '');
    const [processingMutation, setProcessingMutation] = useState(false);
    const [reactionPickerOpen, setReactionPickerOpen] = useState(false);
    const [reactionReference, setReactionReference] = useState<Element | null>(
        null,
    );
    const [reactionTriggerVisible, setReactionTriggerVisible] = useState(false);
    const [reactionError, setReactionError] = useState<string | null>(null);
    const [optimisticReaction, setOptimisticReaction] = useState<{
        messageId: number;
        emoji: string;
        snapshot: string;
    } | null>(null);
    const [messageExpanded, setMessageExpanded] = useState(false);
    const [audioTimeLabel, setAudioTimeLabel] = useState<string | null>(() =>
        message.type === 'audio'
            ? formatAudioTime(audioMetadataDuration(message.media_metadata))
            : null,
    );
    const deleted = message.deleted_at !== null;
    const canReply = canReplyToMessage(message);
    const canShowMenu = message.can_edit || message.can_delete || canReply;
    const canReact = !deleted && message.can_react;
    const reactionSnapshot = reactionSnapshotSignature(message.media_metadata);
    const reactionOverlayEmoji =
        optimisticReaction?.messageId === message.id
            ? optimisticReaction.emoji
            : message.accepted_reaction_emoji;
    const visibleReactionBadges = reactionBadgesFor(
        message.media_metadata,
        reactionOverlayEmoji,
    );

    const closeActions = () => {
        setActionsOpen(false);
        setActionMode('list');
        setEditBody(message.body ?? '');
    };

    const closeReactionPicker = () => {
        setReactionPickerOpen(false);
        setReactionReference(null);
        setReactionTriggerVisible(false);
    };

    useEffect(() => {
        closeReactionPicker();
        setOptimisticReaction(null);
    }, [conversation.external_id, message.id]);

    useEffect(() => {
        if (
            optimisticReaction?.messageId === message.id &&
            (message.accepted_reaction_at !== null ||
                optimisticReaction.snapshot !== reactionSnapshot)
        ) {
            setOptimisticReaction(null);
        }
    }, [
        message.accepted_reaction_at,
        message.id,
        optimisticReaction,
        reactionSnapshot,
    ]);

    useEffect(() => {
        if (actionMode !== 'edit') {
            setEditBody(message.body ?? '');
        }
    }, [actionMode, message.body]);

    useEffect(() => {
        setAudioTimeLabel(
            message.type === 'audio'
                ? formatAudioTime(audioMetadataDuration(message.media_metadata))
                : null,
        );
    }, [message.media_metadata, message.type]);

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

    const submitReaction = (emoji: string) => {
        if (processingMutation) {
            return;
        }

        setProcessingMutation(true);
        setReactionError(null);

        void fetch(`/whatsapp/messages/${message.id}/reactions`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ emoji }),
        })
            .then(async (response) => {
                if (response.ok) {
                    return;
                }

                const payload = (await response.json().catch(() => null)) as {
                    message?: unknown;
                } | null;
                throw new Error(
                    typeof payload?.message === 'string'
                        ? payload.message
                        : 'No se pudo actualizar la reacción.',
                );
            })
            .then(() => {
                setOptimisticReaction({
                    messageId: message.id,
                    emoji,
                    snapshot: reactionSnapshot,
                });
                closeReactionPicker();
                router.reload({
                    only: ['messages'],
                    preserveScroll: true,
                    onSuccess: () => setOptimisticReaction(null),
                });
            })
            .catch((error: unknown) => {
                const message =
                    error instanceof Error
                        ? error.message
                        : 'No se pudo actualizar la reacción.';
                setReactionError(message);
                toast.error(message);
            })
            .finally(() => setProcessingMutation(false));
    };

    return (
        <article
            className={`group flex min-w-0 items-center gap-2 ${
                fromMe
                    ? 'flex-row-reverse justify-start'
                    : 'flex-row justify-start'
            }`}
        >
            <div
                ref={(element) => {
                    refCallback(element);
                }}
                onPointerDown={(event) => {
                    if (event.pointerType === 'touch') {
                        setReactionTriggerVisible(true);
                    }
                }}
                onPointerCancel={closeReactionPicker}
                className={`app-message-bubble relative max-w-[70%] min-w-0 overflow-visible rounded-xl border px-3 py-2 shadow-sm transition ${
                    fromMe ? 'app-message-bubble-out' : ''
                }`}
            >
                {message.quoted_message ? (
                    <QuotedMessagePreview
                        quotedMessage={message.quoted_message}
                        conversation={conversation}
                    />
                ) : null}
                {canShowMenu ? (
                    <div className="absolute top-1 right-1">
                        <button
                            type="button"
                            onClick={(event) => {
                                setActionsReference(event.currentTarget);
                                setActionsOpen((open) => !open);
                                setActionMode('list');
                            }}
                            className="app-muted grid size-7 place-items-center rounded-full opacity-70 transition group-hover:opacity-100 hover:bg-(--app-control-hover) hover:text-(--app-accent) hover:opacity-100"
                            aria-label="Opciones del mensaje"
                            aria-expanded={actionsOpen}
                            aria-haspopup="dialog"
                        >
                            <MoreVertical className="size-4" />
                        </button>
                    </div>
                ) : null}

                {deleted ? (
                    <p className="app-message-text app-muted pr-6 text-sm leading-6 italic">
                        Mensaje eliminado
                    </p>
                ) : (
                    <>
                        <MessageMedia
                            message={message}
                            onAudioTimeLabelChange={setAudioTimeLabel}
                            onOpenImage={onOpenImage}
                        />
                        {message.body && message.type !== 'contact' ? (
                            <MessageBody
                                text={message.body}
                                searchQuery={searchQuery}
                                activeSearchMatch={activeSearchMatch}
                                expanded={messageExpanded}
                                onToggleExpanded={() =>
                                    setMessageExpanded((current) => !current)
                                }
                            />
                        ) : message.type === 'text' ? (
                            <p className="app-message-text app-muted pr-6 text-sm leading-6 italic">
                                Mensaje sin contenido
                            </p>
                        ) : null}
                        {visibleReactionBadges.length > 0 ? (
                            <div
                                className={`absolute bottom-0 z-10 flex max-w-full translate-y-1/2 flex-wrap gap-1 ${
                                    fromMe ? 'right-2' : 'left-2'
                                }`}
                                aria-label="Reacciones del mensaje"
                            >
                                {visibleReactionBadges.map((emoji) => (
                                    <button
                                        key={emoji}
                                        type="button"
                                        onClick={(event) => {
                                            setReactionReference(
                                                event.currentTarget,
                                            );
                                            setReactionPickerOpen(true);
                                        }}
                                        disabled={
                                            !canReact || processingMutation
                                        }
                                        className="app-surface-soft app-focus-ring inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs transition hover:bg-(--app-control-hover) disabled:cursor-not-allowed disabled:opacity-60"
                                        aria-label={`Reaccionar con ${emoji}`}
                                    >
                                        <span aria-hidden="true">{emoji}</span>
                                    </button>
                                ))}
                            </div>
                        ) : null}
                    </>
                )}

                <p
                    className={`app-muted mt-1 flex items-center text-[11px] ${message.type === 'audio' ? 'justify-between gap-4' : 'justify-end gap-1'}`}
                >
                    {message.type === 'audio' && audioTimeLabel !== null ? (
                        <span className="shrink-0 tabular-nums">
                            {audioTimeLabel}
                        </span>
                    ) : null}
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
                {message.remote_edit_status === 'failed' &&
                message.edit_error ? (
                    <p className="app-message-text app-alert-danger mt-1 rounded-lg border px-2 py-1 text-xs leading-5">
                        Edición fallida: {message.edit_error}
                    </p>
                ) : null}
                {message.remote_delete_status === 'failed' &&
                message.delete_error ? (
                    <p className="app-message-text app-alert-danger mt-1 rounded-lg border px-2 py-1 text-xs leading-5">
                        Eliminación fallida: {message.delete_error}
                    </p>
                ) : null}
                {reactionError ? (
                    <p className="app-message-text app-alert-danger mt-1 rounded-lg border px-2 py-1 text-xs leading-5">
                        Reacción fallida: {reactionError}
                    </p>
                ) : null}
                {actionsOpen ? (
                    <MessageActionsPanel
                        message={message}
                        reference={actionsReference}
                        mode={actionMode}
                        editBody={editBody}
                        processing={processingMutation}
                        onModeChange={setActionMode}
                        onEditBodyChange={setEditBody}
                        onSubmitEdit={submitEdit}
                        onDelete={deleteMessage}
                        onReply={() => {
                            onReply(message);
                            closeActions();
                        }}
                        onClose={closeActions}
                    />
                ) : null}
                {canReact ? (
                    <>
                        <button
                            type="button"
                            onClick={(event) => {
                                if (reactionPickerOpen) {
                                    closeReactionPicker();
                                    return;
                                }

                                setReactionReference(event.currentTarget);
                                setReactionTriggerVisible(true);
                                setReactionPickerOpen(true);
                            }}
                            disabled={processingMutation}
                            className={`app-muted app-focus-ring absolute top-1/2 grid size-7 -translate-y-1/2 place-items-center rounded-full transition hover:bg-(--app-control-hover) hover:text-(--app-accent) disabled:cursor-wait disabled:opacity-60 ${
                                fromMe ? 'right-full mr-1' : 'left-full ml-1'
                            } ${
                                reactionPickerOpen || reactionTriggerVisible
                                    ? 'opacity-100'
                                    : 'pointer-events-none opacity-0 group-focus-within:pointer-events-auto group-focus-within:opacity-100 group-hover:pointer-events-auto group-hover:opacity-100'
                            }`}
                            aria-label="Agregar reacción"
                            aria-expanded={reactionPickerOpen}
                            aria-haspopup="dialog"
                        >
                            <Smile className="size-4" />
                        </button>
                        <MessageReactionPopover
                            open={reactionPickerOpen}
                            reference={reactionReference}
                            placement={fromMe ? 'top-end' : 'top-start'}
                            processing={processingMutation}
                            reactions={REACTION_EMOJIS}
                            onOpenChange={(open) => {
                                if (open) {
                                    setReactionPickerOpen(true);
                                } else {
                                    closeReactionPicker();
                                }
                            }}
                            onSelect={submitReaction}
                        />
                    </>
                ) : null}
            </div>
        </article>
    );
}

function MessageBody({
    text,
    searchQuery,
    activeSearchMatch,
    expanded,
    onToggleExpanded,
}: {
    text: string;
    searchQuery: string;
    activeSearchMatch: boolean;
    expanded: boolean;
    onToggleExpanded: () => void;
}) {
    const words = text.trim().split(/\s+/).filter(Boolean);
    const shouldTruncate =
        !activeSearchMatch &&
        (words.length > COLLAPSED_MESSAGE_WORD_LIMIT ||
            text.length > COLLAPSED_MESSAGE_CHAR_LIMIT);

    return (
        <div className="pr-6">
            <p
                className={`app-message-text text-sm leading-6 ${shouldTruncate && !expanded ? 'app-message-text-collapsed' : ''}`}
            >
                {activeSearchMatch ? (
                    <HighlightedText text={text} query={searchQuery} />
                ) : (
                    text
                )}
            </p>
            {shouldTruncate ? (
                <button
                    type="button"
                    onClick={onToggleExpanded}
                    className="app-read-more mt-1 text-xs font-semibold transition hover:underline focus-visible:ring-2 focus-visible:ring-(--app-focus) focus-visible:ring-offset-2 focus-visible:ring-offset-(--app-chat-in) focus-visible:outline-none"
                >
                    {expanded ? 'Leer menos' : 'Leer más'}
                </button>
            ) : null}
        </div>
    );
}

function MessageActionsPanel({
    message,
    reference,
    mode,
    editBody,
    processing,
    onModeChange,
    onEditBodyChange,
    onSubmitEdit,
    onDelete,
    onReply,
    onClose,
}: {
    message: MessageItem;
    reference: Element | null;
    mode: 'list' | 'edit' | 'delete';
    editBody: string;
    processing: boolean;
    onModeChange: (mode: 'list' | 'edit' | 'delete') => void;
    onEditBodyChange: (value: string) => void;
    onSubmitEdit: (event: FormEvent<HTMLFormElement>) => void;
    onDelete: () => void;
    onReply: () => void;
    onClose: () => void;
}) {
    const { context, floatingStyles, refs } = useFloating({
        open: true,
        onOpenChange(nextOpen, event, reason) {
            if (reason === 'escape-key') {
                event?.preventDefault();
            }

            if (!nextOpen) {
                onClose();
            }
        },
        placement: 'bottom-end',
        middleware: [offset(8), flip({ padding: 8 }), shift({ padding: 8 })],
        whileElementsMounted: autoUpdate,
    });
    const dismiss = useDismiss(context, {
        outsidePressEvent: 'pointerdown',
        capture: { escapeKey: true },
    });
    const role = useRole(context, { role: 'dialog' });
    const { getFloatingProps } = useInteractions([dismiss, role]);

    useEffect(() => {
        refs.setReference(reference);
    }, [reference, refs]);

    if (!reference) {
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
                    className="app-surface z-40 max-h-[calc(100vh-1rem)] w-60 max-w-[calc(100vw-1rem)] overflow-y-auto rounded-2xl border p-2 text-sm shadow-2xl shadow-black/20"
                    aria-label="Opciones del mensaje"
                    {...getFloatingProps()}
                >
                    <div className="mb-3 flex items-center justify-between gap-3">
                        <h3 className="pl-1 font-semibold">
                            Opciones del mensaje
                        </h3>
                        <button
                            type="button"
                            onClick={onClose}
                            className="app-muted grid size-8 place-items-center rounded-full transition hover:bg-(--app-control-hover) hover:text-(--app-accent)"
                            aria-label="Cerrar opciones"
                        >
                            <X className="size-4" />
                        </button>
                    </div>

                    {mode === 'list' ? (
                        <div className="space-y-1">
                            {canReplyToMessage(message) ? (
                                <button
                                    type="button"
                                    onClick={onReply}
                                    className="flex w-full items-center gap-3 rounded-xl px-2 py-2 text-left transition hover:bg-(--app-control-hover)"
                                >
                                    <Reply className="size-3 text-(--app-accent)" />
                                    <span>Responder</span>
                                </button>
                            ) : null}
                            {message.can_edit ? (
                                <button
                                    type="button"
                                    onClick={() => onModeChange('edit')}
                                    className="flex w-full items-center gap-3 rounded-xl px-2 py-2 text-left transition hover:bg-(--app-control-hover)"
                                >
                                    <Pencil className="size-3 text-(--app-accent)" />
                                    <span>Editar</span>
                                </button>
                            ) : null}
                            {message.can_delete ? (
                                <button
                                    type="button"
                                    onClick={() => onModeChange('delete')}
                                    className="flex w-full items-center gap-3 rounded-xl px-2 py-2 text-left text-(--app-danger) transition hover:bg-(--app-danger-bg)"
                                >
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
                                onChange={(event) =>
                                    onEditBodyChange(event.target.value)
                                }
                                maxLength={4000}
                                rows={5}
                                className="app-input-control h-32 w-full resize-none rounded-xl border border-(--app-control-border) px-3 py-2 text-sm outline-none focus:border-(--app-focus)"
                            />
                            <div className="flex items-center justify-end gap-2 text-xs font-semibold">
                                <p className="app-faint text-right text-xs">
                                    {editBody.length}/2000
                                </p>
                                <button
                                    type="button"
                                    onClick={() => onModeChange('list')}
                                    className="app-muted rounded-full px-2 py-1 transition hover:bg-(--app-control-hover)"
                                >
                                    Cancelar
                                </button>
                                <button
                                    type="submit"
                                    disabled={
                                        processing || editBody.trim() === ''
                                    }
                                    className="app-button-primary rounded-full px-2 py-1 transition disabled:opacity-50"
                                >
                                    Guardar
                                </button>
                            </div>
                        </form>
                    ) : null}

                    {mode === 'delete' ? (
                        <div className="app-alert-danger space-y-4 rounded-xl border p-4">
                            <p className="text-sm">¿Eliminar este mensaje?</p>
                            <div className="flex justify-end gap-2 text-xs font-semibold">
                                <button
                                    type="button"
                                    onClick={() => onModeChange('list')}
                                    className="rounded-full px-2 py-1 transition hover:bg-(--app-control-hover)"
                                >
                                    Cancelar
                                </button>
                                <button
                                    type="button"
                                    onClick={onDelete}
                                    disabled={processing}
                                    className="rounded-full bg-(--app-danger) px-2 py-1 text-white transition disabled:opacity-50"
                                >
                                    Eliminar
                                </button>
                            </div>
                        </div>
                    ) : null}
                </div>
            </FloatingFocusManager>
        </FloatingPortal>
    );
}

function HighlightedText({ text, query }: { text: string; query: string }) {
    const needle = query.trim();

    if (needle === '') {
        return text;
    }

    const parts = text.split(new RegExp(`(${escapeRegExp(needle)})`, 'gi'));

    return parts.map((part, index) =>
        part.toLocaleLowerCase() === needle.toLocaleLowerCase() ? (
            <mark
                key={`${part}-${index}`}
                className="app-search-highlight rounded px-0.5"
            >
                {part}
            </mark>
        ) : (
            part
        ),
    );
}

export function MessageStatusIndicator({ status }: { status: string }) {
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
        verifying: 'Enviando',
    };

    if (statusKey === 'pending' || statusKey === 'verifying') {
        const label = statusLabels[statusKey];

        return (
            <span
                title={label}
                aria-label={label}
                className="inline-flex items-center gap-1 font-medium text-(--app-faint)"
            >
                <span
                    role="img"
                    className="inline-flex size-3.5 items-center justify-center rounded-full text-[9px] leading-none"
                >
                    ◷
                </span>
            </span>
        );
    }

    if (statusKey === 'failed') {
        return (
            <span
                title={statusLabels.failed}
                aria-label={statusLabels.failed}
                role="img"
                className="inline-flex size-3.5 items-center justify-center rounded-full border border-(--app-danger) text-[10px] leading-none font-bold text-(--app-danger)"
            >
                !
            </span>
        );
    }

    const label = statusLabels[statusKey] ?? status;

    return (
        <span
            title={label}
            aria-label={label}
            className="inline-flex items-center font-medium text-(--app-faint)"
        >
            {label}
        </span>
    );
}

function QuotedMessagePreview({
    quotedMessage,
    conversation,
}: {
    quotedMessage: QuotedMessage;
    conversation: ConversationItem;
}) {
    const sender = quotedMessageSenderLabel(quotedMessage, conversation);
    const body = quotedMessage.body?.trim();

    return (
        <div className="app-surface-soft mb-2 min-w-0 rounded-md border border-l-3 border-l-(--app-accent) px-2 py-1.5">
            {sender ? (
                <p className="truncate text-xs font-semibold text-(--app-accent)">
                    {sender}
                </p>
            ) : null}
            <p className="app-muted truncate text-xs leading-5">
                {body || quotedMessage.label}
            </p>
        </div>
    );
}

function canReplyToMessage(message: MessageItem): boolean {
    return (
        message.deleted_at === null &&
        message.external_id !== null &&
        message.external_id.trim() !== ''
    );
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
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    });
}

function audioMetadataDuration(
    metadata: Record<string, unknown> | null,
): number {
    const rawDuration =
        metadata?.duration ??
        metadata?.durationSeconds ??
        metadata?.seconds ??
        metadata?.length;

    if (
        typeof rawDuration === 'number' &&
        Number.isFinite(rawDuration) &&
        rawDuration > 0
    ) {
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

function reactionBadgesFor(
    metadata: MessageMetadata | null,
    acceptedReactionEmoji: string | null,
): string[] {
    const reactions = metadata?.reactions;

    const emojis = new Set<string>();

    Object.values(reactions ?? {}).forEach((emoji) => {
        if (typeof emoji === 'string' && emoji !== '') {
            emojis.add(emoji);
        }
    });

    if (acceptedReactionEmoji !== null && acceptedReactionEmoji !== '') {
        emojis.add(acceptedReactionEmoji);
    }

    return Array.from(emojis);
}

function reactionSnapshotSignature(metadata: MessageMetadata | null): string {
    return JSON.stringify(
        Object.entries(metadata?.reactions ?? {}).sort(([left], [right]) =>
            left.localeCompare(right),
        ),
    );
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

function quotedMessageSenderLabel(
    quotedMessage: QuotedMessage,
    conversation: ConversationItem,
): string | null {
    if (quotedMessage.direction === 'outbound') {
        return 'Vos';
    }

    if (conversation.contact_name) {
        return conversation.contact_name;
    }

    return conversation.title && conversation.title !== 'Contacto desconocido'
        ? conversation.title
        : null;
}

function escapeRegExp(value: string): string {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function csrfToken(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}
