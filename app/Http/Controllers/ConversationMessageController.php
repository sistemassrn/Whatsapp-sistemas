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

class ConversationMessageController extends Controller
{
    public function store(StoreConversationMessageRequest $request, Conversation $conversation, OpenWaClient $client): RedirectResponse
    {
        /** @var array{body?: string|null, idempotency_key: string, ptt?: bool, voice?: bool} $validated */
        $validated = $request->validated();
        $uploadedMedia = $request->file('media');
        $body = trim((string) ($validated['body'] ?? ''));
        $messageType = $uploadedMedia instanceof UploadedFile ? $this->messageTypeForMime($uploadedMedia->getMimeType() ?: 'application/octet-stream') : 'text';

        $existingMessage = Message::query()
            ->where('idempotency_key', $validated['idempotency_key'])
            ->first();

        if ($existingMessage !== null) {
            return $this->redirectToConversation($conversation)
                ->with('success', 'El mensaje ya había sido registrado.');
        }

        $now = now();

        try {
            $message = Message::query()->create([
                'conversation_id' => $conversation->id,
                'direction' => 'outbound',
                'body' => $body !== '' ? $body : null,
                'type' => $messageType,
                'status' => 'pending',
                'idempotency_key' => $validated['idempotency_key'],
                'sent_at' => $now,
            ]);
        } catch (QueryException $exception) {
            $duplicateMessage = Message::query()
                ->where('idempotency_key', $validated['idempotency_key'])
                ->first();

            if ($duplicateMessage !== null) {
                return $this->redirectToConversation($conversation)
                    ->with('success', 'El mensaje ya había sido registrado.');
            }

            throw $exception;
        }

        if ($uploadedMedia instanceof UploadedFile) {
            $this->storeOutboundMedia($message, $uploadedMedia);
        }

        $conversation->update([
            'last_message_id' => $message->id,
            'last_message_at' => $now,
        ]);

        try {
            $session = $client->findSessionByName((string) config('openwa.session_name'));

            if ($session === null) {
                $this->markMessageAsFailed($message, 'No se encontró la sesión configurada de OpenWA.');

                return $this->redirectToConversation($conversation)
                    ->with('error', 'No se encontró la sesión configurada de OpenWA.');
            }

            $sessionId = $this->sessionId($session);

            if ($sessionId === null) {
                $this->markMessageAsFailed($message, 'OpenWA no informó un identificador válido para la sesión.');

                return $this->redirectToConversation($conversation)
                    ->with('error', 'OpenWA no informó un identificador válido para la sesión.');
            }

            if (! $this->isReady($session)) {
                $this->markMessageAsFailed($message, 'La sesión de OpenWA no está lista para enviar mensajes.');

                return $this->redirectToConversation($conversation)
                    ->with('error', 'La sesión de OpenWA no está lista para enviar mensajes.');
            }

            $response = $uploadedMedia instanceof UploadedFile
                ? $this->sendMediaMessage($client, $sessionId, $conversation->external_id, $message, (bool) ($validated['ptt'] ?? $validated['voice'] ?? false))
                : $client->sendTextMessage($sessionId, $conversation->external_id, $body);
            $message->status = 'accepted';

            $externalId = $this->messageExternalId($response);

            if ($message->external_id === null && $externalId !== null) {
                $message->external_id = $externalId;
            }

            $message->error_message = null;
            $message->save();

            return $this->redirectToConversation($conversation)
                ->with('success', 'Mensaje enviado a OpenWA.');
        } catch (ConnectionException|RequestException $exception) {
            $error = $this->readableOpenWaError($exception);
            $this->markMessageAsFailed($message, $error);

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
            throw new \RuntimeException('El mensaje no tiene media almacenada para enviar.');
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
            default => $fallback ?: 'bin',
        };
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

    private function readableOpenWaError(ConnectionException|RequestException $exception): string
    {
        if ($exception instanceof ConnectionException) {
            return 'No se pudo conectar con OpenWA. Revisá que el servicio esté levantado y que OPENWA_BASE_URL apunte a /api.';
        }

        $status = $exception->response->status();
        $message = $exception->response->json('message');

        if (is_string($message) && $message !== '') {
            return "OpenWA respondió con error HTTP {$status}: {$message}";
        }

        return "OpenWA respondió con error HTTP {$status}. Revisá OPENWA_API_KEY, OPENWA_BASE_URL y el estado del servicio.";
    }
}
