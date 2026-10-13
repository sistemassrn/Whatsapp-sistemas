<?php

namespace App\Http\Controllers;

use App\Jobs\RunWhatsappSyncMaintenance;
use App\Models\WhatsappAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;

class WhatsappSyncMaintenanceController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(): RedirectResponse
    {
        if ($this->isMaintenanceRunning()) {
            return redirect()
                ->back()
                ->with('error', 'Ya hay una sincronización en curso o esperando ejecución.');
        }

        $this->account()->forceFill([
            'maintenance_status' => 'queued',
            'maintenance_reason' => 'manual',
            'maintenance_requested_at' => now(),
            'maintenance_started_at' => null,
            'maintenance_finished_at' => null,
            'maintenance_duration_seconds' => null,
            'maintenance_error' => null,
        ])->save();

        RunWhatsappSyncMaintenance::dispatch('manual')->afterResponse();

        return redirect()
            ->back()
            ->with('success', 'Sincronización de mantenimiento solicitada.');
    }

    private function isMaintenanceRunning(): bool
    {
        $account = $this->account(false);

        if ($account !== null) {
            if ($account->markMaintenanceStaleIfNeeded()) {
                $account->refresh();
            }

            if (in_array($account->maintenance_status, ['queued', 'running'], true)) {
                return true;
            }
        }

        $lock = Cache::lock(RunWhatsappSyncMaintenance::lockName(), RunWhatsappSyncMaintenance::lockSeconds());

        if (! $lock->get()) {
            return true;
        }

        $lock->release();

        return false;
    }

    private function account(bool $create = true): ?WhatsappAccount
    {
        $query = WhatsappAccount::query()->where('name', (string) config('openwa.session_name'));

        if (! $create) {
            return $query->first();
        }

        return WhatsappAccount::query()->firstOrCreate([
            'name' => (string) config('openwa.session_name'),
        ], [
            'status' => 'disconnected',
        ]);
    }
}
