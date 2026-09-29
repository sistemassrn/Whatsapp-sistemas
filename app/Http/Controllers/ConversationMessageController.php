<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreConversationMessageRequest;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\OpenWaClient;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\UnableToReadFile;

class ConversationMessageController extends Controller
{
    public function store(StoreConversationMessageRequest $request, Conversation $conversation, OpenWaClient $client): RedirectResponse
    {
        /** @var array{body?: string|null, idempotency_key: string, ptt?: bool, voice?: bool} $validated */
        $validated = $request->validated();
        $uploadedMediaFiles = $request->mediaFiles();
        $body = trim((string) ($validated['body'] ?? ''));
        $isMediaSend = count($uploadedMediaFiles) > 0;
        $messages = [];

        if (! $isMediaSend && $body === '') {
            return $this->redirectToConversation($conversation)
                ->with('error', 'Escribí un mensaje o adjuntá un archivo.');
        }

        $messageInputs = $isMediaSend
            ? $this->mediaMessageInputs($uploadedMediaFiles, $body, $validated['idempotency_key'])
            : [[
                'body' => $body,
                'idempotency_key' => $validated['idempotency_key'],
                'media' => null,
                'type' => 'text',
            ]];

        $now = now();

        foreach ($messageInputs as $messageInput) {
            $existingMessage = Message::query()
                ->where('idempotency_key', $messageInput['idempotency_key'])
                ->first();

            if ($existingMessage !== null) {
                continue;
            }

            try {
                $message = Message::query()->create([
                    'conversation_id' => $conversation->id,
                    'direction' => 'outbound',
                    'body' => $messageInput['body'] !== '' ? $messageInput['body'] : null,
                    'type' => $messageInput['type'],
                    'status' => 'pending',
                    'idempotency_key' => $messageInput['idempotency_key'],
                    'sent_at' => $now,
                ]);
            } catch (QueryException $exception) {
                $duplicateMessage = Message::query()
                    ->where('idempotency_key', $messageInput['idempotency_key'])
                    ->first();

                if ($duplicateMessage !== null) {
                    continue;
                }

                throw $exception;
            }

            if ($messageInput['media'] instanceof UploadedFile) {
                $this->storeOutboundMedia($message, $messageInput['media']);
            }

            $messages[] = $message;
        }

        if ($messages === []) {
            return $this->redirectToConversation($conversation)
                ->with('success', 'El mensaje ya había sido registrado.');
        }

        $conversation->update([
            'last_message_id' => end($messages)->id,
            'last_message_at' => $now,
        ]);

        try {
            $session = $client->findSessionByName((string) config('openwa.session_name'));

            if ($session === null) {
                $this->markMessagesAsFailed($messages, 'Conectá WhatsApp para enviar mensajes.');

                return $this->redirectToConversation($conversation)
                    ->with('error', 'Conectá WhatsApp para enviar mensajes.');
            }

            $sessionId = $this->sessionId($session);

            if ($sessionId === null) {
                $this->markMessagesAsFailed($messages, 'No pudimos enviar el mensaje. Reintentá en unos segundos.');

                return $this->redirectToConversation($conversation)
                    ->with('error', 'No pudimos enviar el mensaje. Reintentá en unos segundos.');
            }

            if (! $this->isReady($session)) {
                $this->markMessagesAsFailed($messages, 'Conectá WhatsApp para enviar mensajes.');

                return $this->redirectToConversation($conversation)
                    ->with('error', 'Conectá WhatsApp para enviar mensajes.');
            }

            foreach ($messages as $message) {
                $response = $message->media_path !== null
                    ? $this->sendMediaMessage($client, $sessionId, $conversation->external_id, $message, (bool) ($validated['ptt'] ?? $validated['voice'] ?? false))
                    : $client->sendTextMessage($sessionId, $conversation->external_id, $message->body ?? '');
                $message->status = 'accepted';

                $externalId = $this->messageExternalId($response);

                if ($message->external_id === null && $externalId !== null) {
                    $message->external_id = $externalId;
                }

                $message->error_message = null;
                $message->save();
            }

            return $this->redirectToConversation($conversation)
                ->with('success', 'Mensaje enviado.');
        } catch (UnableToReadFile|\RuntimeException $exception) {
            $error = $this->safeMediaSendError($exception);
            $this->markPendingMessagesAsFailed($messages, $error);

            return $this->redirectToConversation($conversation)
                ->with('error', $error);
        } catch (ConnectionException|RequestException $exception) {
            $error = $this->readableSendError($exception);
            $this->markPendingMessagesAsFailed($messages, $error);

            return $this->redirectToConversation($conversation)
                ->with('error', $error);
        }
    }

