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
    ];

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
