<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OpenWaMessageWebhookController extends Controller
{
    private const MAX_MEDIA_BYTES = 26214400;

    public function store(Request $request): JsonResponse
    {
        $configuredSecret = config('openwa.webhook_secret');

        if (is_string($configuredSecret) && trim($configuredSecret) !== '') {
            $requestSecret = $request->header('X-Webhook-Secret', $request->header('X-OpenWA-Secret', ''));

            if (! is_string($requestSecret) || ! hash_equals($configuredSecret, $requestSecret)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        // Local/dev environments may omit OPENWA_WEBHOOK_SECRET until OpenWA is wired.
        $payload = $request->all();
        $messagePayload = $this->messagePayload($payload);
        $fromMe = (bool) data_get($messagePayload, 'fromMe', false);
        $chatExternalId = $this->chatExternalId($messagePayload, $fromMe);
        $messageType = $this->messageType($messagePayload);
        $media = $this->extractMedia($messagePayload, $messageType);

        if ($chatExternalId === null) {
            return response()->json(['message' => 'Missing chat id'], 422);
        }

        $messageExternalId = $this->firstString($messagePayload, ['id', 'messageId', '_data.id.id', '_data.id._serialized']);

        if ($messageExternalId !== null && Message::query()->where('external_id', $messageExternalId)->exists()) {
            return response()->json(['status' => 'duplicate']);
        }

        $message = DB::transaction(function () use ($chatExternalId, $fromMe, $messageExternalId, $messagePayload, $messageType, $media): Message {
            $contact = $this->upsertContact($messagePayload, $chatExternalId, $fromMe);
            $conversation = Conversation::query()->firstOrNew(['external_id' => $chatExternalId]);

            if ($contact !== null) {
                $conversation->contact_id = $contact->id;
            }

            if (! is_string($conversation->title) || trim($conversation->title) === '') {
                $conversation->title = $this->conversationTitle($messagePayload, $chatExternalId);
            }

            $conversation->save();
            $timestamp = $this->messageTimestamp($messagePayload);

            $messageData = [
                'conversation_id' => $conversation->id,
                'external_id' => $messageExternalId,
                'direction' => $fromMe ? 'outbound' : 'inbound',
                'body' => $this->firstString($messagePayload, ['body', 'text', 'message', 'content', 'caption']),
                'type' => $messageType,
                'status' => $fromMe ? 'accepted' : 'received',
                'sent_at' => $fromMe ? $timestamp : null,
                'received_at' => $fromMe ? null : $timestamp,
            ];

            $messageData = array_merge($messageData, $this->mediaAttributes($messagePayload, $media, $messageType));

            $message = Message::query()->create($messageData);

            $conversation->forceFill([
                'last_message_id' => $message->id,
                'last_message_at' => $message->sent_at ?? $message->received_at ?? $message->created_at,
            ])->save();

            return $message;
        });

        return response()->json([
            'status' => 'stored',
            'message_id' => $message->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function messagePayload(array $payload): array
    {
        foreach (['message', 'data', 'payload'] as $key) {
            $candidate = data_get($payload, $key);

            if (is_array($candidate)) {
                return $candidate;
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $messagePayload
     */
    private function messageType(array $messagePayload): string
    {
        $type = strtolower((string) ($this->firstString($messagePayload, ['type', 'messageType', 'media.type', 'metadata.media.type', '_data.type']) ?? 'text'));

        return match ($type) {
            'image', 'video', 'audio', 'ptt', 'voice', 'document', 'file' => $type === 'file' ? 'document' : ($type === 'ptt' || $type === 'voice' ? 'audio' : $type),
            default => 'text',
        };
    }

    /**
     * @param  array<string, mixed>  $messagePayload
     * @return array<string, mixed>|null
     */
    private function extractMedia(array $messagePayload, string $messageType): ?array
    {
        foreach (['media', 'metadata.media', '_data.media'] as $key) {
            $candidate = data_get($messagePayload, $key);

            if (is_array($candidate)) {
                return $candidate;
            }
        }

        $directData = $this->firstString($messagePayload, ['base64', 'data', 'body']);

        if ($messageType !== 'text' && $directData !== null) {
            return ['data' => $directData];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $messagePayload
     * @param  array<string, mixed>|null  $media
     * @return array<string, mixed>
     */
    private function mediaAttributes(array $messagePayload, ?array $media, string $messageType): array
    {
        if ($messageType === 'text') {
            return [];
        }

        $metadata = $this->safeMediaMetadata($messagePayload, $media);
        $encodedData = $this->firstString($media ?? [], ['base64', 'data', 'body', 'file', 'content']);
        $mimeType = $this->firstString($media ?? [], ['mimeType', 'mimetype', 'mime', 'contentType'])
            ?? $this->firstString($messagePayload, ['mimeType', 'mimetype', 'mediaMimeType'])
            ?? $this->defaultMimeForType($messageType);
        $filename = $this->safeFilename(
            $this->firstString($media ?? [], ['filename', 'fileName', 'name'])
                ?? $this->firstString($messagePayload, ['filename', 'fileName'])
                ?? 'media',
            $mimeType,
        );

        $isOmitted = $this->isMediaOmitted($media ?? [], $messagePayload);

        if ($encodedData === null || $isOmitted) {
            return [
                'media_mime_type' => $mimeType,
                'media_filename' => $filename,
                'media_download_status' => $isOmitted ? 'omitted' : 'pending',
                'media_metadata' => $metadata,
            ];
        }

        $decoded = $this->decodeBase64Media($encodedData);

        if ($decoded === null) {
            return [
                'media_mime_type' => $mimeType,
                'media_filename' => $filename,
                'media_download_status' => 'failed',
                'media_error' => 'No se pudo decodificar el contenido multimedia.',
                'media_metadata' => $metadata,
            ];
        }

        if (! $this->isAllowedMime($mimeType)) {
            return [
                'media_mime_type' => $mimeType,
                'media_filename' => $filename,
                'media_size_bytes' => strlen($decoded),
                'media_download_status' => 'failed',
                'media_error' => 'Tipo de archivo no permitido.',
                'media_metadata' => $metadata,
            ];
        }

        if (strlen($decoded) > self::MAX_MEDIA_BYTES) {
            return [
                'media_mime_type' => $mimeType,
                'media_filename' => $filename,
                'media_size_bytes' => strlen($decoded),
                'media_download_status' => 'failed',
                'media_error' => 'El archivo supera el tamaño máximo permitido.',
                'media_metadata' => $metadata,
            ];
        }

        $path = 'inbound/'.now()->format('Y/m').'/'.Str::uuid()->toString().'.'.$this->extensionForMime($mimeType);
        Storage::disk('whatsapp_media')->put($path, $decoded);

        return [
            'media_disk' => 'whatsapp_media',
            'media_path' => $path,
            'media_mime_type' => $mimeType,
            'media_filename' => $filename,
            'media_size_bytes' => strlen($decoded),
            'media_download_status' => 'stored',
            'media_metadata' => $metadata,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $media
     * @param  array<string, mixed>  $messagePayload
     */
    private function isMediaOmitted(array $media, array $messagePayload): bool
    {
        return data_get($media, 'isDataUrl') === false
            || data_get($media, 'omitted') === true
            || data_get($messagePayload, 'mediaDataMissing') === true;
    }

    private function decodeBase64Media(string $encodedData): ?string
    {
        if (str_contains($encodedData, ',')) {
            [, $encodedData] = explode(',', $encodedData, 2);
        }

        $decoded = base64_decode($encodedData, true);

        return is_string($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $messagePayload
     * @param  array<string, mixed>|null  $media
     * @return array<string, mixed>
     */
    private function safeMediaMetadata(array $messagePayload, ?array $media): array
    {
        $metadata = [
            'type' => $this->messageType($messagePayload),
            'has_media_payload' => $media !== null,
        ];

        foreach (['caption', 'duration', 'width', 'height', 'pageCount'] as $key) {
            $value = data_get($media ?? [], $key, data_get($messagePayload, $key));

            if (is_scalar($value)) {
                $metadata[$key] = $value;
            }
        }

        return $metadata;
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
        return str_starts_with($mimeType, 'image/')
            || str_starts_with($mimeType, 'video/')
            || str_starts_with($mimeType, 'audio/')
            || in_array($mimeType, [
                'application/pdf',
                'text/plain',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ], true);
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
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'audio/mpeg' => 'mp3',
            'audio/mp4' => 'm4a',
            'audio/ogg' => 'ogg',
            'audio/webm' => 'webm',
            'application/pdf' => 'pdf',
            'text/plain' => 'txt',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            default => 'bin',
        };
    }

    /**
     * @param  array<string, mixed>  $messagePayload
     */
    private function chatExternalId(array $messagePayload, bool $fromMe): ?string
    {
        $explicitChatId = $this->firstString($messagePayload, [
            'chatId',
            'conversationId',
            '_data.id.remote',
            'message.chatId',
        ]);

        if ($explicitChatId !== null) {
            return $explicitChatId;
        }

        return $this->firstString($messagePayload, $fromMe ? ['to', 'from'] : ['from', 'to']);
    }

    /**
     * @param  array<string, mixed>  $messagePayload
     */
    private function upsertContact(array $messagePayload, string $chatExternalId, bool $fromMe): ?Contact
    {
        if ($this->isGroupChat($messagePayload, $chatExternalId)) {
            return null;
        }

        $contactPayload = $this->nestedArray($messagePayload, ['sender', 'contact', '_contact']) ?? $messagePayload;
        $contactExternalId = $this->firstString($contactPayload, ['id', 'contactId', 'externalId', '_serialized'])
            ?? $this->firstString($messagePayload, $fromMe ? ['to', 'from'] : ['from', 'to'])
            ?? $chatExternalId;

        return Contact::query()->updateOrCreate(
            ['external_id' => $contactExternalId],
            [
                'name' => $this->firstString($contactPayload, ['name', 'shortName', 'formattedName'])
                    ?? $this->firstString($messagePayload, ['notifyName', 'pushName']),
                'push_name' => $this->firstString($contactPayload, ['pushName', 'notifyName'])
                    ?? $this->firstString($messagePayload, ['pushName', 'notifyName', 'sender.pushName', 'contact.pushName']),
                'phone' => $this->firstString($contactPayload, ['phone', 'number', 'user']),
            ],
        );
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

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     * @return array<string, mixed>|null
     */
    private function nestedArray(array $payload, array $keys): ?array
    {
        foreach ($keys as $key) {
            $value = data_get($payload, $key);

            if (is_array($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $messagePayload
     */
    private function conversationTitle(array $messagePayload, string $fallback): string
    {
        if ($this->isGroupChat($messagePayload, $fallback)) {
            return $this->firstString($messagePayload, [
                'chat.name',
                'chat.title',
                'chat.formattedTitle',
                'groupName',
                'group.name',
                'group.title',
            ]) ?? $fallback;
        }

        return $this->firstString($messagePayload, [
            'chat.name',
            'chat.title',
            'chat.formattedTitle',
            'contact.name',
            'notifyName',
            'pushName',
            'contact.pushName',
        ]) ?? $fallback;
    }

    /**
     * @param  array<string, mixed>  $messagePayload
     */
    private function isGroupChat(array $messagePayload, string $chatExternalId): bool
    {
        return data_get($messagePayload, 'isGroup') === true || str_contains($chatExternalId, '@g.us');
    }

    /**
     * @param  array<string, mixed>  $messagePayload
     */
    private function messageTimestamp(array $messagePayload): ?Carbon
    {
        foreach (['timestamp', 't', 'time', 'createdAt', 'date'] as $key) {
            $value = data_get($messagePayload, $key);

            if (is_numeric($value)) {
                $timestamp = (int) $value;

                return Carbon::createFromTimestamp(
                    $timestamp > 9999999999 ? (int) floor($timestamp / 1000) : $timestamp,
                    config('app.timezone'),
                );
            }

            if (is_string($value) && trim($value) !== '') {
                try {
                    return Carbon::parse($value)->setTimezone(config('app.timezone'));
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return null;
    }
}
