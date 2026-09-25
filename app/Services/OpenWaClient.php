<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
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
