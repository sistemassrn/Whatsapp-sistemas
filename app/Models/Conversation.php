<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $external_id
 * @property int|null $contact_id
 * @property string|null $title
 * @property int|null $last_message_id
 * @property Carbon|null $last_message_at
 * @property Carbon|null $last_read_at
 * @property Carbon|null $marked_unread_at
 * @property int $unread_count
 * @property Carbon|null $hidden_at
 * @property string|null $hidden_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Contact|null $contact
 * @property-read Message|null $lastMessage
 * @property-read Collection<int, Message> $messages
 */
class Conversation extends WhatsappModel
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'external_id',
        'contact_id',
        'title',
        'last_message_id',
        'last_message_at',
        'last_read_at',
        'marked_unread_at',
        'unread_count',
        'hidden_at',
        'hidden_reason',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'last_read_at' => 'datetime',
            'marked_unread_at' => 'datetime',
            'unread_count' => 'integer',
            'hidden_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_message_id');
    }
}
