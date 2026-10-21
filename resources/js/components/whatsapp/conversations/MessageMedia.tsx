import { Paperclip, Pause, Play } from 'lucide-react';
import { type ReactNode, useEffect, useRef, useState } from 'react';
import { useAvatarSource } from './hooks/useAvatarSource';
import type {
    ContactMessageMetadata,
    ImagePreview,
    MessageItem,
} from './types';

export function ImageGroupBubble({
    messages,
    onOpenImage,
    refCallback,
    renderStatus,
}: {
    messages: MessageItem[];
    onOpenImage: (image: ImagePreview) => void;
    refCallback: (messageId: number, element: HTMLElement | null) => void;
    renderStatus: (status: string) => ReactNode;
}) {
    if (messages.length === 0) {
        return null;
    }

    const fromMe = messages[0]?.direction === 'outbound';
    const lastMessage = messages[messages.length - 1];
    const timestamp =
        lastMessage.sent_at ??
        lastMessage.received_at ??
        lastMessage.created_at;
    const visibleMessages = messages.slice(0, 4);
    const hiddenCount = messages.length - visibleMessages.length;

    return (
        <article
            className={`flex min-w-0 ${fromMe ? 'justify-end' : 'justify-start'}`}
        >
            <div
                ref={(element) => {
                    messages.forEach((message) =>
                        refCallback(message.id, element),
                    );
                }}
                className={`app-message-bubble relative max-w-[70%] min-w-0 rounded-xl border p-1.5 shadow-sm transition ${fromMe ? 'app-message-bubble-out' : ''}`}
            >
                <div
                    className={`app-image-group app-image-group-${Math.min(messages.length, 4)}`}
                >
                    {visibleMessages.map((message, index) => {
                        const isLastVisible =
                            index === visibleMessages.length - 1;

                        return (
                            <ImageGroupItem
                                key={message.id}
                                message={message}
                                className={
                                    messages.length === 3 && index === 0
                                        ? 'app-image-group-item-large'
                                        : ''
                                }
                                hiddenCount={
                                    hiddenCount > 0 && isLastVisible
                                        ? hiddenCount
                                        : 0
                                }
                                onOpenImage={onOpenImage}
                            />
                        );
                    })}
                </div>
                <p className="app-muted mt-1 flex items-center justify-end gap-1 px-1 text-[11px]">
                    <time>{formatMessageTimestamp(timestamp)}</time>
                    {renderStatus(lastMessage.status)}
                </p>
            </div>
        </article>
    );
}

function ImageGroupItem({
    message,
    className,
    hiddenCount,
    onOpenImage,
}: {
    message: MessageItem;
    className: string;
    hiddenCount: number;
    onOpenImage: (image: ImagePreview) => void;
}) {
    const [imageLoadFailed, setImageLoadFailed] = useState(false);

    useEffect(() => {
        setImageLoadFailed(false);
    }, [message.media_url]);

    if (imageLoadFailed || !message.media_url) {
        return (
            <div className={`app-image-group-item ${className}`}>
                <ImageUnavailableFallback compact />
                {hiddenCount > 0 ? (
                    <span className="app-image-group-more">+{hiddenCount}</span>
                ) : null}
            </div>
        );
    }

    return (
        <button
            type="button"
            onClick={() =>
                onOpenImage({
                    id: message.id,
                    url: message.media_url!,
                    filename: message.media_filename ?? 'imagen',
                })
            }
            className={`app-image-group-item ${className}`}
            aria-label="Abrir imagen"
        >
            <img
                src={message.media_url}
                alt={message.media_filename ?? 'Imagen adjunta'}
                loading="lazy"
                onError={() => setImageLoadFailed(true)}
            />
            {hiddenCount > 0 ? (
                <span className="app-image-group-more">+{hiddenCount}</span>
            ) : null}
        </button>
    );
}

