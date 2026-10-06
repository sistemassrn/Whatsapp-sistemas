<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;

class WhatsappConnectionStatus
{
    public function __construct(private OpenWaClient $client) {}

    /**
     * @return array{
     *     configured: bool,
     *     baseUrl: string,
     *     sessionName: string,
     *     health: array<string, mixed>|null,
     *     session: array<string, mixed>|null,
     *     qrCode: array<string, mixed>|null,
     *     status: string,
     *     isReady: bool,
     *     isStarted: bool,
     *     canStart: bool,
     *     started: bool,
     *     autoStarted: bool,
     *     lastCheckedAt: string,
     *     error: string|null
     * }
     */
    public function openwa(bool $autoStart = false, bool $includeQr = true): array
    {
        $baseUrl = (string) config('openwa.base_url');
        $sessionName = (string) config('openwa.session_name');
        $configured = $this->isConfigured($baseUrl, $sessionName);
        $health = null;
        $session = null;
        $qrCode = null;
        $started = false;
        $error = null;
        $lastCheckedAt = now()->toIso8601String();

        if (! $configured) {
            return [
                'configured' => false,
                'baseUrl' => $baseUrl,
                'sessionName' => $sessionName,
                'health' => null,
                'session' => null,
                'qrCode' => null,
                'status' => 'not_configured',
                'isReady' => false,
                'isStarted' => false,
                'canStart' => false,
                'started' => false,
                'autoStarted' => false,
                'lastCheckedAt' => $lastCheckedAt,
                'error' => null,
            ];
        }

        try {
            $health = $this->client->health();
        } catch (ConnectionException|RequestException $exception) {
            $error = $this->readableOpenWaError($exception);
        }

        try {
            $session = $this->client->findSessionByName($sessionName);

            if ($autoStart) {
                $session = $this->startSessionIfNeeded($session, $sessionName, $started);
            }

            if (is_array($session)) {
                $sessionId = $this->sessionId($session);

                if ($includeQr && $sessionId !== null && ! $this->isReady($session)) {
                    $qrCode = $this->cachedQr($sessionId);
                }
            }
        } catch (ConnectionException|RequestException $exception) {
            $error = $this->readableOpenWaError($exception);
        }

        $status = is_array($session) ? $this->sessionStatus($session) : null;
        $isReady = is_array($session) && $this->isReady($session);
        $isStarted = is_array($session) && $this->isStarted($session);

        return [
            'configured' => $configured,
            'baseUrl' => $baseUrl,
            'sessionName' => $sessionName,
            'health' => $this->safeHealth($health),
            'session' => $this->safeSession($session),
            'qrCode' => $qrCode,
            'status' => $status ?? ($error === null ? 'disconnected' : 'error'),
            'isReady' => $isReady,
            'isStarted' => $isStarted,
            'canStart' => $configured && ! $isReady && ! $isStarted,
            'started' => $started,
            'autoStarted' => $started,
            'lastCheckedAt' => $lastCheckedAt,
            'error' => $error,
        ];
    }

