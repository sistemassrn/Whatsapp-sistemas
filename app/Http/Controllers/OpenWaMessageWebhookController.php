<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Services\WhatsappHistoricalImportFilter;
use App\Services\WhatsappMessageImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OpenWaMessageWebhookController extends Controller
{
    public function store(Request $request, WhatsappMessageImporter $importer, WhatsappHistoricalImportFilter $historicalImportFilter): JsonResponse
    {
        $configuredSecret = config('openwa.webhook_secret');

        if (is_string($configuredSecret) && trim($configuredSecret) !== '') {
            if (! $this->hasValidWebhookSecret($request, $configuredSecret)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        } elseif (config('openwa.require_webhook_secret')) {
            Log::critical('OpenWA webhook rejected because OPENWA_WEBHOOK_SECRET is not configured.');

            return response()->json(['message' => 'Webhook secret is not configured'], 503);
        }

        // Local/dev environments may omit OPENWA_WEBHOOK_SECRET until OpenWA is wired.
        $payload = $request->all();
        $messagePayload = $importer->messagePayload($payload);
        $fromMe = (bool) data_get($messagePayload, 'fromMe', false);
        $chatExternalId = $this->chatExternalId($messagePayload, $fromMe);
        $messageExternalId = $importer->messageExternalId($messagePayload);
        $chatPayload = $this->chatPayload($payload, $messagePayload);

        if ($chatExternalId === null && $messageExternalId === null) {
            Log::warning('WhatsApp webhook ignored because chat id is missing.', [
                'payload_keys' => array_keys($messagePayload),
            ]);

            return response()->json(['message' => 'Missing chat id'], 422);
        }

        if ($messageExternalId === null) {
            Log::warning('WhatsApp webhook received without message id.', [
                'chat_id' => $chatExternalId,
            ]);
        }

        if (! $historicalImportFilter->shouldImportWebhookMessage($messagePayload, $chatPayload)) {
            Log::info('WhatsApp webhook historical message ignored.', [
                'chat_id' => $chatExternalId,
                'message_id' => $messageExternalId,
            ]);

            return response()->json([
                'status' => 'ignored',
                'reason' => 'historical_unknown_chat',
            ]);
        }

        $result = $importer->importMessage($messagePayload, $chatPayload);
        $message = $result['message'];

        if (! $message instanceof Message) {
            return response()->json(['message' => 'Missing chat id'], 422);
        }

        return response()->json([
            'status' => 'stored',
            'message_id' => $message->id,
        ]);
    }

    private function hasValidWebhookSecret(Request $request, string $configuredSecret): bool
    {
        $configuredSecret = trim($configuredSecret);
        $requestSecret = $request->header('X-Webhook-Secret', $request->header('X-OpenWA-Secret', ''));

        if (is_string($requestSecret) && hash_equals($configuredSecret, $requestSecret)) {
            return true;
        }

        $signature = $request->header('X-OpenWA-Signature', '');

        if (! is_string($signature) || trim($signature) === '') {
            return false;
        }

        $expectedHash = hash_hmac('sha256', $request->getContent(), $configuredSecret);
        $expectedSignature = 'sha256='.$expectedHash;

        return hash_equals($expectedSignature, $signature) || hash_equals($expectedHash, $signature);
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
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $messagePayload
     * @return array<string, mixed>|null
     */
    private function chatPayload(array $payload, array $messagePayload): ?array
    {
        return $this->nestedArray($payload, ['chat', 'data.chat', 'payload.chat'])
            ?? $this->nestedArray($messagePayload, ['chat', '_chat']);
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
}
