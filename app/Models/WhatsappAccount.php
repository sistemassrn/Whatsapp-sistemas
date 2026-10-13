<?php

namespace App\Models;

use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $status
 * @property string|null $phone_number
 * @property Carbon|null $last_seen_at
 * @property string|null $last_error
 * @property Carbon|null $maintenance_started_at
 * @property Carbon|null $maintenance_finished_at
 * @property Carbon|null $maintenance_requested_at
 * @property int|null $maintenance_duration_seconds
 * @property string|null $maintenance_status
 * @property string|null $maintenance_reason
 * @property string|null $maintenance_error
 * @property string|null $openwa_last_status
 * @property Carbon|null $openwa_last_checked_at
 * @property Carbon|null $openwa_last_ready_at
 * @property Carbon|null $openwa_last_recovery_sync_at
 * @property string|null $openwa_recovery_sync_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WhatsappAccount extends WhatsappModel
{
    public const STALE_MAINTENANCE_ERROR = 'La sincronización quedó sin confirmación de finalización. Puede haber sido interrumpida o excedió el tiempo máximo.';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'status',
        'phone_number',
        'last_seen_at',
        'last_error',
        'maintenance_started_at',
        'maintenance_finished_at',
        'maintenance_requested_at',
        'maintenance_duration_seconds',
        'maintenance_status',
        'maintenance_reason',
        'maintenance_error',
        'openwa_last_status',
        'openwa_last_checked_at',
        'openwa_last_ready_at',
        'openwa_last_recovery_sync_at',
        'openwa_recovery_sync_error',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'maintenance_started_at' => 'datetime',
            'maintenance_finished_at' => 'datetime',
            'maintenance_requested_at' => 'datetime',
            'openwa_last_checked_at' => 'datetime',
            'openwa_last_ready_at' => 'datetime',
            'openwa_last_recovery_sync_at' => 'datetime',
        ];
    }

    public static function maintenanceStaleMinutes(): int
    {
        return max(1, (int) config('openwa.sync_maintenance_stale_minutes', 90));
    }

    public function isMaintenanceStale(): bool
    {
        if (! in_array($this->maintenance_status, ['queued', 'running'], true)) {
            return false;
        }

        $referenceAt = $this->maintenance_status === 'running'
            ? $this->maintenance_started_at
            : $this->maintenance_requested_at;

        return $referenceAt !== null
            && $referenceAt->lessThanOrEqualTo(now()->subMinutes(self::maintenanceStaleMinutes()));
    }

    public function markMaintenanceStaleIfNeeded(): bool
    {
        if (! $this->isMaintenanceStale()) {
            return false;
        }

        $finishedAt = now();
        $startedAt = $this->maintenance_started_at ?? $this->maintenance_requested_at;

        $this->forceFill([
            'maintenance_status' => 'failed',
            'maintenance_finished_at' => $finishedAt,
            'maintenance_duration_seconds' => $startedAt === null ? null : (int) round(max(0, $startedAt->diffInSeconds($finishedAt))),
            'maintenance_error' => self::STALE_MAINTENANCE_ERROR,
        ])->save();

        return true;
    }
}
