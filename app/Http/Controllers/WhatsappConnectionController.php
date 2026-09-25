<?php

namespace App\Http\Controllers;

use App\Services\OpenWaClient;
use App\Services\WhatsappConnectionStatus;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WhatsappConnectionController extends Controller
{
    public function index(Request $request, WhatsappConnectionStatus $connectionStatus): Response|RedirectResponse
    {
        $openwa = $connectionStatus->openwa(autoStart: true, includeQr: true);

        if (is_array($openwa['session']) && $connectionStatus->isReady($openwa['session'])) {
            return redirect()->route('whatsapp.conversations');
        }

        return Inertia::render('whatsapp/connect', [
            'operator' => $request->user() === null ? null : [
                'name' => $request->user()->nombre ?: $request->user()->usuario,
                'usuario' => $request->user()->usuario,
            ],
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
            'openwa' => $openwa,
        ]);
    }

    public function start(OpenWaClient $client, WhatsappConnectionStatus $connectionStatus): RedirectResponse
    {
        $sessionName = (string) config('openwa.session_name');

        try {
            $session = $client->findSessionByName($sessionName) ?? $client->createSession($sessionName);
            $sessionId = $connectionStatus->sessionId($session);

            if ($sessionId === null) {
                return redirect()
                    ->back()
                    ->with('error', 'OpenWA respondió la sesión, pero no informó un identificador válido.');
            }

            $client->startSession($sessionId);

            return redirect()
                ->back()
                ->with('success', 'Sesión de WhatsApp iniciada. Si el QR está disponible, va a aparecer al refrescar la pantalla.');
        } catch (ConnectionException|RequestException $exception) {
            return redirect()
                ->back()
                ->with('error', $connectionStatus->readableOpenWaError($exception));
        }
    }

    public function disconnect(OpenWaClient $client, WhatsappConnectionStatus $connectionStatus): RedirectResponse
    {
        $sessionName = (string) config('openwa.session_name');

        try {
            $session = $client->findSessionByName($sessionName);

            if ($session === null) {
                return redirect()
                    ->route('whatsapp.connect')
                    ->with('success', 'No había una sesión de WhatsApp activa para cerrar.');
            }

            $sessionId = $connectionStatus->sessionId($session);

            if ($sessionId === null) {
                return redirect()
                    ->route('whatsapp.connect')
                    ->with('error', 'OpenWA encontró la sesión, pero no informó un identificador válido para cerrarla.');
            }

            $client->logoutSession($sessionId);

            return redirect()
                ->route('whatsapp.connect')
                ->with('success', 'Sesión de WhatsApp cerrada. Para volver a operar, escaneá el QR si OpenWA lo solicita.');
        } catch (ConnectionException|RequestException $exception) {
            return redirect()
                ->route('whatsapp.connect')
                ->with('error', $connectionStatus->readableOpenWaError($exception));
        }
    }
}
