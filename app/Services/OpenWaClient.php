<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class OpenWaClient
{
    /**
     * @return array<string, mixed>
     */
    public function health(): array
    {
        return $this->request()
            ->get('health')
            ->throw()
            ->json();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findSessionByName(string $name): ?array
    {
        $response = $this->request()
            ->get('sessions', ['name' => $name])
            ->throw()
            ->json();

        if (! is_array($response)) {
            return null;
        }

        if (array_is_list($response)) {
            return $this->findNamedSessionInList($response, $name);
        }

        if (isset($response['data']) && is_array($response['data'])) {
            if (array_is_list($response['data'])) {
                return $this->findNamedSessionInList($response['data'], $name);
            }

            /** @var array<string, mixed> $session */
            $session = $response['data'];

            return $session;
        }

        if (isset($response['sessions']) && is_array($response['sessions'])) {
            return $this->findNamedSessionInList($response['sessions'], $name);
        }

        /** @var array<string, mixed> $session */
        $session = $response;

        return $session;
    }

    /**
     * @return array<string, mixed>
     */
    public function createSession(string $name): array
    {
        return $this->request()
            ->post('sessions', ['name' => $name])
            ->throw()
            ->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function startSession(string $sessionId): array
    {
        return $this->request()
            ->post("sessions/{$sessionId}/start")
            ->throw()
            ->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function logoutSession(string $sessionId): array
    {
        return $this->request()
            ->post("sessions/{$sessionId}/logout")
            ->throw()
            ->json();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function qr(string $sessionId): ?array
    {
        $response = $this->request()->get("sessions/{$sessionId}/qr");

        if ($response->notFound() || $response->noContent()) {
            return null;
        }

        return $response->throw()->json();
    }

    /**
     * @return array<string, mixed>|array<int, mixed>
     */
    public function chats(string $sessionId, int $limit = 30, int $offset = 0): array
    {
        return $this->request()
            ->get("sessions/{$sessionId}/chats", [
                'limit' => $limit,
                'offset' => $offset,
            ])
            ->throw()
            ->json();
    }

    /**
     * @return array<string, mixed>|array<int, mixed>
     */
    public function chatMessages(string $sessionId, string $chatId, int $limit = 50): array
    {
        $encodedChatId = rawurlencode($chatId);

        return $this->request()
            ->get("sessions/{$sessionId}/messages/{$encodedChatId}/history", [
                'limit' => $limit,
            ])
            ->throw()
            ->json();
    }

    /**
     * @return array{binary?: string, data?: string, media?: mixed, mimetype?: string, mimeType?: string, filename?: string, fileName?: string, content_type?: string, content_disposition?: string}|null
     */
    public function downloadMessageMedia(string $sessionId, string $chatId, string $messageId): ?array
    {
        $encodedChatId = rawurlencode($chatId);
        $encodedMessageId = rawurlencode($messageId);

        $response = $this->request()
            ->timeout(max((int) config('openwa.media_timeout'), (int) config('openwa.timeout')))
            ->withHeaders(['Accept' => '*/*'])
            ->get("sessions/{$sessionId}/messages/{$encodedChatId}/{$encodedMessageId}/media");

        if ($response->notFound() || $response->noContent()) {
            return null;
        }

        $response->throw();

        if ($this->isJsonResponse($response)) {
            $json = $response->json();

            return is_array($json) ? $json : null;
        }

        return [
            'binary' => $response->body(),
            'content_type' => $response->header('Content-Type'),
            'content_disposition' => $response->header('Content-Disposition'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function downloadMessageMediaFromHistory(string $sessionId, string $chatId, string $messageId, int $limit = 20): ?array
    {
        $encodedChatId = rawurlencode($chatId);

        $response = $this->request()
            ->timeout(max((int) config('openwa.media_timeout'), (int) config('openwa.timeout')))
            ->get("sessions/{$sessionId}/messages/{$encodedChatId}/history", [
                'limit' => max(1, $limit),
                'includeMedia' => 'true',
            ])
            ->throw()
            ->json();

        foreach ($this->items($response, ['data', 'messages', 'items']) as $message) {
            if (! is_array($message) || ! $this->isSameMessage($message, $messageId)) {
                continue;
            }

            $media = Arr::get($message, 'media', Arr::get($message, 'metadata.media'));

            if (! is_array($media)) {
                return null;
            }

            /** @var array<string, mixed> $media */
            return $media;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function sendTextMessage(string $sessionId, string $chatId, string $text): array
    {
        return $this->request()
            ->post("sessions/{$sessionId}/messages/send-text", [
                'chatId' => $chatId,
                'text' => $text,
            ])
            ->throw()
            ->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function editMessage(string $sessionId, string $chatId, string $messageId, string $body): array
    {
        return $this->request()
            ->post("sessions/{$sessionId}/messages/edit", [
                'chatId' => $chatId,
                'messageId' => $messageId,
                'body' => $body,
            ])
            ->throw()
            ->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteMessage(string $sessionId, string $chatId, string $messageId, bool $everyone = true): array
    {
        return $this->request()
            ->post("sessions/{$sessionId}/messages/delete", [
                'chatId' => $chatId,
                'messageId' => $messageId,
                'forEveryone' => $everyone,
            ])
            ->throw()
            ->json();
    }

    public function contactProfilePicture(string $sessionId, string $contactId): ?string
    {
        $encodedContactId = rawurlencode($contactId);

        $response = $this->request()
            ->get("sessions/{$sessionId}/contacts/{$encodedContactId}/profile-picture")
            ->throw()
            ->json();

        if (! is_array($response)) {
            return null;
        }

        foreach (['url', 'profilePicUrl', 'picture', 'data.url', 'data.profilePicUrl', 'data.picture'] as $key) {
            $url = Arr::get($response, $key);

            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function sendImageMessage(string $sessionId, string $chatId, string $base64, string $mimeType, string $filename, ?string $caption = null): array
    {
        return $this->sendMediaMessage($sessionId, 'send-image', [
            'chatId' => $chatId,
            'base64' => $base64,
            'mimetype' => $mimeType,
            'filename' => $filename,
            'caption' => $caption,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function sendVideoMessage(string $sessionId, string $chatId, string $base64, string $mimeType, string $filename, ?string $caption = null): array
    {
        return $this->sendMediaMessage($sessionId, 'send-video', [
            'chatId' => $chatId,
            'base64' => $base64,
            'mimetype' => $mimeType,
            'filename' => $filename,
            'caption' => $caption,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function sendAudioMessage(string $sessionId, string $chatId, string $base64, string $mimeType, string $filename, bool $ptt = false): array
    {
        return $this->sendMediaMessage($sessionId, 'send-audio', [
            'chatId' => $chatId,
            'base64' => $base64,
            'mimetype' => $mimeType,
            'filename' => $filename,
            'ptt' => $ptt,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function sendDocumentMessage(string $sessionId, string $chatId, string $base64, string $mimeType, string $filename, ?string $caption = null): array
    {
        return $this->sendMediaMessage($sessionId, 'send-document', [
            'chatId' => $chatId,
            'base64' => $base64,
            'mimetype' => $mimeType,
            'filename' => $filename,
            'caption' => $caption,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sendMediaMessage(string $sessionId, string $endpoint, array $payload): array
    {
        return $this->request()
            ->post("sessions/{$sessionId}/messages/{$endpoint}", $payload)
            ->throw()
            ->json();
    }

    private function request(): PendingRequest
    {
        $request = Http::baseUrl(rtrim((string) config('openwa.base_url'), '/'))
            ->timeout((int) config('openwa.timeout'))
            ->acceptJson()
            ->asJson();

        $apiKey = config('openwa.api_key');

        if (is_string($apiKey) && $apiKey !== '') {
            $request = $request->withHeaders([
                'X-API-Key' => $apiKey,
            ]);
        }

        return $request;
    }

    private function isJsonResponse(Response $response): bool
    {
        $contentType = strtolower((string) $response->header('Content-Type'));

        return str_contains($contentType, 'application/json') || str_contains($contentType, '+json');
    }

    /**
     * @param  list<string>  $containerKeys
     * @return list<mixed>
     */
    private function items(mixed $payload, array $containerKeys): array
    {
        if (is_array($payload) && array_is_list($payload)) {
            return $payload;
        }

        if (! is_array($payload)) {
            return [];
        }

        foreach ($containerKeys as $key) {
            $value = Arr::get($payload, $key);

            if (is_array($value)) {
                return array_is_list($value) ? $value : [$value];
            }
        }

        return [$payload];
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function isSameMessage(array $message, string $messageId): bool
    {
        foreach (['id', 'messageId', '_data.id.id', '_data.id._serialized'] as $key) {
            $candidate = Arr::get($message, $key);

            if (is_string($candidate) && $candidate === $messageId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, mixed>  $sessions
     * @return array<string, mixed>|null
     */
    private function findNamedSessionInList(array $sessions, string $name): ?array
    {
        foreach ($sessions as $session) {
            if (! is_array($session)) {
                continue;
            }

            if (($session['name'] ?? null) === $name) {
                /** @var array<string, mixed> $namedSession */
                $namedSession = $session;

                return $namedSession;
            }
        }

        return null;
    }
}
