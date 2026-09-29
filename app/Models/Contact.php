<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $external_id
 * @property string|null $name
 * @property string|null $push_name
 * @property string|null $phone
 * @property string|null $profile_photo_url
 * @property Carbon|null $profile_photo_fetched_at
 * @property string|null $profile_photo_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Conversation> $conversations
 */
class Contact extends WhatsappModel
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'external_id',
        'name',
        'push_name',
        'phone',
        'profile_photo_url',
        'profile_photo_fetched_at',
        'profile_photo_error',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'profile_photo_fetched_at' => 'datetime',
        ];
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