export function MessageMedia({
    message,
    onAudioTimeLabelChange,
    onOpenImage,
}: {
    message: MessageItem;
    onAudioTimeLabelChange?: (label: string) => void;
    onOpenImage: (image: ImagePreview) => void;
}) {
    const [imageLoadFailed, setImageLoadFailed] = useState(false);

    useEffect(() => {
        setImageLoadFailed(false);
    }, [message.media_url]);

    if (message.type === 'text') {
        return null;
    }

    if (message.type === 'contact') {
        return <ContactMessageCard message={message} />;
    }

    if (!message.media_url) {
        const status = message.media_download_status
            ? translateMediaStatus(message.media_download_status)
            : 'pendiente';
        const mediaError =
            message.media_error ?? unavailableMediaLabel(message.type);

        return (
            <div className="app-surface-soft mb-1 rounded-lg border px-3 py-2 text-sm">
                <p>{mediaError ?? mediaLabel(message.type)}</p>
                <p className="app-muted mt-0.5 text-xs">Estado: {status}</p>
            </div>
        );
    }

    if (message.type === 'image') {
        if (imageLoadFailed) {
            return <ImageUnavailableFallback />;
        }

        return (
            <button
                type="button"
                onClick={() =>
                    onOpenImage({
                        id: message.id,
                        url: message.media_url!,
                        filename: message.media_filename ?? 'imagen',
                    })
                }
                className="mb-2 block overflow-hidden rounded-lg text-left"
                aria-label="Abrir imagen"
            >
                <img
                    src={message.media_url}
                    alt={message.media_filename ?? 'Imagen adjunta'}
                    className="max-h-80 object-contain transition hover:brightness-110"
                    loading="lazy"
                    onError={() => setImageLoadFailed(true)}
                />
            </button>
        );
    }

    if (message.type === 'video') {
        return (
            <video controls className="mb-2 max-h-80 rounded-lg">
                <source
                    src={message.media_url}
                    type={message.media_mime_type ?? undefined}
                />
            </video>
        );
    }

    if (message.type === 'audio') {
        return (
            <AudioMessagePlayer
                message={message}
                onTimeLabelChange={onAudioTimeLabelChange}
            />
        );
    }

    return (
        <a
            href={message.media_url}
            target="_blank"
            rel="noreferrer"
            className="app-surface-soft mb-2 flex items-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium transition hover:bg-(--app-control-hover)"
        >
            <Paperclip className="size-4" />
            <span className="truncate">
                {message.media_filename ?? 'Documento adjunto'}
            </span>
            {message.media_size_bytes ? (
                <span className="app-muted shrink-0 text-xs">
                    {formatBytes(message.media_size_bytes)}
                </span>
            ) : null}
        </a>
    );
}

function ContactMessageCard({ message }: { message: MessageItem }) {
    const contact = contactMessageMetadata(message);
    const name = contact.name ?? message.body ?? 'Contacto';
    const { avatarUrl, handleAvatarError } = useAvatarSource(
        contact.avatarUrl,
        null,
    );

    return (
        <div className="app-surface-soft mb-2 flex min-w-60 items-center gap-3 rounded-lg border px-3 py-2 text-sm">
            {avatarUrl ? (
                <img
                    src={avatarUrl}
                    alt={`Foto de perfil de ${name}`}
                    className="size-10 shrink-0 rounded-full border border-(--app-border) object-cover"
                    loading="lazy"
                    onError={handleAvatarError}
                />
            ) : (
                <span className="flex size-10 shrink-0 items-center justify-center rounded-full border border-(--app-border) bg-(--app-surface-strong) font-semibold">
                    {initials(name)}
                </span>
            )}
            <span className="min-w-0 flex-1">
                <span className="block truncate font-semibold">{name}</span>
                {contact.phone ? (
                    <span className="app-muted mt-0.5 block truncate text-xs">
                        {contact.phone}
                    </span>
                ) : null}
                {/* {contact.organization ? <span className="app-muted mt-0.5 block truncate text-xs">{contact.organization}</span> : null} */}
                {/* {contact.count > 1 ? <span className="app-faint mt-1 block text-xs">+{contact.count - 1} contacto{contact.count - 1 === 1 ? '' : 's'} más</span> : null} */}
            </span>
        </div>
    );
}

function ImageUnavailableFallback({ compact = false }: { compact?: boolean }) {
    return (
        <div
            className={`app-image-fallback ${compact ? 'app-image-fallback-compact' : 'mb-2 rounded-lg border px-4 py-6'}`}
            role="img"
            aria-label="Imagen no disponible"
        >
            <p className="font-semibold">Imagen no disponible</p>
        </div>
    );
}

