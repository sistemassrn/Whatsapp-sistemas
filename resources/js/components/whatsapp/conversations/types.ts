export type Operator = {
    name?: string;
    email?: string;
    usuario?: string;
};

export type AuthUser = {
    id: number | string;
    usuario: string;
    nombre: string | null;
};

export type ConversationItem = {
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

export type ReactionSnapshot = Record<string, string>;
export type MessageMetadata = Record<string, unknown> & {
    reactions?: ReactionSnapshot;
};

export type QuotedMessage = {
    id: number | null;
    external_id: string | null;
    direction: string;
    type: string;
    body: string | null;
    label: string;
};

export type MessageItem = {
    id: number;
    external_id: string | null;
    direction: string;
    body: string | null;
    type: 'text' | 'image' | 'video' | 'audio' | 'document' | 'contact' | string;
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
    can_react: boolean;
    quoted_message: QuotedMessage | null;
    accepted_reaction_emoji: string | null;
    accepted_reaction_at: string | null;
    media_url: string | null;
    media_mime_type: string | null;
    media_filename: string | null;
    media_size_bytes: number | null;
    media_metadata: MessageMetadata | null;
    media_download_status: string | null;
    media_error: string | null;
    sent_at: string | null;
    received_at: string | null;
    created_at: string | null;
};

export type ImagePreview = {
    id: number;
    url: string;
    filename: string;
};

export type AgendaContact = {
    id: number;
    external_id: string;
    display_name: string;
    secondary_label: string | null;
    phone: string | null;
    usable_phone: boolean;
    profile_photo_url: string | null;
    avatar_url: string | null;
    initials: string;
};

export type MessageRenderItem =
    | { type: 'divider'; key: string; text: string }
    | { type: 'date-divider'; key: string; text: string }
    | { type: 'message'; message: MessageItem }
    | { type: 'image-group'; key: string; messages: MessageItem[] };

export type UploadProgress = {
    percentage?: number | null;
};

export type MessageSubmitErrors = Partial<
    Record<'body' | 'media' | 'idempotency_key' | 'contact_id', string>
> &
    Record<string, string>;

export type ConnectionState = {
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

export type MaintenanceInfo = {
    status: string | null;
    reason: string | null;
    requested_at: string | null;
    started_at: string | null;
    finished_at: string | null;
    duration_seconds: number | null;
    error: string | null;
    recent_sync_window_hours: number;
    retry_media_window_hours: number;
    retry_media_cooldown_hours: number;
    retry_media_every_minutes: number;
    sync_maintenance_stale_minutes: number;
    scheduler: string;
};

export type Props = {
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
    maintenance: MaintenanceInfo;
    flash?: {
        success?: string | null;
        error?: string | null;
    };
    filters: {
        chat_search: string;
        message_search: string;
    };
};

export type ContactMessageMetadata = {
    name: string | null;
    phone: string | null;
    organization: string | null;
    avatarUrl: string | null;
    count: number;
};
