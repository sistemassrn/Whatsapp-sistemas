import { router, useForm } from '@inertiajs/react';
import {
    type ClipboardEvent,
    type Dispatch,
    type DragEvent,
    type FormEvent,
    type KeyboardEvent,
    type RefObject,
    type SetStateAction,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
} from 'react';
import toast from 'react-hot-toast';
import {
    COMPOSER_TEXTAREA_MAX_HEIGHT,
    MAX_FILES_PER_SEND,
    MAX_FILE_BYTES,
    MAX_TOTAL_FILE_BYTES,
} from '../constants';
import type {
    AgendaContact,
    ConversationItem,
    MessageItem,
    MessageSubmitErrors,
    UploadProgress,
} from '../types';
import { useCloseOnOutsidePointer } from './useCloseOnOutsidePointer';

type MessageComposerData = {
    body: string;
    media: File[];
    idempotency_key: string;
};

export type MessageComposerController = {
    data: MessageComposerData;
    errors: MessageSubmitErrors;
    sendProgress: number | null;
    attachmentMenuOpen: boolean;
    composerEmojiPickerOpen: boolean;
    contactSelectorOpen: boolean;
    sendingContactId: number | null;
    replyTarget: MessageItem | null;
    fileInputRef: RefObject<HTMLInputElement | null>;
    attachmentMenuRef: RefObject<HTMLDivElement | null>;
    composerEmojiPickerRef: RefObject<HTMLDivElement | null>;
    composerEmojiTriggerRef: RefObject<HTMLButtonElement | null>;
    textareaRef: RefObject<HTMLTextAreaElement | null>;
    isDraggingFiles: boolean;
    submitMessage: (event: FormEvent<HTMLFormElement>) => void;
    setAttachmentMenuOpen: Dispatch<SetStateAction<boolean>>;
    toggleComposerEmojiPicker: () => void;
    insertComposerEmoji: (emoji: string) => void;
    setContactSelectorOpen: Dispatch<SetStateAction<boolean>>;
    sendContact: (contact: AgendaContact) => void;
    removeFile: (index: number) => void;
    handleFileInputChange: (files: FileList | null) => void;
    handleBodyChange: (body: string) => void;
    handleComposerKeyDown: (
        event: KeyboardEvent<HTMLTextAreaElement>,
    ) => void;
    rememberComposerSelection: () => void;
    handlePaste: (event: ClipboardEvent<HTMLTextAreaElement>) => void;
    cancelReply: () => void;
    replyToMessage: (message: MessageItem) => void;
    handleDragEnter: (event: DragEvent<HTMLDivElement>) => void;
    handleDragOver: (event: DragEvent<HTMLDivElement>) => void;
    handleDragLeave: (event: DragEvent<HTMLDivElement>) => void;
    handleDrop: (event: DragEvent<HTMLElement>) => void;
};

