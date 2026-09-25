<?php

namespace App\Http\Controllers;

use App\Services\OpenWaClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class WhatsappConnectionController extends Controller
{
    public function index(OpenWaClient $client): Response
    {
        $baseUrl = (string) config('openwa.base_url');
        $sessionName = (string) config('openwa.session_name');
        $session = null;
        $qrCode = null;
        $error = null;

        try {
            $health = $client->health();
        } catch (ConnectionException|RequestException $exception) {
            $health = null;
            $error = $this->readableOpenWaError($exception);
        }

        try {
            $session = $client->findSessionByName($sessionName);

            if (is_array($session)) {
                $sessionId = $this->sessionId($session);

                if ($sessionId !== null && ! $this->isReady($session)) {
                    $qrCode = $client->qr($sessionId);
                }
            }
        } catch (ConnectionException|RequestException $exception) {
            $error = $this->readableOpenWaError($exception);
        }

        return Inertia::render('whatsapp/connect', [
            'operator' => session('testing_operator'),
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
            'openwa' => [
                'configured' => $this->isConfigured($baseUrl, $sessionName),
                'baseUrl' => $baseUrl,
                'sessionName' => $sessionName,
                'health' => $health,
                'session' => $session,
                'qrCode' => $qrCode,
                'error' => $error,
            ],
        ]);
    }

    public function start(OpenWaClient $client): RedirectResponse
    {
        $sessionName = (string) config('openwa.session_name');

        try {
            $session = $client->findSessionByName($sessionName) ?? $client->createSession($sessionName);
            $sessionId = $this->sessionId($session);

            if ($sessionId === null) {
                return redirect()
                    ->route('whatsapp.connect')
                    ->with('error', 'OpenWA respondió la sesión, pero no informó un identificador válido.');
            }

            $client->startSession($sessionId);

            return redirect()
                ->route('whatsapp.connect')
                ->with('success', 'Sesión de WhatsApp iniciada. Si el QR está disponible, va a aparecer al refrescar la pantalla.');
        } catch (ConnectionException|RequestException $exception) {
            return redirect()
                ->route('whatsapp.connect')
                ->with('error', $this->readableOpenWaError($exception));
        }
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function sessionId(array $session): ?string
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

    private function isConfigured(string $baseUrl, string $sessionName): bool
    {
        $apiKey = config('openwa.api_key');

        return $baseUrl !== '' && $sessionName !== '' && is_string($apiKey) && $apiKey !== '';
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function isReady(array $session): bool
    {
        $status = $session['status'] ?? null;

        if (is_string($status) && strtolower($status) === 'ready') {
            return true;
        }

        foreach (['data', 'session'] as $key) {
            if (isset($session[$key]) && is_array($session[$key])) {
                /** @var array<string, mixed> $nestedSession */
                $nestedSession = $session[$key];

                return $this->isReady($nestedSession);
            }
        }

        return false;
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
