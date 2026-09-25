<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

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
     *     started: bool,
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

        if (! $configured) {
            return [
                'configured' => false,
                'baseUrl' => $baseUrl,
                'sessionName' => $sessionName,
                'health' => null,
                'session' => null,
                'qrCode' => null,
                'started' => false,
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
                    $qrCode = $this->client->qr($sessionId);
                }
            }
        } catch (ConnectionException|RequestException $exception) {
            $error = $this->readableOpenWaError($exception);
        }

        return [
            'configured' => $configured,
            'baseUrl' => $baseUrl,
            'sessionName' => $sessionName,
            'health' => $health,
            'session' => $session,
            'qrCode' => $qrCode,
            'started' => $started,
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
            return 'No se pudo conectar con OpenWA. Revisá que el servicio esté levantado y que OPENWA_BASE_URL apunte a /api.';
        }

        $status = $exception->response->status();

        return "OpenWA respondió con error HTTP {$status}. Revisá OPENWA_API_KEY, OPENWA_BASE_URL y el estado del servicio.";
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
}
