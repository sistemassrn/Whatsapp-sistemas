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
                ->with('error', 'Ya hay una sincronización en curso.');
        }

        RunWhatsappSyncMaintenance::dispatch();

        return redirect()
            ->back()
            ->with('success', 'Sincronización de mantenimiento iniciada.');
    }

    private function isMaintenanceRunning(): bool
    {
        $account = WhatsappAccount::query()
            ->where('name', (string) config('openwa.session_name'))
            ->first();

        if ($account?->maintenance_status === 'running'
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