export function useMessageComposer(
    conversation: ConversationItem,
): MessageComposerController {
    const [isDraggingFiles, setIsDraggingFiles] = useState(false);
    const [attachmentMenuOpen, setAttachmentMenuOpen] = useState(false);
    const [composerEmojiPickerOpen, setComposerEmojiPickerOpen] =
        useState(false);
    const [contactSelectorOpen, setContactSelectorOpen] = useState(false);
    const [sendingContactId, setSendingContactId] = useState<number | null>(
        null,
    );
    const [replyTarget, setReplyTarget] = useState<MessageItem | null>(null);
    const activeConversationIdRef = useRef(conversation.external_id);
    const fileInputRef = useRef<HTMLInputElement>(null);
    const attachmentMenuRef = useRef<HTMLDivElement>(null);
    const composerEmojiPickerRef = useRef<HTMLDivElement>(null);
    const composerEmojiTriggerRef = useRef<HTMLButtonElement>(null);
    const textareaRef = useRef<HTMLTextAreaElement>(null);
    const composerSelectionRef = useRef({ start: 0, end: 0 });
    const composerBodyRef = useRef('');
    const composerMediaRef = useRef<File[]>([]);
    const [sendProgress, setSendProgress] = useState<number | null>(null);
    const { data, setData, errors, clearErrors, setError } =
        useForm<MessageComposerData>({
            body: '',
            media: [],
            idempotency_key: generateIdempotencyKey(),
        });

    useCloseOnOutsidePointer(attachmentMenuRef, attachmentMenuOpen, () =>
        setAttachmentMenuOpen(false),
    );
    useCloseOnOutsidePointer(
        composerEmojiPickerRef,
        composerEmojiPickerOpen,
        () => setComposerEmojiPickerOpen(false),
    );

    useEffect(() => {
        if (!composerEmojiPickerOpen) {
            return;
        }

        const closeOnEscape = (event: globalThis.KeyboardEvent) => {
            if (event.key !== 'Escape') {
                return;
            }

            event.preventDefault();
            setComposerEmojiPickerOpen(false);
            composerEmojiTriggerRef.current?.focus();
        };

        document.addEventListener('keydown', closeOnEscape);

        return () => document.removeEventListener('keydown', closeOnEscape);
    }, [composerEmojiPickerOpen]);

    useEffect(() => {
        composerBodyRef.current = data.body;
        composerMediaRef.current = data.media;
    }, [data.body, data.media]);

    useLayoutEffect(() => {
        activeConversationIdRef.current = conversation.external_id;
    }, [conversation.external_id]);

    useEffect(() => {
        setAttachmentMenuOpen(false);
        setComposerEmojiPickerOpen(false);
        setContactSelectorOpen(false);
        setReplyTarget(null);
    }, [conversation.external_id]);

    useLayoutEffect(() => {
        const textarea = textareaRef.current;

        if (!textarea) {
            return;
        }

        textarea.style.height = 'auto';
        textarea.style.height = `${Math.min(textarea.scrollHeight, COMPOSER_TEXTAREA_MAX_HEIGHT)}px`;
        textarea.style.overflowY =
            textarea.scrollHeight > COMPOSER_TEXTAREA_MAX_HEIGHT
                ? 'auto'
                : 'hidden';
    }, [data.body]);

    const sendMessage = () => {
        const submittedBody = data.body;
        const submittedMedia = data.media;
        const submittedKey = data.idempotency_key;
        const submittedReplyTarget = replyTarget;

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

        router.post(
            `/whatsapp/conversations/${conversation.id}/messages`,
            {
                body: submittedBody,
                media: submittedMedia,
                idempotency_key: submittedKey,
                reply_to_message_id: submittedReplyTarget?.id ?? null,
            },
            {
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
                    if (
                        activeConversationIdRef.current ===
                        conversation.external_id
                    ) {
                        setReplyTarget((currentTarget) =>
                            currentTarget?.id === submittedReplyTarget?.id
                                ? null
                                : currentTarget,
                        );
                    }
                },
                onError: (submitErrors: MessageSubmitErrors) => {
                    setError(submitErrors);

                    if (
                        composerBodyRef.current.trim() === '' &&
                        composerMediaRef.current.length === 0
                    ) {
                        composerBodyRef.current = submittedBody;
                        composerMediaRef.current = submittedMedia;
                        setData({
                            body: submittedBody,
                            media: submittedMedia,
                            idempotency_key: submittedKey,
                        });
                        return;
                    }

                    toast.error(
                        'No se pudo enviar el mensaje anterior. No reemplacé lo que estás escribiendo.',
                    );
                },
                onFinish: () => setSendProgress(null),
            },
        );
    };

    const submitMessage = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        sendMessage();
    };

    const sendContact = (contact: AgendaContact) => {
        if (sendingContactId !== null) {
            return;
        }

        if (!contact.usable_phone) {
            toast.error('Este contacto no tiene teléfono para enviar.');
            return;
        }

        clearErrors();
        setSendingContactId(contact.id);

        router.post(
            `/whatsapp/conversations/${conversation.id}/contacts`,
            {
                contact_id: contact.id,
                idempotency_key: generateIdempotencyKey(),
            },
            {
                async: true,
                preserveScroll: true,
                onSuccess: () => {
                    setContactSelectorOpen(false);
                },
                onError: (submitErrors: MessageSubmitErrors) => {
                    setError(submitErrors);
                    toast.error(
                        submitErrors.contact_id ??
                            submitErrors.idempotency_key ??
                            'No se pudo enviar el contacto.',
                    );
                },
                onFinish: () => setSendingContactId(null),
            },
        );
    };

    const handleComposerKeyDown = (
        event: KeyboardEvent<HTMLTextAreaElement>,
    ) => {
        if (
            event.key !== 'Enter' ||
            event.shiftKey ||
            event.nativeEvent.isComposing
        ) {
            return;
        }

        event.preventDefault();
        sendMessage();
    };

    const rememberComposerSelection = () => {
        const textarea = textareaRef.current;

        if (!textarea) {
            return;
        }

        composerSelectionRef.current = {
            start: textarea.selectionStart,
            end: textarea.selectionEnd,
        };
    };

    const toggleComposerEmojiPicker = () => {
        rememberComposerSelection();
        setComposerEmojiPickerOpen((open) => !open);
    };

    const insertComposerEmoji = (emoji: string) => {
        const { start, end } = composerSelectionRef.current;
        const nextBody = `${data.body.slice(0, start)}${emoji}${data.body.slice(end)}`;
        const cursor = start + emoji.length;

        composerBodyRef.current = nextBody;
        setData('body', nextBody);
        setComposerEmojiPickerOpen(false);

        window.requestAnimationFrame(() => {
            textareaRef.current?.focus();
            textareaRef.current?.setSelectionRange(cursor, cursor);
            composerSelectionRef.current = { start: cursor, end: cursor };
        });
    };

    const replyToMessage = (message: MessageItem) => {
        setReplyTarget(message);
        window.requestAnimationFrame(() => textareaRef.current?.focus());
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

        if (
            nextFiles.reduce((total, file) => total + file.size, 0) >
            MAX_TOTAL_FILE_BYTES
        ) {
            toast.error('Los archivos no pueden superar 50 MB.');
            return;
        }

        clearErrors('media');
        composerMediaRef.current = nextFiles;
        setData('media', nextFiles);
    };

    const removeFile = (index: number) => {
        const nextFiles = data.media.filter(
            (_, fileIndex) => fileIndex !== index,
        );

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

    return {
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
        isDraggingFiles,
        submitMessage,
        setAttachmentMenuOpen,
        toggleComposerEmojiPicker,
        insertComposerEmoji,
        setContactSelectorOpen,
        sendContact,
        removeFile,
        handleFileInputChange,
        handleBodyChange: (body) => {
            composerBodyRef.current = body;
            setData('body', body);
        },
        handleComposerKeyDown,
        rememberComposerSelection,
        handlePaste,
        cancelReply: () => setReplyTarget(null),
        replyToMessage,
        handleDragEnter: (event) => {
            if (event.dataTransfer.types.includes('Files')) {
                setIsDraggingFiles(true);
            }
        },
        handleDragOver: (event) => {
            if (event.dataTransfer.types.includes('Files')) {
                event.preventDefault();
            }
        },
        handleDragLeave: (event) => {
            if (
                !event.currentTarget.contains(
                    event.relatedTarget as Node | null,
                )
            ) {
                setIsDraggingFiles(false);
            }
        },
        handleDrop,
    };
}

function generateIdempotencyKey(): string {
    if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) {
        return crypto.randomUUID();
    }

    return `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}