function AudioMessagePlayer({
    message,
    onTimeLabelChange,
}: {
    message: MessageItem;
    onTimeLabelChange?: (label: string) => void;
}) {
    const audioRef = useRef<HTMLAudioElement>(null);
    const [isPlaying, setIsPlaying] = useState(false);
    const [duration, setDuration] = useState(0);
    const [currentTime, setCurrentTime] = useState(0);
    const hasSeekableDuration = Number.isFinite(duration) && duration > 0;
    const displayedCurrentTime = hasSeekableDuration
        ? Math.min(Math.max(currentTime, 0), duration)
        : 0;
    const progress = hasSeekableDuration
        ? displayedCurrentTime / duration
        : 0;

    useEffect(() => {
        setIsPlaying(false);
        setDuration(0);
        setCurrentTime(0);
    }, [message.media_url]);

    useEffect(() => {
        const audio = audioRef.current;

        return () => {
            audio?.pause();
        };
    }, []);

    useEffect(() => {
        onTimeLabelChange?.(
            formatAudioTime(isPlaying ? currentTime : duration),
        );
    }, [currentTime, duration, isPlaying, onTimeLabelChange]);

    const updateDuration = (audio: HTMLAudioElement) => {
        if (Number.isFinite(audio.duration) && audio.duration > 0) {
            setDuration(audio.duration);
        }
    };

    const togglePlayback = () => {
        const audio = audioRef.current;

        if (!audio) {
            return;
        }

        if (audio.paused) {
            document
                .querySelectorAll<HTMLAudioElement>(
                    'audio[data-chat-audio="true"]',
                )
                .forEach((otherAudio) => {
                    if (otherAudio !== audio) {
                        otherAudio.pause();
                    }
                });

            void audio.play();
            return;
        }

        audio.pause();
    };

    const seekTo = (nextTime: number) => {
        const audio = audioRef.current;

        if (!audio || !hasSeekableDuration) {
            return;
        }

        const clampedTime = Math.min(Math.max(nextTime, 0), duration);

        audio.currentTime = clampedTime;
        setCurrentTime(clampedTime);
    };

    return (
        <div className="app-audio-player mb-1">
            <audio
                data-chat-audio="true"
                ref={audioRef}
                preload="auto"
                onLoadedMetadata={(event) =>
                    updateDuration(event.currentTarget)
                }
                onLoadedData={(event) => updateDuration(event.currentTarget)}
                onDurationChange={(event) =>
                    updateDuration(event.currentTarget)
                }
                onTimeUpdate={(event) =>
                    setCurrentTime(event.currentTarget.currentTime)
                }
                onPlay={() => setIsPlaying(true)}
                onPause={() => setIsPlaying(false)}
                onEnded={() => {
                    setIsPlaying(false);
                    setCurrentTime(0);
                }}
            >
                <source
                    src={message.media_url!}
                    type={message.media_mime_type ?? undefined}
                />
            </audio>
            <button
                type="button"
                onClick={togglePlayback}
                className="app-audio-button"
                aria-label={isPlaying ? 'Pausar audio' : 'Reproducir audio'}
            >
                {isPlaying ? (
                    <Pause className="size-4" />
                ) : (
                    <Play className="size-4 translate-x-px" />
                )}
            </button>
            <div className="app-audio-timeline min-w-0 flex-1">
                <div className="app-audio-waveform" aria-hidden="true">
                    {Array.from({ length: 24 }, (_, index) => (
                        <span
                            key={index}
                            className={
                                index / 23 <= progress
                                    ? 'app-audio-bar app-audio-bar-active'
                                    : 'app-audio-bar'
                            }
                            style={{ height: `${6 + ((index * 7) % 14)}px` }}
                        />
                    ))}
                </div>
                <input
                    type="range"
                    min="0"
                    max={hasSeekableDuration ? duration : 0}
                    step="0.01"
                    value={displayedCurrentTime}
                    disabled={!hasSeekableDuration}
                    onChange={(event) =>
                        seekTo(Number(event.currentTarget.value))
                    }
                    className="app-audio-seek"
                    aria-label="Posición del audio"
                    aria-valuetext={`${formatAudioTime(displayedCurrentTime)} de ${formatAudioTime(
                        hasSeekableDuration ? duration : 0,
                    )}`}
                />
            </div>
        </div>
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

function contactMessageMetadata(message: MessageItem): ContactMessageMetadata {
    const contacts = message.media_metadata?.contacts;
    const contact =
        Array.isArray(contacts) && contacts.length > 0
            ? contacts[0]
            : message.media_metadata?.contact;
    const count = Array.isArray(contacts) ? contacts.length : 1;

    if (!contact || typeof contact !== 'object') {
        return {
            name: message.body,
            phone: null,
            organization: null,
            avatarUrl: null,
            count: 1,
        };
    }

    const metadata = contact as Record<string, unknown>;
    const name =
        typeof metadata.name === 'string' && metadata.name.trim() !== ''
            ? metadata.name
            : message.body;
    const phone =
        typeof metadata.phone === 'string' && metadata.phone.trim() !== ''
            ? metadata.phone
            : null;
    const organization =
        typeof metadata.organization === 'string' &&
        metadata.organization.trim() !== ''
            ? metadata.organization
            : null;
    const avatarUrl = firstString(
        metadata.avatar_url,
        metadata.avatarUrl,
        metadata.profile_photo_url,
        metadata.profilePhotoUrl,
        metadata.photo_url,
        metadata.photoUrl,
    );

    return { name, phone, organization, avatarUrl, count };
}

function firstString(...values: unknown[]): string | null {
    for (const value of values) {
        if (typeof value === 'string' && value.trim() !== '') {
            return value;
        }
    }

    return null;
}

function initials(value: string): string {
    return (
        value
            .split(' ')
            .filter(Boolean)
            .slice(0, 2)
            .map((part) => part[0]?.toUpperCase())
            .join('') || '?'
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

function mediaLabel(type: string): string {
    const labels: Record<string, string> = {
        image: 'Imagen',
        video: 'Video',
        audio: 'Audio',
        document: 'Documento',
    };

    return labels[type] ?? 'Archivo';
}

function unavailableMediaLabel(type: string): string | null {
    return type === 'audio' ? 'Audio no disponible' : 'Archivo no disponible';
}

function translateMediaStatus(status: string): string {
    const statuses: Record<string, string> = {
        stored: 'guardado',
        pending: 'pendiente',
        omitted: 'omitido',
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
