<?php

namespace App\Console\Commands;

use App\Models\Message;
use App\Services\OpenWaClient;
use App\Services\WhatsappMessageMediaDownloader;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

#[Signature('whatsapp:retry-media {--limit=50} {--minutes=1440}')]
#[Description('Retry pending WhatsApp media downloads')]
class RetryWhatsappMedia extends Command
{
    private int $attempted = 0;

    private int $stored = 0;

    private int $failed = 0;

    private int $skipped = 0;

    /**
     * Execute the console command.
     */
    public function handle(OpenWaClient $client, WhatsappMessageMediaDownloader $mediaDownloader): int
    {
        $sessionId = $this->readySessionId($client);

        if ($sessionId === null) {
            $this->info('No hay una sesión lista para recuperar archivos.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $minutes = max(1, (int) $this->option('minutes'));
        $recentCutoff = now()
            ->subHours(max(1, (int) config('openwa.recent_sync_window_hours', 24)))
            ->format('Ymd H:i:s.v');

        Message::query()
            ->with('conversation')
            ->whereIn('media_download_status', ['omitted', 'pending', 'failed'])
            ->whereIn('type', ['image', 'audio', 'video', 'document'])
            ->whereNull('media_path')
            ->whereNotNull('external_id')
            ->where('updated_at', '>=', now()->subMinutes($minutes)->format('Ymd H:i:s.v'))
            ->whereRaw('COALESCE(sent_at, received_at, created_at) >= ?', [$recentCutoff])
            ->oldest('updated_at')
            ->limit($limit)
            ->get()
            ->each(function (Message $message) use ($mediaDownloader, $sessionId): void {
                $chatId = $message->conversation->external_id ?? null;

                if (! is_string($chatId) || trim($chatId) === '') {
                    $this->skipped++;

                    return;
                }

                $this->attempted++;
                $downloaded = $mediaDownloader->attempt($message, $sessionId, $chatId, ['source' => 'retry-media']);

                if ($downloaded) {
                    $this->stored++;
                } else {
                    $this->failed++;
                }
            });

        $this->info("Intentados: {$this->attempted}");
        $this->info("Guardados: {$this->stored}");
        $this->info("Fallidos: {$this->failed}");
        $this->info("Omitidos: {$this->skipped}");

        return self::SUCCESS;
    }

    private function readySessionId(OpenWaClient $client): ?string
    {
        $sessionName = (string) config('openwa.session_name');

        if (trim($sessionName) === '') {
            return null;
        }

        try {
            $session = $client->findSessionByName($sessionName);
        } catch (ConnectionException|RequestException) {
            return null;
        }

        if ($session === null || $this->sessionStatus($session) !== 'ready') {
            return null;
        }

        return $this->sessionId($session);
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
}