    /**
     * @return array{
     *     status: string,
     *     isReady: bool,
     *     qr: array<string, mixed>|null,
     *     canStart: bool,
     *     started: bool,
     *     error: string|null
     * }
     */
    public function connection(bool $autoStart = false, bool $includeQr = true): array
    {
        $openwa = $this->openwa($autoStart, $includeQr);
        $session = $openwa['session'];
        $status = is_array($session) ? $this->sessionStatus($session) : null;
        $isReady = is_array($session) && $this->isReady($session);
        $isStarted = is_array($session) && $this->isStarted($session);

        return [
            'status' => $status ?? ($openwa['configured'] ? 'disconnected' : 'not_configured'),
            'isReady' => $isReady,
            'qr' => $isReady ? null : $openwa['qrCode'],
            'canStart' => $openwa['configured'] && ! $isReady && ! $isStarted,
            'started' => $openwa['started'],
            'error' => $openwa['error'],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $session
     * @return array<string, mixed>|null
     */
    private function startSessionIfNeeded(?array $session, string $sessionName, bool &$started): ?array
    {
        if ($session === null) {
            $session = $this->client->createSession($sessionName);
        }

        if ($this->isReady($session) || $this->isStarted($session)) {
            return $session;
        }

        $sessionId = $this->sessionId($session);

        if ($sessionId === null) {
            return $session;
        }

        $this->client->startSession($sessionId);
        $started = true;

        return $this->client->findSessionByName($sessionName) ?? $session;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function cachedQr(string $sessionId): ?array
    {
        $cacheKey = "openwa:qr:{$sessionId}";
        $cached = Cache::get($cacheKey);

        if (is_array($cached) && array_key_exists('qr', $cached)) {
            $qr = $cached['qr'];

            return is_array($qr) ? $qr : null;
        }

        $qr = $this->client->qr($sessionId);

        Cache::put($cacheKey, ['qr' => $qr], now()->addSeconds($qr === null ? 5 : 10));

        return $qr;
    }

    /**
     * @param  array<string, mixed>  $session
     */
    public function sessionId(array $session): ?string
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
    public function isReady(array $session): bool
    {
        $status = $this->sessionStatus($session);

        return $status !== null && strtolower($status) === 'ready';
    }

    /**
     * @param  array<string, mixed>  $session
     */
    public function isStarted(array $session): bool
    {
        $engineLoaded = $this->sessionEngineLoaded($session);

        if ($engineLoaded !== null) {
            return $engineLoaded;
        }

        $status = $this->sessionStatus($session);

        return $status !== null && in_array(strtolower($status), [
            'initializing',
            'qr_ready',
            'authenticating',
            'ready',
            'action_required',
        ], true);
    }

    public function readableOpenWaError(ConnectionException|RequestException $exception): string
    {
        if ($exception instanceof ConnectionException) {
            return 'No pudimos conectar. Reintentá en unos segundos.';
        }

        return 'No pudimos conectar. Reintentá en unos segundos.';
    }

    private function isConfigured(string $baseUrl, string $sessionName): bool
    {
        $apiKey = config('openwa.api_key');

        return $baseUrl !== '' && $sessionName !== '' && is_string($apiKey) && $apiKey !== '';
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function sessionStatus(array $session): ?string
    {
        $status = $session['status'] ?? null;

        if (is_string($status) && $status !== '') {
            return $status;
        }

        foreach (['data', 'session'] as $key) {
            if (isset($session[$key]) && is_array($session[$key])) {
                /** @var array<string, mixed> $nestedSession */
                $nestedSession = $session[$key];

                return $this->sessionStatus($nestedSession);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function sessionEngineLoaded(array $session): ?bool
    {
        $engineLoaded = $session['engineLoaded'] ?? null;

        if (is_bool($engineLoaded)) {
            return $engineLoaded;
        }

        foreach (['data', 'session'] as $key) {
            if (isset($session[$key]) && is_array($session[$key])) {
                /** @var array<string, mixed> $nestedSession */
                $nestedSession = $session[$key];

                return $this->sessionEngineLoaded($nestedSession);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $health
     * @return array<string, mixed>|null
     */
    private function safeHealth(?array $health): ?array
    {
        if ($health === null) {
            return null;
        }

        return [
            'status' => is_string($health['status'] ?? null) ? $health['status'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $session
     * @return array<string, mixed>|null
     */
    private function safeSession(?array $session): ?array
    {
        if ($session === null) {
            return null;
        }

        return [
            'id' => $this->sessionId($session),
            'name' => $this->sessionName($session),
            'status' => $this->sessionStatus($session),
            'engineLoaded' => $this->sessionEngineLoaded($session),
        ];
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function sessionName(array $session): ?string
    {
        $name = $session['name'] ?? null;

        if (is_string($name) && $name !== '') {
            return $name;
        }

        foreach (['data', 'session'] as $key) {
            if (isset($session[$key]) && is_array($session[$key])) {
                /** @var array<string, mixed> $nestedSession */
                $nestedSession = $session[$key];

                return $this->sessionName($nestedSession);
            }
        }

        return null;
    }
}
