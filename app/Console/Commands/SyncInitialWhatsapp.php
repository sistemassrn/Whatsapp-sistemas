<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\WhatsappAccount;
use App\Services\OpenWaClient;
use App\Services\WhatsappHistoricalImportFilter;
use App\Services\WhatsappMessageImporter;
use Carbon\CarbonInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

#[Signature('whatsapp:sync-initial {--limit-chats=30} {--limit-messages=50}')]
#[Description('Synchronize initial chats and messages from OpenWA into local persistence')]
class SyncInitialWhatsapp extends Command
{
    private int $chatsProcessed = 0;

    private int $conversationsUpserted = 0;

    private int $messagesCreated = 0;

    private int $messagesSkipped = 0;

    /**
     * Execute the console command.
     */
    public function handle(OpenWaClient $client, WhatsappMessageImporter $importer, WhatsappHistoricalImportFilter $filter): int
    {
        $sessionName = (string) config('openwa.session_name');
        $limitChats = max(1, (int) $this->option('limit-chats'));
        $limitMessages = max(1, (int) $this->option('limit-messages'));
        $syncCutoff = $filter->syncCutoff();

        if ($sessionName === '') {
            $this->error('OPENWA_SESSION_NAME no está configurado.');

            return self::FAILURE;
        }

        try {
            $session = $client->findSessionByName($sessionName);
        } catch (ConnectionException|RequestException $exception) {
            $this->error($this->readableOpenWaError($exception));

            return self::FAILURE;
        }

        if ($session === null) {
            $this->error("No se encontró la sesión OpenWA configurada: {$sessionName}.");

            return self::FAILURE;
        }

        $sessionId = $this->sessionId($session);

        if ($sessionId === null) {
            $this->error('OpenWA encontró la sesión, pero no informó un identificador válido.');

            return self::FAILURE;
        }

        $status = $this->sessionStatus($session);
        $account = $this->updateAccount($sessionName, $session, $status);

        if ($status !== 'ready') {
            $this->error("La sesión OpenWA no está lista. Estado actual: {$status}.");

            return self::FAILURE;
        }

        try {
            $chats = $this->items($client->chats($sessionId, $limitChats), ['data', 'chats', 'items']);
        } catch (ConnectionException|RequestException $exception) {
            $account->forceFill(['last_error' => $this->readableOpenWaError($exception)])->save();
            $this->error($this->readableOpenWaError($exception));

            return self::FAILURE;
        }

        foreach ($chats as $chat) {
            if (! is_array($chat)) {
                $this->warn('Chat omitido: OpenWA devolvió una forma no soportada.');

                continue;
            }

            $this->syncChat($client, $importer, $filter, $sessionId, $chat, $limitMessages, $syncCutoff);

            usleep(400000);
        }

        $this->info("Chats procesados: {$this->chatsProcessed}");
        $this->info("Conversaciones upserted: {$this->conversationsUpserted}");
        $this->info("Mensajes creados: {$this->messagesCreated}");
        $this->info("Mensajes omitidos: {$this->messagesSkipped}");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function updateAccount(string $sessionName, array $session, string $status): WhatsappAccount
    {
        $account = WhatsappAccount::query()->firstOrNew(['name' => $sessionName]);

        $account->fill([
            'status' => $status === 'ready' ? 'connected' : $this->mapAccountStatus($status),
            'phone_number' => $this->firstString($session, ['phone', 'phoneNumber', 'me.id', 'me.phone', 'data.phone', 'session.phone']),
            'last_seen_at' => $status === 'ready' ? now() : $account->last_seen_at,
            'last_error' => $status === 'ready' ? null : $account->last_error,
        ])->save();

        return $account;
    }

    /**
     * @param  array<string, mixed>  $chat
     */
    private function syncChat(OpenWaClient $client, WhatsappMessageImporter $importer, WhatsappHistoricalImportFilter $filter, string $sessionId, array $chat, int $limitMessages, CarbonInterface $syncCutoff): void
    {
        $chatExternalId = $this->firstString($chat, ['id', 'chatId', 'externalId', '_data.id._serialized', '_data.id']);

        if ($chatExternalId === null) {
            $this->warn('Chat omitido: no tiene identificador externo.');

            return;
        }

        $this->chatsProcessed++;

        try {
            $messages = $this->items($client->chatMessages($sessionId, $chatExternalId, $limitMessages), ['data', 'messages', 'items']);
        } catch (ConnectionException|RequestException $exception) {
            $this->warn("No se pudieron leer mensajes del chat {$chatExternalId}: {$this->readableOpenWaError($exception)}");

            return;
        }

        $recentMessages = array_values(array_filter($messages, fn (mixed $message): bool => is_array($message) && $filter->isRecentPayload($message, $syncCutoff)));

        if (! $filter->shouldSyncChat($chat, $recentMessages, $syncCutoff)) {
            return;
        }

        $conversation = Conversation::query()->updateOrCreate(
            ['external_id' => $chatExternalId],
            [
                'contact_id' => $this->upsertContact($chat, $chatExternalId)?->id,
                'title' => $this->conversationTitle($chat, $chatExternalId),
            ],
        );
        $this->conversationsUpserted++;

        $newestMessage = null;
        $newestTimestamp = null;

        foreach ($recentMessages as $messagePayload) {
            if (! is_array($messagePayload)) {
                $this->warn("Mensaje omitido en {$chatExternalId}: OpenWA devolvió una forma no soportada.");

                continue;
            }

            $result = $importer->importMessage($messagePayload, $chat, unhideConversation: false);
            $message = $result['message'];
            $created = $result['created'];
            $messageTimestamp = $message?->sent_at ?? $message?->received_at ?? $message?->created_at;

            if ($message !== null && ($newestTimestamp === null || ($messageTimestamp !== null && $messageTimestamp->greaterThan($newestTimestamp)))) {
                $newestMessage = $message;
                $newestTimestamp = $messageTimestamp;
            }

            if ($created) {
                $this->messagesCreated++;
            } else {
                $this->messagesSkipped++;
            }
        }

        if ($newestMessage !== null) {
            $conversation->forceFill([
                'last_message_id' => $newestMessage->id,
                'last_message_at' => $newestTimestamp,
            ])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $chat
     */
    private function upsertContact(array $chat, string $chatExternalId): ?Contact
    {
        if ($this->isGroupChat($chat, $chatExternalId)) {
            return null;
        }

        $contact = $this->nestedArray($chat, ['contact', '_contact', 'sender']) ?? $chat;
        $contactExternalId = $this->firstString($contact, ['id', 'contactId', 'externalId', '_serialized']) ?? $chatExternalId;

        return Contact::query()->updateOrCreate(
            ['external_id' => $contactExternalId],
            [
                'name' => $this->firstString($contact, ['name', 'shortName', 'formattedName']) ?? $this->firstString($chat, ['name', 'title']),
                'push_name' => $this->firstString($contact, ['pushName', 'notifyName']) ?? $this->firstString($chat, ['pushName']),
                'phone' => $this->firstString($contact, ['phone', 'number', 'user']),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $containerKeys
     * @return list<mixed>
     */
    private function items(array $payload, array $containerKeys): array
    {
        if (array_is_list($payload)) {
            return $payload;
        }

        foreach ($containerKeys as $key) {
            $value = data_get($payload, $key);

            if (is_array($value)) {
                return array_is_list($value) ? $value : [$value];
            }
        }

        return [$payload];
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
     * @param  array<string, mixed>  $chat
     */
    private function conversationTitle(array $chat, string $fallback): string
    {
        return $this->firstString($chat, ['name', 'title', 'pushName', 'formattedTitle']) ?? $fallback;
    }

    /**
     * @param  array<string, mixed>  $chat
     */
    private function isGroupChat(array $chat, string $chatExternalId): bool
    {
        return data_get($chat, 'isGroup') === true || str_contains($chatExternalId, '@g.us');
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

    private function mapAccountStatus(string $openWaStatus): string
    {
        return match ($openWaStatus) {
            'ready', 'connected' => 'connected',
            'starting', 'qr', 'pairing', 'connecting' => 'connecting',
            default => 'disconnected',
        };
    }

    private function readableOpenWaError(ConnectionException|RequestException $exception): string
    {
        if ($exception instanceof ConnectionException) {
            return 'No se pudo conectar con OpenWA. Revisá que el servicio esté levantado y que OPENWA_BASE_URL apunte a /api.';
        }

        $status = $exception->response->status();

        return "OpenWA respondió con error HTTP {$status}. Revisá OPENWA_API_KEY, OPENWA_BASE_URL y el estado del servicio.";
    }
}
