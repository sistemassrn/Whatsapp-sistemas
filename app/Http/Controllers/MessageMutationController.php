<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Services\OpenWaClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class MessageMutationController extends Controller
{
    public function react(Request $request, Message $message, OpenWaClient $client): JsonResponse
    {
        $emoji = $request->input('emoji');

        if (! $request->has('emoji') || ($emoji !== null && ! is_string($emoji))) {
            return response()->json([
                'message' => 'La reacción debe ser un emoji Unicode válido.',
                'errors' => ['emoji' => ['La reacción debe ser un emoji Unicode válido.']],
            ], 422);
        }

        $emoji = $emoji ?? '';

        if (! $this->isValidReactionEmoji($emoji)) {
            return response()->json([
                'message' => 'La reacción debe ser un emoji Unicode válido.',
                'errors' => ['emoji' => ['La reacción debe ser un emoji Unicode válido.']],
            ], 422);
        }

        if (! $this->canReactToMessage($message)) {
            return response()->json([
                'message' => 'No se puede reaccionar a este mensaje.',
                'errors' => ['message' => ['No se puede reaccionar a este mensaje.']],
            ], 422);
        }

        try {
            $sessionId = $this->readySessionId($client);
            $client->reactToMessage($sessionId, $message->remoteChatId(), (string) $message->external_id, $emoji);
            $acceptedAt = now();

            $message->update([
                'accepted_reaction_emoji' => $emoji,
                'accepted_reaction_at' => $acceptedAt,
            ]);

            return response()->json([
                'status' => 'accepted',
                'accepted_reaction_emoji' => $emoji,
                'accepted_reaction_at' => $acceptedAt->toISOString(),
            ]);
        } catch (ConnectionException|RequestException) {
            return response()->json(['message' => 'No se pudo actualizar la reacción.'], 502);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $this->safeRuntimeMutationError($exception)], 503);
        }
    }

    public function update(Request $request, Message $message, OpenWaClient $client): RedirectResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
        ]);

        $body = trim($validated['body']);

        if ($body === '') {
            return $this->redirectToConversation($message)
                ->withErrors(['body' => 'El mensaje no puede estar vacío.']);
        }

        $guardError = $this->mutationGuardError($message, 'editar');

        if ($guardError !== null) {
            return $this->redirectToConversation($message)->with('error', $guardError);
        }

        try {
            $sessionId = $this->readySessionId($client);
            $client->editMessage($sessionId, $message->remoteChatId(), (string) $message->external_id, $body);

            $message->update([
                'body' => $body,
                'edited_at' => now(),
                'remote_edit_status' => 'accepted',
                'edit_error' => null,
            ]);

            return $this->redirectToConversation($message)->with('success', 'Mensaje editado.');
        } catch (ConnectionException|RequestException $exception) {
            $error = $this->readableMutationError($exception);

            $message->update([
                'remote_edit_status' => 'failed',
                'edit_error' => $error,
            ]);

            return $this->redirectToConversation($message)->with('error', $error);
        } catch (\RuntimeException $exception) {
            $error = $this->safeRuntimeMutationError($exception);

            $message->update([
                'remote_edit_status' => 'failed',
                'edit_error' => $error,
            ]);

            return $this->redirectToConversation($message)->with('error', $error);
        }
    }

    public function destroy(Message $message, OpenWaClient $client): RedirectResponse
    {
        $guardError = $this->mutationGuardError($message, 'eliminar');

        if ($guardError !== null) {
            return $this->redirectToConversation($message)->with('error', $guardError);
        }

        try {
            $sessionId = $this->readySessionId($client);
            $client->deleteMessage($sessionId, $message->remoteChatId(), (string) $message->external_id);

            $message->update([
                'deleted_at' => now(),
                'remote_delete_status' => 'accepted',
                'delete_error' => null,
            ]);

            return $this->redirectToConversation($message)->with('success', 'Mensaje eliminado.');
        } catch (ConnectionException|RequestException $exception) {
            $error = $this->readableMutationError($exception);

            $message->update([
                'remote_delete_status' => 'failed',
                'delete_error' => $error,
            ]);

            return $this->redirectToConversation($message)->with('error', $error);
        } catch (\RuntimeException $exception) {
            $error = $this->safeRuntimeMutationError($exception);

            $message->update([
                'remote_delete_status' => 'failed',
                'delete_error' => $error,
            ]);

            return $this->redirectToConversation($message)->with('error', $error);
        }
    }

    private function redirectToConversation(Message $message): RedirectResponse
    {
        return redirect()->route('whatsapp.conversations', [
            'chat' => $message->conversation->external_id,
        ]);
    }

    private function mutationGuardError(Message $message, string $action): ?string
    {
        if ($message->direction !== 'outbound') {
            return "Solo se pueden {$action} mensajes enviados por el operador.";
        }

        if ($message->type !== 'text') {
            return "Por ahora solo se pueden {$action} mensajes de texto.";
        }

        if ($message->external_id === null || $message->external_id === '') {
            return "No se puede {$action} este mensaje todavía.";
        }

        if ($message->status !== 'accepted') {
            return "Solo se pueden {$action} mensajes ya enviados.";
        }

        if ($message->deleted_at !== null) {
            return 'El mensaje ya fue eliminado.';
        }

        return null;
    }

    private function canReactToMessage(Message $message): bool
    {
        return $message->external_id !== null
            && $message->external_id !== ''
            && $message->deleted_at === null
            && ! in_array($message->status, ['pending', 'verifying'], true);
    }

    private function isValidReactionEmoji(string $emoji): bool
    {
        if ($emoji === '') {
            return true;
        }

        return preg_match('/^(?:\p{Regional_Indicator}{2}|[0-9#*]\x{FE0F}?\x{20E3}|\p{Extended_Pictographic}(?:\p{Emoji_Modifier}|\x{FE0F}|\x{200D}\p{Extended_Pictographic}(?:\p{Emoji_Modifier}|\x{FE0F})?)*)$/u', $emoji) === 1;
    }

    private function readySessionId(OpenWaClient $client): string
    {
        $session = $client->findSessionByName((string) config('openwa.session_name'));

        if ($session === null) {
            throw new \RuntimeException('No pudimos conectar. Reintentá en unos segundos.');
        }

        $sessionId = $this->sessionId($session);

        if ($sessionId === null) {
            throw new \RuntimeException('No pudimos conectar. Reintentá en unos segundos.');
        }

        if (! $this->isReady($session)) {
            throw new \RuntimeException('Conectá WhatsApp para continuar.');
        }

        return $sessionId;
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

    private function readableMutationError(ConnectionException|RequestException $exception): string
    {
        if ($exception instanceof ConnectionException) {
            return 'No pudimos conectar. Reintentá en unos segundos.';
        }

        $status = $exception->response->status();

        if ($status === 401 || $status === 403) {
            return 'No se pudo modificar este mensaje.';
        }

        if ($status === 404) {
            return 'No encontramos el mensaje para modificarlo.';
        }

        if ($status === 409) {
            return 'Conectá WhatsApp para continuar.';
        }

        if ($status === 422) {
            return 'Revisá el mensaje e intentá de nuevo.';
        }

        return 'No se pudo modificar este mensaje.';
    }

    private function safeRuntimeMutationError(\RuntimeException $exception): string
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'Conectá WhatsApp') || str_contains($message, 'No pudimos conectar')) {
            return $message;
        }

        return 'No se pudo modificar este mensaje.';
    }

    private function safeResponseMessage(RequestException $exception): ?string
    {
        $message = $exception->response->json('message');

        if (! is_string($message) || trim($message) === '') {
            $errors = $exception->response->json('errors');

            if (is_array($errors)) {
                $firstError = Arr::first(Arr::flatten($errors));

                $message = is_string($firstError) ? $firstError : null;
            }
        }

        if (! is_string($message) || trim($message) === '') {
            return null;
        }

        return Str::of($message)
            ->replaceMatches('/(x-api-key|api[_-]?key|authorization|bearer)\s*[:=]\s*\S+/i', '$1=[oculto]')
            ->replaceMatches('/[A-Za-z]:\\\\[^\s]+|\/[^\s]+/', '[ruta oculta]')
            ->limit(180, '…')
            ->toString();
    }
}