    private function redirectToConversation(Conversation $conversation): RedirectResponse
    {
        return redirect()->route('whatsapp.conversations', [
            'chat' => $conversation->external_id,
        ]);
    }

    private function markMessageAsFailed(Message $message, string $error): void
    {
        $message->update([
            'status' => 'failed',
            'error_message' => $error,
        ]);
    }

    /**
     * @param  list<Message>  $messages
     */
    private function markMessagesAsFailed(array $messages, string $error): void
    {
        foreach ($messages as $message) {
            $this->markMessageAsFailed($message, $error);
        }
    }

    /**
     * @param  list<Message>  $messages
     */
    private function markPendingMessagesAsFailed(array $messages, string $error): void
    {
        foreach ($messages as $message) {
            if ($message->status === 'pending') {
                $this->markMessageAsFailed($message, $error);
            }
        }
    }

    /**
     * @param  list<UploadedFile>  $uploadedMediaFiles
     * @return list<array{body: string, idempotency_key: string, media: UploadedFile, type: string}>
     */
    private function mediaMessageInputs(array $uploadedMediaFiles, string $body, string $baseIdempotencyKey): array
    {
        $multiple = count($uploadedMediaFiles) > 1;

        return array_map(function (UploadedFile $uploadedMedia, int $index) use ($body, $baseIdempotencyKey, $multiple): array {
            $mimeType = $uploadedMedia->getMimeType() ?: 'application/octet-stream';

            return [
                'body' => $index === 0 ? $body : '',
                'idempotency_key' => $multiple ? "{$baseIdempotencyKey}-".($index + 1) : $baseIdempotencyKey,
                'media' => $uploadedMedia,
                'type' => $this->messageTypeForMime($mimeType),
            ];
        }, $uploadedMediaFiles, array_keys($uploadedMediaFiles));
    }

