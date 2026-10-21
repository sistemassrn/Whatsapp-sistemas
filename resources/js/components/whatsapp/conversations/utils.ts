import type { ConversationItem, Props } from './types';

export function isAvatarUrl(value: unknown): value is string {
    if (typeof value !== 'string' || value === '') {
        return false;
    }

    try {
        const url = new URL(value);

        return url.protocol === 'http:' || url.protocol === 'https:';
    } catch {
        return false;
    }
}

export function compactQuery(
    query: Record<string, string | number | null | undefined>,
): Record<string, string | number> {
    return Object.fromEntries(
        Object.entries(query).filter(
            ([, value]) =>
                value !== null &&
                value !== undefined &&
                String(value).trim() !== '',
        ),
    ) as Record<string, string | number>;
}

export function normalizeSearch(value: string): string {
    return value.trim();
}

export function incomingConversationSoundKey(
    conversation: ConversationItem,
): string | null {
    if (conversation.last_message_at === null) {
        return null;
    }

    const timestamp = new Date(conversation.last_message_at).getTime();

    if (Number.isNaN(timestamp)) {
        return null;
    }

    return [
        conversation.external_id,
        timestamp,
        conversation.last_message_preview ??
            conversation.last_message_body ??
            '',
    ].join('|');
}

export function conversationHref(chatId: string, filters: Props['filters']): string {
    const query = new URLSearchParams();

    query.set('chat', chatId);

    if (filters.chat_search !== '') {
        query.set('chat_search', filters.chat_search);
    }

    return `/whatsapp/conversations?${query.toString()}`;
}
