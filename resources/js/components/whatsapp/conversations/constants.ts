import type { CSSProperties } from 'react';

export const MESSAGE_LIMIT_INCREMENT = 50;
export const MAX_MESSAGE_LIMIT = 300;
export const COLLAPSED_MESSAGE_WORD_LIMIT = 80;
export const COLLAPSED_MESSAGE_CHAR_LIMIT = 520;
export const COMPOSER_TEXTAREA_MAX_HEIGHT = 144;
export const MAX_FILES_PER_SEND = 3;
export const MAX_FILE_BYTES = 25 * 1024 * 1024;
export const MAX_TOTAL_FILE_BYTES = 50 * 1024 * 1024;
export const INCOMING_MESSAGE_SOUND_URL = '/sounds/pop.mp3';
export const INCOMING_MESSAGE_SOUND_COOLDOWN_MS = 3000;
export const REACTION_EMOJIS = ['👍', '❤️', '😂', '😮', '😢', '🙏'];

export const CHAT_BACKGROUND_STYLE: CSSProperties = {
    backgroundImage:
        "linear-gradient(var(--chat-background-overlay), var(--chat-background-overlay)), url('/img/fondochats.webp')",
    backgroundRepeat: 'repeat',
    backgroundSize: '420px auto',
};

export const INBOX_RELOAD_PROPS = [
    'conversations',
    'messages',
    'messageLimit',
    'hasMoreMessages',
    'firstUnreadMessageId',
    'emptyState',
    'filters',
    'flash',
    'selectedChatId',
    'maintenance',
];
export const CONTACTS_LOAD_ERROR = 'No se pudo cargar la agenda.';
