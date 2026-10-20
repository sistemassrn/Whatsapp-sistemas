<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreConversationMessageRequest;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\OpenWaClient;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use League\Flysystem\UnableToReadFile;

class ConversationMessageController extends Controller
{
    public function store(StoreConversationMessageRequest $request, Conversation $conversation, OpenWaClient $client): RedirectResponse
    {
        /** @var array{body?: string|null, idempotency_key: string, reply_to_message_id?: int|null, ptt?: bool, voice?: bool} $validated */
        $validated = $request->validated();
        $uploadedMediaFiles = $request->mediaFiles();
        $body = trim((string) ($validated['body'] ?? ''));
        $isMediaSend = count($uploadedMediaFiles) > 0;
        $replyToMessage = $this->replyToMessage($conversation, $validated['reply_to_message_id'] ?? null);
        $quotedMessageId = $replyToMessage?->external_id;
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
                    'reply_to_message_id' => $replyToMessage?->id,
                    'quoted_message_external_id' => $quotedMessageId,
                    'quoted_message_snapshot' => $replyToMessage === null
                        ? null
                        : $this->quotedMessageSnapshot($replyToMessage),
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
            'hidden_at' => null,
            'hidden_reason' => null,
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
                    ? $this->sendMediaMessage($client, $sessionId, $conversation->external_id, $message, (bool) ($validated['ptt'] ?? $validated['voice'] ?? false), $quotedMessageId)
                    : $client->sendTextMessage($sessionId, $conversation->external_id, $message->body ?? '', $quotedMessageId);
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
            if ($this->isDefinitiveSendFailure($exception)) {
                $error = $this->readableSendError($exception);
                $this->markPendingMessagesAsFailed($messages, $error);

                return $this->redirectToConversation($conversation)
                    ->with('error', $error);
            }

            $this->markPendingMessagesAsVerifying($messages);

            return $this->redirectToConversation($conversation);
        }
    }

    public function storeContact(Request $request, Conversation $conversation, OpenWaClient $client): RedirectResponse
    {
        /** @var array{contact_id: int, idempotency_key: string} $validated */
        $validated = $request->validate([
            'contact_id' => ['required', 'integer', 'exists:contacts,id'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ], [
            'contact_id.required' => 'Elegí un contacto para enviar.',
            'contact_id.exists' => 'El contacto seleccionado ya no está disponible.',
            'idempotency_key.required' => 'No se pudo preparar el envío. Actualizá la página e intentá de nuevo.',
            'idempotency_key.string' => 'La clave de envío no es válida. Actualizá la página e intentá de nuevo.',
            'idempotency_key.max' => 'La clave de envío es demasiado larga. Actualizá la página e intentá de nuevo.',
        ]);

        $contact = Contact::query()->findOrFail($validated['contact_id']);
        $contactName = $this->contactDisplayName($contact);
        $contactNumber = $this->contactPhoneNumber($contact);

        if ($contactNumber === null) {
            return $this->redirectToConversation($conversation)
                ->with('error', 'El contacto seleccionado no tiene un teléfono válido para enviar.');
        }

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
                'body' => $contactName,
                'type' => 'contact',
                'status' => 'pending',
                'idempotency_key' => $validated['idempotency_key'],
                'sent_at' => $now,
                'media_metadata' => [
                    'contact' => [
                        'id' => $contact->id,
                        'external_id' => $contact->external_id,
                        'name' => $contactName,
                        'phone' => $contactNumber,
                        'profile_photo_url' => $contact->profile_photo_url,
                    ],
                ],
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

        $conversation->update([
            'last_message_id' => $message->id,
            'last_message_at' => $now,
            'hidden_at' => null,
            'hidden_reason' => null,
        ]);

        try {
            $session = $client->findSessionByName((string) config('openwa.session_name'));

            if ($session === null) {
                $this->markMessageAsFailed($message, 'Conectá WhatsApp para enviar mensajes.');

                return $this->redirectToConversation($conversation)
                    ->with('error', 'Conectá WhatsApp para enviar mensajes.');
            }

            $sessionId = $this->sessionId($session);

            if ($sessionId === null) {
                $this->markMessageAsFailed($message, 'No pudimos enviar el mensaje. Reintentá en unos segundos.');

                return $this->redirectToConversation($conversation)
                    ->with('error', 'No pudimos enviar el mensaje. Reintentá en unos segundos.');
            }

            if (! $this->isReady($session)) {
                $this->markMessageAsFailed($message, 'Conectá WhatsApp para enviar mensajes.');

                return $this->redirectToConversation($conversation)
                    ->with('error', 'Conectá WhatsApp para enviar mensajes.');
            }

            $response = $client->sendContactMessage($sessionId, $conversation->external_id, $contactName, $contactNumber);
            $message->status = 'accepted';

            $externalId = $this->messageExternalId($response);

            if ($message->external_id === null && $externalId !== null) {
                $message->external_id = $externalId;
            }

            $message->error_message = null;
            $message->save();

            return $this->redirectToConversation($conversation)
                ->with('success', 'Contacto enviado.');
        } catch (ConnectionException|RequestException $exception) {
            if ($this->isDefinitiveSendFailure($exception)) {
                $error = $this->readableSendError($exception);
                $this->markMessageAsFailed($message, $error);

                return $this->redirectToConversation($conversation)
                    ->with('error', $error);
            }

            $message->update([
                'status' => 'verifying',
                'error_message' => null,
            ]);

            return $this->redirectToConversation($conversation);
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
     * @param  list<Message>  $messages
     */
    private function markPendingMessagesAsVerifying(array $messages): void
    {
        foreach ($messages as $message) {
            if ($message->status === 'pending') {
                $message->update([
                    'status' => 'verifying',
                    'error_message' => null,
                ]);
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
    private function sendMediaMessage(OpenWaClient $client, string $sessionId, string $chatId, Message $message, bool $ptt, ?string $quotedMessageId): array
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
            'image' => $client->sendImageMessage($sessionId, $chatId, $base64, $message->media_mime_type, $filename, $message->body, $quotedMessageId),
            'video' => $client->sendVideoMessage($sessionId, $chatId, $base64, $message->media_mime_type, $filename, $message->body, $quotedMessageId),
            'audio' => $client->sendAudioMessage($sessionId, $chatId, $base64, $message->media_mime_type, $filename, $ptt, $quotedMessageId),
            default => $client->sendDocumentMessage($sessionId, $chatId, $base64, $message->media_mime_type, $filename, $message->body, $quotedMessageId),
        };
    }

    private function replyToMessage(Conversation $conversation, ?int $replyToMessageId): ?Message
    {
        if ($replyToMessageId === null) {
            return null;
        }

        $replyToMessage = Message::query()
            ->where('conversation_id', $conversation->id)
            ->find($replyToMessageId);

        $externalId = $replyToMessage?->external_id;

        if ($externalId === null || trim($externalId) === '') {
            throw ValidationException::withMessages([
                'reply_to_message_id' => 'El mensaje seleccionado para responder ya no está disponible.',
            ]);
        }

        return $replyToMessage;
    }

    /**
     * @return array{direction: string, type: string, body: string|null, label: string}
     */
    private function quotedMessageSnapshot(Message $message): array
    {
        return [
            'direction' => $message->direction,
            'type' => $message->type,
            'body' => $message->body,
            'label' => $this->messageLabel($message->type),
        ];
    }

    private function messageLabel(string $type): string
    {
        return match ($type) {
            'contact' => 'Contacto',
            'image' => 'Imagen',
            'video' => 'Video',
            'audio' => 'Audio',
            'document' => 'Documento',
            'text' => 'Mensaje',
            default => 'Archivo',
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

    private function contactDisplayName(Contact $contact): string
    {
        foreach ([$contact->name, $contact->push_name, $contact->phone, $contact->external_id] as $candidate) {
            $displayName = $this->cleanContactIdentifier($candidate);

            if ($displayName !== null) {
                return $displayName;
            }
        }

        return 'Contacto';
    }

    private function contactPhoneNumber(Contact $contact): ?string
    {
        $phone = preg_replace('/\D+/', '', (string) $contact->phone);

        if (! is_string($phone) || $phone === '' || strlen($phone) < 6 || strlen($phone) > 15) {
            return null;
        }

        return $phone;
    }

    private function cleanContactIdentifier(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || strtolower($value) === 'mi num' || Str::endsWith($value, '@lid')) {
            return null;
        }

        if (Str::endsWith($value, '@c.us')) {
            $phone = Str::before($value, '@');

            return preg_match('/^\d{6,15}$/', $phone) === 1 ? $phone : null;
        }

        if (str_contains($value, '@')) {
            return null;
        }

        return preg_match('/^[a-f0-9]{24,}$/i', $value) === 1 ? null : $value;
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

    private function isDefinitiveSendFailure(ConnectionException|RequestException $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return false;
        }

        $status = $exception->response->status();

        return $status >= 400 && $status < 500 && ! in_array($status, [408, 425, 429], true);
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
