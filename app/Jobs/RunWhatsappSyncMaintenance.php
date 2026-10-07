<?php

namespace App\Jobs;

use App\Models\WhatsappAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RunWhatsappSyncMaintenance implements ShouldQueue
{
    use Queueable;

    public const LOCK_SECONDS = 7200;

    public function __construct(public ?string $reason = null) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $lock = Cache::lock(self::lockName(), self::LOCK_SECONDS);

        if (! $lock->get()) {
            return;
        }

        try {
            $this->account()->forceFill([
                'maintenance_status' => 'running',
                'maintenance_started_at' => now(),
                'maintenance_finished_at' => null,
                'maintenance_error' => null,
            ])->save();

            $exitCode = Artisan::call('whatsapp:sync-maintenance');
            $output = trim(Artisan::output());

            if ($exitCode !== 0) {
                $this->markFailed($output !== '' ? $output : "whatsapp:sync-maintenance falló con código {$exitCode}.");

                return;
            }

            $this->account()->forceFill([
                'maintenance_status' => 'success',
                'maintenance_finished_at' => now(),
                'maintenance_error' => null,
            ])->save();
        } catch (Throwable $exception) {
            $output = trim(Artisan::output());
            $message = $exception->getMessage();

            if ($output !== '') {
                $message .= PHP_EOL.$output;
            }

            $this->markFailed($message);
        } finally {
            $lock->release();
        }
    }

    public static function lockName(): string
    {
        return 'whatsapp:sync-maintenance';
    }

    private function account(): WhatsappAccount
    {
        return WhatsappAccount::query()->firstOrCreate([
            'name' => (string) config('openwa.session_name'),
        ], [
            'status' => 'disconnected',
        ]);
    }

    private function markFailed(string $message): void
    {
        $this->account()->forceFill([
            'maintenance_status' => 'failed',
            'maintenance_finished_at' => now(),
            'maintenance_error' => mb_substr($message, 0, 65000),
        ])->save();
    }
}
