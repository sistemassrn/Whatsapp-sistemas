<?php

namespace App\Services;

use App\Models\Message;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WhatsappMessageMediaDownloader
{
    private const MAX_MEDIA_BYTES = 26214400;

    private const ALLOWED_MEDIA_MIME_TYPES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'video/mp4',
        'audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/webm', 'audio/wav', 'audio/x-wav',
        'application/pdf', 'text/plain', 'application/sql', 'application/octet-stream',
        'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public function __construct(private OpenWaClient $client) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function attempt(Message $message, string $sessionId, ?string $chatId = null, array $context = []): bool
    {
        if (! $this->shouldAttempt($message)) {
            return false;
        }

        $chatId ??= $message->conversation->external_id;
        $messageId = $message->external_id;

        if (! is_string($chatId) || trim($chatId) === '' || ! is_string($messageId) || trim($messageId) === '') {
            return false;
        }

        try {
            $download = $this->client->downloadMessageMedia($sessionId, $chatId, $messageId);
        } catch (ConnectionException|RequestException $exception) {
            $this->markRetriable($message, 'Archivo no disponible.');
            Log::info('WhatsApp media download fallback failed.', [
                'message_id' => $message->id,
                'status' => $exception instanceof RequestException ? $exception->response?->status() : null,
            ]);

            return false;
        }

        if ($download === null) {
            try {
                $download = $this->client->downloadMessageMediaFromHistory($sessionId, $chatId, $messageId);
            } catch (ConnectionException|RequestException $exception) {
                $this->markRetriable($message, 'Archivo no disponible.');
                Log::info('WhatsApp media history fallback failed.', [
                    'message_id' => $message->id,
                    'status' => $exception instanceof RequestException ? $exception->response?->status() : null,
                ]);

                return false;
            }

            if ($download === null) {
                $this->markRetriable($message, 'Archivo no disponible.');

                return false;
            }
        }

        return $this->storeDownload($message, $download, $context);
    }

    public function shouldAttempt(Message $message): bool
    {
        return in_array($message->type, ['image', 'video', 'audio', 'document'], true)
            && in_array($message->media_download_status, ['omitted', 'pending', 'failed'], true)
            && $message->media_path === null;
    }

    /**
     * @param  array<string, mixed>  $download
     * @param  array<string, mixed>  $context
     */
    private function storeDownload(Message $message, array $download, array $context): bool
    {
        $payload = $this->mediaPayload($download);

        if ($payload === null) {
            $this->markFailed($message, 'Archivo no disponible.');

            return false;
        }

        $mimeType = $this->normalizeMimeType(
            $this->firstString($download, ['mimetype', 'mimeType', 'mime', 'contentType', 'content_type'])
                ?? $message->media_mime_type
                ?? $this->defaultMimeForType($message->type),
        );
        $filename = $this->safeFilename(
            $this->firstString($download, ['filename', 'fileName', 'name'])
                ?? $this->filenameFromContentDisposition((string) ($download['content_disposition'] ?? ''))
                ?? $message->media_filename
                ?? 'media',
            $mimeType,
        );
        $size = strlen($payload);

        if (! $this->isAllowedMime($mimeType)) {
            $this->markFailed($message, 'Tipo de archivo no permitido.', $mimeType, $filename, $size);

            return false;
        }

        if ($size > self::MAX_MEDIA_BYTES) {
            $this->markFailed($message, 'El archivo supera el tamaño máximo permitido.', $mimeType, $filename, $size);

            return false;
        }

        $path = 'inbound/'.now()->format('Y/m').'/'.Str::uuid()->toString().'.'.$this->extensionForMime($mimeType);

        try {
            Storage::disk('whatsapp_media')->put($path, $payload);
        } catch (\Throwable $exception) {
            Log::warning('WhatsApp media fallback could not be stored.', [
                'message_id' => $message->id,
                'error' => $exception->getMessage(),
            ]);
            $this->markFailed($message, 'No se pudo guardar el archivo multimedia.', $mimeType, $filename, $size);

            return false;
        }

        $metadata = is_array($message->media_metadata) ? $message->media_metadata : [];

        foreach (['duration', 'durationSeconds', 'seconds', 'width', 'height', 'pageCount'] as $metadataKey) {
            $metadataValue = $download[$metadataKey] ?? null;

            if (is_scalar($metadataValue)) {
                $metadata[$metadataKey] = $metadataValue;
            }
        }

        $message->forceFill([
            'media_disk' => 'whatsapp_media',
            'media_path' => $path,
            'media_mime_type' => $mimeType,
            'media_filename' => $filename,
            'media_size_bytes' => $size,
            'media_download_status' => 'stored',
            'media_error' => null,
            'media_next_retry_at' => null,
            'media_retry_attempts' => 0,
            'media_metadata' => array_merge($metadata, [
                'fallback_downloaded' => true,
                'fallback_source' => $context['source'] ?? 'openwa',
            ]),
        ])->save();

        return true;
    }

    /**
     * @param  array<string, mixed>  $download
     */
    private function mediaPayload(array $download): ?string
    {
        $binary = $download['binary'] ?? null;

        if (is_string($binary) && $binary !== '') {
            return $binary;
        }

        $encoded = $this->firstString($download, ['base64', 'data', 'body', 'file', 'content', 'url', 'media.base64', 'media.data', 'media.url']);

        if ($encoded !== null) {
            if (preg_match('/^https?:\/\//i', $encoded) === 1) {
                return $this->downloadUrl($encoded);
            }

            return $this->decodeBase64Media($encoded);
        }

        $media = $download['media'] ?? null;

        return is_string($media) ? $this->decodeBase64Media($media) : null;
    }

    private function downloadUrl(string $url): ?string
    {
        try {
            $response = Http::timeout((int) config('openwa.timeout'))->get($url);
        } catch (\Throwable) {
            return null;
        }

        return $response->successful() ? $response->body() : null;
    }

    private function markFailed(Message $message, string $error, ?string $mimeType = null, ?string $filename = null, ?int $size = null): void
    {
        if ($error === 'Archivo no disponible.') {
            $this->markRetriable($message, $error);

            return;
        }

        $message->forceFill(array_filter([
            'media_mime_type' => $mimeType,
            'media_filename' => $filename,
            'media_size_bytes' => $size,
            'media_download_status' => 'failed',
            'media_error' => $error,
        ], static fn ($value): bool => $value !== null))->save();
    }

    private function markRetriable(Message $message, string $error): void
    {
        $status = in_array($message->media_download_status, ['omitted', 'pending', 'failed'], true)
            ? $message->media_download_status
            : 'pending';
        $mediaError = $message->media_download_status === 'failed' && is_string($message->media_error) && $message->media_error !== ''
            ? $message->media_error
            : $error;
        $attempts = max(0, (int) $message->media_retry_attempts) + 1;

        $message->forceFill([
            'media_download_status' => $status,
            'media_error' => $mediaError,
            'media_next_retry_at' => now()->addMinutes($this->retryDelayMinutes($attempts)),
            'media_retry_attempts' => $attempts,
        ])->save();
    }

    private function retryDelayMinutes(int $attempts): int
    {
        $cooldownMinutes = max(1, (int) config('openwa.retry_media_cooldown_hours', 6)) * 60;
        $delayMinutes = match ($attempts) {
            1 => 5,
            2 => 15,
            3 => 60,
            default => $cooldownMinutes,
        };

        return min($delayMinutes, $cooldownMinutes);
    }

    private function decodeBase64Media(string $encodedData): ?string
    {
        if (str_contains($encodedData, ',')) {
            [, $encodedData] = explode(',', $encodedData, 2);
        }

        $encodedData = preg_replace('/\s+/', '', trim($encodedData));

        if (! is_string($encodedData) || $encodedData === '' || ! preg_match('/^[A-Za-z0-9+\/]*={0,2}$/', $encodedData)) {
            return null;
        }

        $decoded = base64_decode($encodedData, true);

        return is_string($decoded) ? $decoded : null;
    }

    private function defaultMimeForType(string $messageType): string
    {
        return match ($messageType) {
            'image' => 'image/jpeg',
            'video' => 'video/mp4',
            'audio' => 'audio/ogg',
            default => 'application/octet-stream',
        };
    }

    private function isAllowedMime(string $mimeType): bool
    {
        return in_array($this->baseMimeType($mimeType), self::ALLOWED_MEDIA_MIME_TYPES, true);
    }

    private function safeFilename(string $filename, string $mimeType): string
    {
        $basename = pathinfo($filename, PATHINFO_FILENAME);
        $extension = pathinfo($filename, PATHINFO_EXTENSION) ?: $this->extensionForMime($mimeType);
        $safeBasename = Str::of($basename)->ascii()->replaceMatches('/[^A-Za-z0-9._-]+/', '-')->trim('-._')->limit(80, '')->toString();

        return ($safeBasename !== '' ? $safeBasename : 'media').'.'.$extension;
    }

    private function extensionForMime(string $mimeType): string
    {
        return match ($this->baseMimeType($mimeType)) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/bmp' => 'bmp',
            'video/mp4' => 'mp4',
            'audio/mpeg' => 'mp3',
            'audio/mp4' => 'm4a',
            'audio/ogg' => 'ogg',
            'audio/webm' => 'webm',
            'audio/wav', 'audio/x-wav' => 'wav',
            'application/pdf' => 'pdf',
            'application/sql' => 'sql',
            'text/plain' => 'txt',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            default => 'bin',
        };
    }

    private function normalizeMimeType(string $mimeType): string
    {
        $baseMimeType = $this->baseMimeType($mimeType);

        return str_starts_with($baseMimeType, 'audio/ogg') && str_contains($mimeType, 'codecs=')
            ? $mimeType
            : $baseMimeType;
    }

    private function baseMimeType(string $mimeType): string
    {
        return strtolower(trim(Str::before($mimeType, ';')));
    }

    private function filenameFromContentDisposition(string $contentDisposition): ?string
    {
        if (preg_match('/filename="?([^";]+)"?/i', $contentDisposition, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]) !== '' ? trim($matches[1]) : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private function firstString(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = data_get($payload, $key);

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }

            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
        }

        return null;
    }
}
