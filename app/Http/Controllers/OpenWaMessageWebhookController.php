<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Services\OpenWaClient;
use App\Services\WhatsappMessageImporter;
use App\Services\WhatsappMessageMediaDownloader;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OpenWaMessageWebhookController extends Controller
{
    public function store(Request $request, WhatsappMessageImporter $importer, WhatsappMessageMediaDownloader $mediaDownloader, OpenWaClient $client): JsonResponse
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
        $messagePayload = $importer->messagePayload($payload);
        $fromMe = (bool) data_get($messagePayload, 'fromMe', false);
        $chatExternalId = $this->chatExternalId($messagePayload, $fromMe);

        if ($chatExternalId === null) {
            Log::warning('WhatsApp webhook ignored because chat id is missing.', [
                'payload_keys' => array_keys($messagePayload),
            ]);

            return response()->json(['message' => 'Missing chat id'], 422);
        }

        $messageExternalId = $this->firstString($messagePayload, ['id', 'messageId', '_data.id.id', '_data.id._serialized']);

        if ($messageExternalId === null) {
            Log::warning('WhatsApp webhook received without message id.', [
                'chat_id' => $chatExternalId,
            ]);
        }

        if ($messageExternalId !== null && Message::query()->where('external_id', $messageExternalId)->exists()) {
            return response()->json(['status' => 'duplicate']);
        }

        $result = $importer->importMessage($messagePayload);
        $message = $result['message'];

        if (! $message instanceof Message) {
            return response()->json(['message' => 'Missing chat id'], 422);
        }

        $sessionId = $this->firstString($payload, ['sessionId', 'session.id', 'data.sessionId', 'payload.sessionId'])
            ?? $this->firstString($messagePayload, ['sessionId', 'session.id']);

        if ($sessionId === null && $mediaDownloader->shouldAttempt($message)) {
            $sessionId = $this->configuredReadySessionId($client);
        }

        if ($sessionId !== null) {
            $mediaDownloader->attempt($message, $sessionId, $chatExternalId, ['source' => 'webhook']);
        }

        return response()->json([
            'status' => 'stored',
            'message_id' => $message->id,
        ]);
    }

    private function configuredReadySessionId(OpenWaClient $client): ?string
    {
        $sessionName = (string) config('openwa.session_name');

        if (trim($sessionName) === '') {
            return null;
        }

        try {
            $session = $client->findSessionByName($sessionName);
        } catch (ConnectionException|RequestException) {
            return null;
        }

        if ($session === null || $this->sessionStatus($session) !== 'ready') {
            return null;
        }

        return $this->sessionId($session);
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
     * @param  array<string, mixed>  $session
     */
    private function sessionId(array $session): ?string
    {
        $sessionId = $this->firstString($session, ['id', '_id', 'sessionId']);

        if ($sessionId !== null) {
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
    private function sessionStatus(array $session): string
    {
        $status = $this->firstString($session, ['status', 'state']);

        if ($status !== null) {
            return strtolower($status);
        }

        foreach (['data', 'session'] as $key) {
            if (isset($session[$key]) && is_array($session[$key])) {
                /** @var array<string, mixed> $nestedSession */
                $nestedSession = $session[$key];

                return $this->sessionStatus($nestedSession);
            }
        }

        return 'unknown';
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
