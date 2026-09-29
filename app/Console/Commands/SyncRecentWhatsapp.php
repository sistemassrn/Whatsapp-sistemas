<?php

namespace App\Console\Commands;

use App\Models\Message;
use App\Models\WhatsappAccount;
use App\Services\OpenWaClient;
use App\Services\WhatsappMessageImporter;
use App\Services\WhatsappMessageMediaDownloader;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

#[Signature('whatsapp:sync-recent {--limit-chats=50} {--limit-messages=30}')]
#[Description('Synchronize recent WhatsApp messages into local persistence')]
class SyncRecentWhatsapp extends Command
{
    private int $chatsScanned = 0;

    private int $messagesCreated = 0;

    private int $messagesSkipped = 0;

    private int $failures = 0;

    /**
     * Execute the console command.
     */
    public function handle(OpenWaClient $client, WhatsappMessageImporter $importer, WhatsappMessageMediaDownloader $mediaDownloader): int
    {
        $sessionName = (string) config('openwa.session_name');
        $limitChats = max(1, (int) $this->option('limit-chats'));
        $limitMessages = max(1, (int) $this->option('limit-messages'));

        if ($sessionName === '') {
            $this->info('No hay sesión configurada.');

            return self::SUCCESS;
        }

        try {
            $session = $client->findSessionByName($sessionName);
        } catch (ConnectionException|RequestException $exception) {
            $this->info($this->readableSyncError($exception));

            return self::SUCCESS;
        }

        if ($session === null) {
            $this->info('No se encontró la sesión configurada.');

            return self::SUCCESS;
        }

        $sessionId = $this->sessionId($session);

        if ($sessionId === null) {
            $this->info('La sesión no informó un identificador válido.');

            return self::SUCCESS;
        }

        $status = $this->sessionStatus($session);
        $this->updateAccount($sessionName, $session, $status);

        if ($status !== 'ready') {
            $this->info("La sesión no está lista. Estado actual: {$status}.");

            return self::SUCCESS;
        }

        try {
            $chats = $this->items($client->chats($sessionId, $limitChats), ['data', 'chats', 'items']);
        } catch (ConnectionException|RequestException $exception) {
            WhatsappAccount::query()
                ->where('name', $sessionName)
                ->update(['last_error' => $this->readableSyncError($exception)]);
            $this->info($this->readableSyncError($exception));

            return self::SUCCESS;
        }

        foreach ($chats as $chat) {
            if (! is_array($chat)) {
                $this->failures++;

                continue;
            }

            $this->syncChat($client, $importer, $mediaDownloader, $sessionId, $chat, $limitMessages);
        }

        $this->info("Chats escaneados: {$this->chatsScanned}");
        $this->info("Mensajes creados: {$this->messagesCreated}");
        $this->info("Mensajes omitidos: {$this->messagesSkipped}");
        $this->info("Fallos: {$this->failures}");

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
    private function syncChat(OpenWaClient $client, WhatsappMessageImporter $importer, WhatsappMessageMediaDownloader $mediaDownloader, string $sessionId, array $chat, int $limitMessages): void
    {
        $chatExternalId = $this->firstString($chat, ['id', 'chatId', 'externalId', '_data.id._serialized', '_data.id']);

        if ($chatExternalId === null) {
            $this->failures++;

            return;
        }

        $this->chatsScanned++;

        try {
            $messages = $this->items($client->chatMessages($sessionId, $chatExternalId, $limitMessages), ['data', 'messages', 'items']);
        } catch (ConnectionException|RequestException) {
            $this->failures++;

            return;
        }

        $conversation = null;

        foreach ($messages as $messagePayload) {
            if (! is_array($messagePayload)) {
                $this->failures++;

                continue;
            }

            $result = $importer->importMessage($messagePayload, $chat);

            if ($result['created']) {
                $this->messagesCreated++;
            } else {
                $this->messagesSkipped++;
            }

            $message = $result['message'];

            if ($message instanceof Message) {
                $mediaDownloader->attempt($message, $sessionId, $chatExternalId, ['source' => 'sync-recent']);
                $conversation = $message->conversation;
            }
        }

        if ($conversation !== null) {
            $importer->refreshConversationLastMessage($conversation);
        }
    }

    /**
     * @param  array<string, mixed>|array<int, mixed>  $payload
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

    private function mapAccountStatus(string $status): string
    {
        return match ($status) {
            'ready', 'connected' => 'connected',
            'starting', 'qr', 'qr_ready', 'pairing', 'connecting' => 'connecting',
            default => 'disconnected',
        };
    }

    private function readableSyncError(ConnectionException|RequestException $exception): string
    {
        if ($exception instanceof ConnectionException) {
            return 'No se pudo conectar con el servicio de mensajes.';
        }

        return 'El servicio de mensajes respondió con error.';
    }
}
