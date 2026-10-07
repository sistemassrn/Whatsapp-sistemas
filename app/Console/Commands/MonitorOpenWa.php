<?php

namespace App\Console\Commands;

use App\Jobs\RunWhatsappSyncMaintenance;
use App\Models\WhatsappAccount;
use App\Services\OpenWaClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

#[Signature('whatsapp:monitor-openwa')]
#[Description('Monitor OpenWA status and trigger recovery sync when the configured session returns to ready.')]
class MonitorOpenWa extends Command
{
    private const LOCK_SECONDS = 120;

    /**
     * Execute the console command.
     */
    public function handle(OpenWaClient $client): int
    {
        $lock = Cache::lock('whatsapp:monitor-openwa', self::LOCK_SECONDS);

        if (! $lock->get()) {
            $this->info('El monitor de OpenWA ya está en ejecución.');

            return self::SUCCESS;
        }

        try {
            return $this->monitor($client);
        } finally {
            $lock->release();
        }
    }

    private function monitor(OpenWaClient $client): int
    {
        $sessionName = (string) config('openwa.session_name');
        $account = WhatsappAccount::query()->firstOrNew(['name' => $sessionName]);
        $previousStatus = $account->openwa_last_status;
        $error = null;

        try {
            $session = $client->findSessionByName($sessionName);
            $status = $session === null ? 'unavailable' : $this->sessionStatus($session);
        } catch (Throwable $exception) {
            $status = 'unavailable';
            $error = $exception->getMessage();
        }

        $now = now();
        $updates = [
            'status' => $status === 'ready' ? 'connected' : $this->mapAccountStatus($status),
            'openwa_last_status' => $status,
            'openwa_last_checked_at' => $now,
            'openwa_recovery_sync_error' => $error === null ? null : mb_substr($error, 0, 65000),
        ];

        if ($status === 'ready') {
            $updates['last_seen_at'] = $now;
            $updates['last_error'] = null;
            $updates['openwa_last_ready_at'] = $now;
        } elseif ($error !== null) {
            $updates['last_error'] = mb_substr($error, 0, 65000);
        }

        $account->forceFill($updates)->save();

        $this->info("Sesión {$sessionName}: {$status}.");

        if ($previousStatus === 'ready' || $status !== 'ready') {
            return self::SUCCESS;
        }

        if (! $this->cooldownPassed($account)) {
            $this->info('No se dispara sincronización de recuperación: cooldown activo.');

            return self::SUCCESS;
        }

        if ($this->isMaintenanceRunning($account)) {
            $this->info('No se dispara sincronización de recuperación: ya hay mantenimiento en curso.');

            return self::SUCCESS;
        }

        $account->forceFill([
            'openwa_last_recovery_sync_at' => $now,
            'openwa_recovery_sync_error' => null,
        ])->save();

        RunWhatsappSyncMaintenance::dispatch('openwa_recovered');
        $this->info('Sincronización de recuperación disparada.');

        return self::SUCCESS;
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

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function mapAccountStatus(string $status): string
    {
        return match ($status) {
            'ready', 'connected' => 'connected',
            'starting', 'qr', 'qr_ready', 'pairing', 'connecting' => 'connecting',
            default => 'disconnected',
        };
    }

    private function cooldownPassed(WhatsappAccount $account): bool
    {
        if ($account->openwa_last_recovery_sync_at === null) {
            return true;
        }

        $minutes = max(0, (int) config('openwa.recovery_sync_cooldown_minutes', 15));

        return $account->openwa_last_recovery_sync_at->lessThanOrEqualTo(now()->subMinutes($minutes));
    }

    private function isMaintenanceRunning(WhatsappAccount $account): bool
    {
        if ($account->maintenance_status === 'running'
            && $account->maintenance_started_at !== null
            && $account->maintenance_started_at->greaterThan(now()->subSeconds(RunWhatsappSyncMaintenance::LOCK_SECONDS))) {
            return true;
        }

        $lock = Cache::lock(RunWhatsappSyncMaintenance::lockName(), RunWhatsappSyncMaintenance::LOCK_SECONDS);

        if (! $lock->get()) {
            return true;
        }

        $lock->release();

        return false;
    }
}
