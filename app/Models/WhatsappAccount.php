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
 * @property string|null $maintenance_status
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
        'maintenance_status',
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
            'openwa_last_checked_at' => 'datetime',
            'openwa_last_ready_at' => 'datetime',
            'openwa_last_recovery_sync_at' => 'datetime',
        ];
    }
}
