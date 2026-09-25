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
        ];
    }
}
