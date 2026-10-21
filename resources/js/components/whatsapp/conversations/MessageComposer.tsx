import { Files, Paperclip, Reply, Send, Smile, UserRound, X } from 'lucide-react';
import EmojiPicker from '@/components/ui/EmojiPicker';
import { ContactSelectorModal } from './ContactModals';
import type { ConversationItem, MessageItem } from './types';
import type { MessageComposerController } from './hooks/useMessageComposer';

export function MessageComposer({
    conversation,
    composer,
}: {
    conversation: ConversationItem;
    composer: MessageComposerController;
}) {
    const {
        data,
        errors,
        sendProgress,
        attachmentMenuOpen,
        composerEmojiPickerOpen,
        contactSelectorOpen,
        sendingContactId,
        replyTarget,
        fileInputRef,
        attachmentMenuRef,
        composerEmojiPickerRef,
        composerEmojiTriggerRef,
        textareaRef,
        submitMessage,
        setAttachmentMenuOpen,
        toggleComposerEmojiPicker,
        insertComposerEmoji,
        setContactSelectorOpen,
        sendContact,
        removeFile,
        handleFileInputChange,
        handleBodyChange,
        handleComposerKeyDown,
        rememberComposerSelection,
        handlePaste,
        cancelReply,
    } = composer;

    return (
        <>
            <footer className="app-surface m-3 rounded-t-3xl rounded-b-3xl border p-1">
                <form onSubmit={submitMessage} className="space-y-2">
                    <input
                        type="hidden"
                        value={data.idempotency_key}
                        readOnly
                    />
                    <input
                        ref={fileInputRef}
                        type="file"
                        className="hidden"
                        multiple
                        onChange={(event) =>
                            handleFileInputChange(event.target.files)
                        }
                    />
                    {data.media.length > 0 ? (
                        <div className="ml-14 flex max-w-[75%] flex-wrap gap-2">
                            {data.media.map((file, index) => (
                                <div
                                    key={`${file.name}-${file.size}-${index}`}
                                    className="flex max-w-full items-center gap-2 rounded-full bg-(--app-surface-strong) px-3 py-1 text-xs"
                                >
                                    <Paperclip className="size-3.5 shrink-0" />
                                    <span className="truncate">
                                        {file.name}
                                    </span>
                                    <span className="app-muted shrink-0">
                                        {formatBytes(file.size)}
                                    </span>
                                    <button
                                        type="button"
                                        onClick={() => removeFile(index)}
                                        className="p-0.3 rounded-full transition hover:bg-(--app-control-hover)"
                                        aria-label="Quitar adjunto"
                                    >
                                        <X className="size-3.5" />
                                    </button>
                                </div>
                            ))}
                        </div>
                    ) : null}
                    {replyTarget ? (
                        <div className="app-surface-soft ml-14 flex min-w-0 items-center gap-3 rounded-2xl border border-l-4 border-l-(--app-accent) px-3 py-2">
                            <Reply
                                className="size-4 shrink-0 text-(--app-accent)"
                                aria-hidden="true"
                            />
                            <div className="min-w-0 flex-1">
                                <p className="text-xs font-semibold text-(--app-accent)">
                                    {replySenderLabel(replyTarget, conversation)}
                                </p>
                                <p className="app-muted truncate text-xs">
                                    {replyPreviewLabel(replyTarget)}
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={cancelReply}
                                className="app-muted app-focus-ring grid size-7 shrink-0 place-items-center rounded-full transition hover:bg-(--app-control-hover) hover:text-(--app-accent)"
                                aria-label="Cancelar respuesta"
                            >
                                <X className="size-4" />
                            </button>
                        </div>
                    ) : null}
                    <div className="flex items-end gap-2">
                        <div ref={attachmentMenuRef} className="relative shrink-0">
                            <button
                                type="button"
                                onClick={() =>
                                    setAttachmentMenuOpen((current) => !current)
                                }
                                className="app-button-primary grid size-10 place-items-center rounded-full transition"
                                aria-label="Adjuntar"
                                aria-expanded={attachmentMenuOpen}
                                aria-haspopup="menu"
                            >
                                <Paperclip className="size-4" />
                            </button>
                            {attachmentMenuOpen ? (
                                <div
                                    className="app-menu absolute bottom-[calc(100%+0.5rem)] left-0 z-20 w-44 rounded-xl border p-1 text-sm shadow-2xl shadow-black/30"
                                    role="menu"
                                >
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setAttachmentMenuOpen(false);
                                            fileInputRef.current?.click();
                                        }}
                                        className="app-menu-item flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left"
                                        role="menuitem"
                                    >
                                        <Files className="size-4 text-(--app-accent)" />
                                        <span>Archivos</span>
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setAttachmentMenuOpen(false);
                                            setContactSelectorOpen(true);
                                        }}
                                        className="app-menu-item flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left"
                                        role="menuitem"
                                    >
                                        <UserRound className="size-4 text-(--app-accent)" />
                                        <span>Contactos</span>
                                    </button>
                                </div>
                            ) : null}
                        </div>
                        <label className="sr-only" htmlFor="message-body">
                            Mensaje
                        </label>
                        <div className="flex min-h-10 flex-1 items-end">
                            <div
                                ref={composerEmojiPickerRef}
                                className="relative self-end"
                            >
                                <button
                                    ref={composerEmojiTriggerRef}
                                    type="button"
                                    onClick={toggleComposerEmojiPicker}
                                    className="app-button-primary grid size-10 place-items-center rounded-full transition"
                                    aria-label="Insertar emoji"
                                    aria-expanded={composerEmojiPickerOpen}
                                    aria-haspopup="dialog"
                                >
                                    <Smile className="size-4" />
                                </button>

                                {composerEmojiPickerOpen ? (
                                    <div
                                        className="app-menu absolute bottom-[calc(100%+0.5rem)] left-0 z-20 rounded-xl border shadow-2xl shadow-black/30"
                                        role="dialog"
                                        aria-label="Elegir emoji"
                                    >
                                        <EmojiPicker
                                            onSelect={insertComposerEmoji}
                                        />
                                    </div>
                                ) : null}
                            </div>
                            <textarea
                                ref={textareaRef}
                                id="message-body"
                                value={data.body}
                                onChange={(event) =>
                                    handleBodyChange(event.target.value)
                                }
                                onKeyDown={handleComposerKeyDown}
                                onSelect={rememberComposerSelection}
                                onClick={rememberComposerSelection}
                                onKeyUp={rememberComposerSelection}
                                onPaste={handlePaste}
                                rows={1}
                                maxLength={4000}
                                placeholder={
                                    data.media.length > 0
                                        ? 'Agregá un comentario'
                                        : 'Escribí un mensaje'
                                }
                                className="max-h-36 min-h-10 flex-1 resize-none px-2 pt-3 pb-1.5 text-sm leading-5 transition outline-none"
                            />
                        </div>
                        <button
                            type="submit"
                            disabled={
                                data.body.trim() === '' &&
                                data.media.length === 0
                            }
                            className="app-button-primary grid size-10 shrink-0 place-items-center rounded-full transition disabled:cursor-not-allowed disabled:opacity-50"
                            aria-label="Enviar mensaje"
                        >
                            <Send className="size-4" />
                        </button>
                    </div>
                    {sendProgress !== null ? (
                        <p className="app-muted ml-14 text-xs">
                            Subiendo archivos… {sendProgress}%
                        </p>
                    ) : null}
                    {errors.body ? (
                        <p className="mt-2 text-sm text-(--app-danger)">
                            {errors.body}
                        </p>
                    ) : null}
                    {errors.media ? (
                        <p className="mt-2 text-sm text-(--app-danger)">
                            {errors.media}
                        </p>
                    ) : null}
                    {errors.contact_id ? (
                        <p className="mt-2 text-sm text-(--app-danger)">
                            {errors.contact_id}
                        </p>
                    ) : null}
                    {errors.idempotency_key ? (
                        <p className="mt-2 text-sm text-(--app-danger)">
                            {errors.idempotency_key}
                        </p>
                    ) : null}
                </form>
            </footer>
            {contactSelectorOpen ? (
                <ContactSelectorModal
                    sendingContactId={sendingContactId}
                    onClose={() => setContactSelectorOpen(false)}
                    onSelect={sendContact}
                />
            ) : null}
        </>
    );
}

function replySenderLabel(
    message: MessageItem,
    conversation: ConversationItem,
): string | null {
    return message.direction === 'outbound' ? 'Vos' : conversation.title;
}

function replyPreviewLabel(message: MessageItem): string {
    const body = message.body?.trim();

    if (body) {
        return body.length > 120 ? `${body.slice(0, 119)}…` : body;
    }

    const labels: Record<string, string> = {
        image: 'Imagen',
        audio: 'Audio',
        video: 'Video',
        document: 'Documento',
        contact: 'Contacto',
    };

    return labels[message.type] ?? 'Mensaje';
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
