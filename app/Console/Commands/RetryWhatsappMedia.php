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
use Illuminate\Support\Facades\Storage;

#[Signature('whatsapp:retry-media {--limit=50} {--minutes=1440}')]
#[Description('Retry pending WhatsApp media downloads')]
class RetryWhatsappMedia extends Command
{
    private const PERMANENT_MEDIA_ERROR = 'Tipo de archivo no permitido.';

    private int $attempted = 0;

    private int $stored = 0;

    private int $failed = 0;

    private int $skipped = 0;

    /**
     * Execute the console command.
     */
    public function handle(OpenWaClient $client, WhatsappMessageMediaDownloader $mediaDownloader): int
    {
        $this->attempted = 0;
        $this->stored = 0;
        $this->failed = 0;
        $this->skipped = 0;

        $sessionId = $this->readySessionId($client);

        if ($sessionId === null) {
            $this->info('No hay una sesión lista para recuperar archivos.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $minutes = max(1, (int) $this->option('minutes'));
        $retryMediaCutoff = now()
            ->subHours(max(1, (int) config('openwa.retry_media_window_hours', 240)))
            ->format('Ymd H:i:s.v');
        $updatedAtCutoff = now()->subMinutes($minutes);

        $messages = Message::query()
            ->with('conversation')
            ->whereIn('type', ['image', 'audio', 'video', 'document'])
            ->whereNotNull('external_id')
            ->where('updated_at', '>=', $updatedAtCutoff)
            ->whereRaw('COALESCE(sent_at, received_at, created_at) >= ?', [$retryMediaCutoff])
            ->where(function ($query): void {
                $query->whereNull('media_error')
                    ->orWhere('media_error', '!=', self::PERMANENT_MEDIA_ERROR);
            })
            ->where(function ($query): void {
                $query->where(function ($query): void {
                    $query->whereIn('media_download_status', ['omitted', 'pending', 'failed'])
                        ->whereNull('media_path');
                })->orWhere(function ($query): void {
                    $query->where('media_download_status', 'stored')
                        ->where('media_disk', 'whatsapp_media')
                        ->whereNotNull('media_path');
                });
            })
            ->orderByRaw('COALESCE(sent_at, received_at, created_at) DESC')
            ->lazy();

        foreach ($messages as $message) {
            if ($this->attempted >= $limit) {
                break;
            }

            if ($message->media_download_status === 'stored') {
                if ($this->storedFileExists($message)) {
                    $this->skipped++;

                    continue;
                }

                $this->markStoredFileMissing($message);
            }

            if ($message->media_next_retry_at !== null && $message->media_next_retry_at->isFuture()) {
                $this->skipped++;

                continue;
            }

            $chatId = $message->conversation->external_id ?? null;

            if (! is_string($chatId) || trim($chatId) === '') {
                $this->skipped++;

                continue;
            }

            $this->attempted++;
            $downloaded = $mediaDownloader->attempt($message, $sessionId, $chatId, ['source' => 'retry-media']);

            if ($downloaded) {
                $this->stored++;
            } else {
                $this->failed++;
            }
        }

        $this->info("Intentados: {$this->attempted}");
        $this->info("Guardados: {$this->stored}");
        $this->info("Fallidos: {$this->failed}");
        $this->info("Omitidos: {$this->skipped}");

        return self::SUCCESS;
    }

    private function storedFileExists(Message $message): bool
    {
        return is_string($message->media_path)
            && $message->media_path !== ''
            && Storage::disk('whatsapp_media')->exists($message->media_path);
    }

    private function markStoredFileMissing(Message $message): void
    {
        $message->forceFill([
            'media_disk' => null,
            'media_path' => null,
            'media_download_status' => 'failed',
            'media_error' => 'Archivo local no encontrado.',
            'media_next_retry_at' => null,
            'media_retry_attempts' => 0,
        ])->save();
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
