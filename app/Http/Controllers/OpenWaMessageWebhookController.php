<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class OpenWaMessageWebhookController extends Controller
{
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

        if ($chatExternalId === null) {
            return response()->json(['message' => 'Missing chat id'], 422);
        }

        $messageExternalId = $this->firstString($messagePayload, ['id', 'messageId', '_data.id.id', '_data.id._serialized']);

        if ($messageExternalId !== null && Message::query()->where('external_id', $messageExternalId)->exists()) {
            return response()->json(['status' => 'duplicate']);
        }

        $message = DB::transaction(function () use ($chatExternalId, $fromMe, $messageExternalId, $messagePayload): Message {
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

            $message = Message::query()->create([
                'conversation_id' => $conversation->id,
                'external_id' => $messageExternalId,
                'direction' => $fromMe ? 'outbound' : 'inbound',
                'body' => $this->firstString($messagePayload, ['body', 'text', 'message', 'content', 'caption']),
                'status' => $fromMe ? 'accepted' : 'received',
                'sent_at' => $fromMe ? $timestamp : null,
                'received_at' => $fromMe ? null : $timestamp,
            ]);

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

                return Carbon::createFromTimestamp($timestamp > 9999999999 ? (int) floor($timestamp / 1000) : $timestamp);
            }

            if (is_string($value) && trim($value) !== '') {
                try {
                    return Carbon::parse($value);
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return null;
    }
}
