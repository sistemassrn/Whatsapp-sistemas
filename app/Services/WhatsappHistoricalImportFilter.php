<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class WhatsappHistoricalImportFilter
{
    /**
     * @param  array<string, mixed>  $chat
     */
    public function shouldSyncChat(array $chat, array $recentMessages, CarbonInterface $syncCutoff): bool
    {
        if ($this->isSavedOrScheduledChat($chat)) {
            return true;
        }

        if (! $this->shouldImportUnknownHistoricalChats() && $this->isUnknownHistoricalChat($chat)) {
            return false;
        }

        if ($recentMessages !== []) {
            return true;
        }

        $chatTimestamp = $this->payloadTimestamp($chat, ['timestamp', 't', 'time', 'lastMessage.timestamp', 'lastMessage.t', 'lastMessageAt', 'updatedAt']);

        return $chatTimestamp !== null && $chatTimestamp->greaterThanOrEqualTo($syncCutoff);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function isRecentPayload(array $payload, CarbonInterface $syncCutoff): bool
    {
        $timestamp = $this->payloadTimestamp($payload, ['timestamp', 't', 'time', 'createdAt', 'date']);

        return $timestamp === null || $timestamp->greaterThanOrEqualTo($syncCutoff);
    }

    /**
     * @param  array<string, mixed>  $messagePayload
     * @param  array<string, mixed>|null  $chatPayload
     */
    public function shouldImportWebhookMessage(array $messagePayload, ?array $chatPayload = null): bool
    {
        $timestamp = $this->payloadTimestamp($messagePayload, ['timestamp', 't', 'time', 'createdAt', 'date']);

        if ($timestamp === null || $timestamp->greaterThanOrEqualTo($this->syncCutoff())) {
            return true;
        }

        $chat = $this->webhookChatPayload($messagePayload, $chatPayload);

        if ($this->isSavedOrScheduledChat($chat)) {
            return true;
        }

        return $this->shouldImportUnknownHistoricalChats() || ! $this->isUnknownHistoricalChat($chat);
    }

    public function syncCutoff(): CarbonInterface
    {
        return now()->subDays(max(1, (int) config('openwa.import_max_age_days', 240)));
    }

    /**
     * @param  array<string, mixed>  $chat
     */
    public function isSavedOrScheduledChat(array $chat): bool
    {
        foreach (['isSaved', 'saved', 'isScheduled', 'scheduled', 'isMyContact', 'contact.isMyContact', 'contact.isAddressBookContact'] as $key) {
            if (data_get($chat, $key) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $chat
     */
    private function isUnknownHistoricalChat(array $chat): bool
    {
        $chatExternalId = $this->firstString($chat, ['id', 'chatId', 'externalId', '_data.id._serialized', '_data.id']);

        if ($chatExternalId !== null && str_contains($chatExternalId, '@g.us')) {
            return false;
        }

        $contact = $this->nestedArray($chat, ['contact', '_contact', 'sender']) ?? [];

        foreach ([$chat, $contact] as $payload) {
            foreach (['name', 'shortName', 'formattedName', 'pushName', 'notifyName', 'phone', 'number', 'user'] as $key) {
                $value = $this->firstString($payload, [$key]);

                if ($value !== null && ! $this->isTechnicalIdentifier($value)) {
                    return false;
                }
            }
        }

        $title = $this->firstString($chat, ['title', 'formattedTitle']);

        return $title === null || $this->isTechnicalIdentifier($title);
    }

    private function shouldImportUnknownHistoricalChats(): bool
    {
        return (bool) config('openwa.import_unknown_historical_chats', false);
    }

    /**
     * @param  array<string, mixed>  $messagePayload
     * @param  array<string, mixed>|null  $chatPayload
     * @return array<string, mixed>
     */
    private function webhookChatPayload(array $messagePayload, ?array $chatPayload): array
    {
        $chat = $chatPayload ?? $this->nestedArray($messagePayload, ['chat', '_chat']) ?? [];

        foreach (['id', 'chatId', 'externalId', '_data.id._serialized', '_data.id'] as $key) {
            if (data_get($chat, $key) !== null) {
                return $chat;
            }
        }

        $fromMe = (bool) data_get($messagePayload, 'fromMe', false);
        $chat['id'] = $this->firstString($messagePayload, ['chatId', 'conversationId', '_data.id.remote', 'message.chatId'])
            ?? $this->firstString($messagePayload, $fromMe ? ['to', 'from'] : ['from', 'to']);

        if (! isset($chat['contact'])) {
            $chat['contact'] = $this->nestedArray($messagePayload, ['sender', 'contact', '_contact']);
        }

        $chat['title'] = $this->firstString($chat, ['title', 'formattedTitle'])
            ?? $this->firstString($messagePayload, ['chat.name', 'chat.title', 'chat.formattedTitle']);

        return $chat;
    }

    private function isTechnicalIdentifier(string $value): bool
    {
        $value = trim($value);

        return $value === ''
            || Str::endsWith($value, ['@c.us', '@lid'])
            || str_contains($value, '@')
            || preg_match('/^[a-f0-9]{24,}$/i', $value) === 1;
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
     */
    private function payloadTimestamp(array $payload, array $keys): ?Carbon
    {
        foreach ($keys as $key) {
            $value = data_get($payload, $key);

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
}
