<?php

namespace App\Console\Commands;

use App\Models\WhatsappAccount;
use App\Services\OpenWaClient;
use App\Services\WhatsappContactSynchronizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

#[Signature('whatsapp:sync-contacts {--limit=100} {--max-pages=100} {--include-discovered : Also sync contacts that OpenWA reports outside the address book} {--all : Alias for --include-discovered}')]
#[Description('Synchronize WhatsApp contacts from OpenWA into local persistence')]
class SyncWhatsappContacts extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(OpenWaClient $client, WhatsappContactSynchronizer $synchronizer): int
    {
        $sessionName = (string) config('openwa.session_name');
        $limit = max(1, (int) $this->option('limit'));
        $maxPages = max(1, (int) $this->option('max-pages'));
        $includeDiscovered = (bool) $this->option('include-discovered') || (bool) $this->option('all');

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
            $result = $synchronizer->sync($sessionId, $limit, $maxPages, $includeDiscovered);
        } catch (ConnectionException|RequestException $exception) {
            $account->forceFill(['last_error' => $this->readableOpenWaError($exception)])->save();
            $this->error($this->readableOpenWaError($exception));

            return self::FAILURE;
        }

        $this->info("Contactos procesados: {$result['processed']}");
        $this->info("Contactos creados: {$result['created']}");
        $this->info("Contactos actualizados: {$result['updated']}");
        $this->info("Contactos omitidos: {$result['skipped']}");

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
            return $status;
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

    private function mapAccountStatus(string $status): string
    {
        return match ($status) {
            'ready', 'connected' => 'connected',
            'qr', 'qr_ready', 'starting', 'initializing' => 'pending',
            'disconnected', 'closed', 'failed' => 'disconnected',
            default => 'pending',
        };
    }

    private function readableOpenWaError(ConnectionException|RequestException $exception): string
    {
        if ($exception instanceof RequestException && $exception->response !== null) {
            return "OpenWA respondió con HTTP {$exception->response->status()}.";
        }

        return 'No se pudo conectar con OpenWA.';
    }
}