    private function storeOutboundMedia(Message $message, UploadedFile $uploadedMedia): void
    {
        $mimeType = $uploadedMedia->getMimeType() ?: 'application/octet-stream';
        $originalFilename = $this->safeFilename($uploadedMedia->getClientOriginalName(), $mimeType);
        $path = 'outbound/'.now()->format('Y/m').'/'.Str::uuid()->toString().'.'.$this->extensionForMime($mimeType, $uploadedMedia->extension());

        Storage::disk('whatsapp_media')->put($path, $uploadedMedia->getContent());

        $message->update([
            'media_disk' => 'whatsapp_media',
            'media_path' => $path,
            'media_mime_type' => $mimeType,
            'media_filename' => $originalFilename,
            'media_size_bytes' => $uploadedMedia->getSize(),
            'media_download_status' => 'stored',
            'media_error' => null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sendMediaMessage(OpenWaClient $client, string $sessionId, string $chatId, Message $message, bool $ptt): array
    {
        if ($message->media_path === null || $message->media_mime_type === null) {
            throw new \RuntimeException('El mensaje no tiene un archivo almacenado para enviar.');
        }

        if ($message->media_disk !== 'whatsapp_media' || ! Storage::disk('whatsapp_media')->exists($message->media_path)) {
            throw new \RuntimeException('El archivo adjunto ya no está disponible en el almacenamiento.');
        }

        $base64 = base64_encode(Storage::disk('whatsapp_media')->get($message->media_path));
        $filename = $message->media_filename ?: basename($message->media_path);

        return match ($message->type) {
            'image' => $client->sendImageMessage($sessionId, $chatId, $base64, $message->media_mime_type, $filename, $message->body),
            'video' => $client->sendVideoMessage($sessionId, $chatId, $base64, $message->media_mime_type, $filename, $message->body),
            'audio' => $client->sendAudioMessage($sessionId, $chatId, $base64, $message->media_mime_type, $filename, $ptt),
            default => $client->sendDocumentMessage($sessionId, $chatId, $base64, $message->media_mime_type, $filename, $message->body),
        };
    }

    private function messageTypeForMime(string $mimeType): string
    {
        if (str_starts_with($mimeType, 'image/')) {
            return 'image';
        }

        if (str_starts_with($mimeType, 'video/')) {
            return 'video';
        }

        if (str_starts_with($mimeType, 'audio/')) {
            return 'audio';
        }

        return 'document';
    }

    private function safeFilename(string $filename, string $mimeType): string
    {
        $basename = pathinfo($filename, PATHINFO_FILENAME);
        $extension = pathinfo($filename, PATHINFO_EXTENSION) ?: $this->extensionForMime($mimeType, 'bin');
        $safeBasename = Str::of($basename)->ascii()->replaceMatches('/[^A-Za-z0-9._-]+/', '-')->trim('-._')->limit(80, '')->toString();

        return ($safeBasename !== '' ? $safeBasename : 'media').'.'.$extension;
    }

    private function extensionForMime(string $mimeType, ?string $fallback = null): string
    {
        $baseMimeType = $this->baseMimeType($mimeType);

        return match ($baseMimeType) {
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
            default => $fallback ?: 'bin',
        };
    }

    private function baseMimeType(string $mimeType): string
    {
        return strtolower(trim(Str::before($mimeType, ';')));
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function sessionId(array $session): ?string
    {
        $sessionId = $session['id'] ?? $session['_id'] ?? $session['sessionId'] ?? null;

        if (is_string($sessionId) && $sessionId !== '') {
            return $sessionId;
        }

        foreach (['data', 'session'] as $key) {
            if (isset($session[$key]) && is_array($session[$key])) {
                /** @var array<string, mixed> $nestedSession */
                $nestedSession = $session[$key];

                return $this->sessionId($nestedSession);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function isReady(array $session): bool
    {
        $status = $session['status'] ?? null;

        if (is_string($status) && strtolower($status) === 'ready') {
            return true;
        }

        foreach (['data', 'session'] as $key) {
            if (isset($session[$key]) && is_array($session[$key])) {
                /** @var array<string, mixed> $nestedSession */
                $nestedSession = $session[$key];

                return $this->isReady($nestedSession);
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function messageExternalId(array $response): ?string
    {
        $externalId = $response['id']
            ?? $response['messageId']
            ?? Arr::get($response, 'data.id')
            ?? Arr::get($response, '_data.id.id')
            ?? Arr::get($response, '_data.id._serialized');

        return is_string($externalId) && $externalId !== '' ? $externalId : null;
    }

    private function readableSendError(ConnectionException|RequestException $exception): string
    {
        if ($exception instanceof ConnectionException) {
            return 'No pudimos enviar el mensaje. Reintentá en unos segundos.';
        }

        $status = $exception->response->status();

        if ($status === 401 || $status === 403) {
            return 'No pudimos enviar el mensaje. Reintentá en unos segundos.';
        }

        if ($status === 404) {
            return 'Conectá WhatsApp para enviar mensajes.';
        }

        if ($status === 422) {
            return 'Revisá el destinatario, el texto o el adjunto.';
        }

        return 'No pudimos enviar el mensaje. Reintentá en unos segundos.';
    }

    private function safeMediaSendError(UnableToReadFile|\RuntimeException $exception): string
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'almacenado') || str_contains($message, 'disponible')) {
            return $message;
        }

        return 'No se pudo leer el archivo adjunto para enviarlo. Volvé a adjuntarlo e intentá de nuevo.';
    }

    private function safeResponseMessage(RequestException $exception): ?string
    {
        $message = $exception->response->json('message');

        if (! is_string($message) || trim($message) === '') {
            $errors = $exception->response->json('errors');

            if (is_array($errors)) {
                $firstError = Arr::first(Arr::flatten($errors));

                $message = is_string($firstError) ? $firstError : null;
            }
        }

        if (! is_string($message) || trim($message) === '') {
            return null;
        }

        return Str::of($message)
            ->replaceMatches('/(x-api-key|api[_-]?key|authorization|bearer)\s*[:=]\s*\S+/i', '$1=[oculto]')
            ->replaceMatches('/[A-Za-z]:\\\\[^\s]+|\/[^\s]+/', '[ruta oculta]')
            ->limit(180, '…')
            ->toString();
    }
}
