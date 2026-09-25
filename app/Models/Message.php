<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $conversation_id
 * @property string|null $external_id
 * @property string $direction
 * @property string|null $body
 * @property string $status
 * @property Carbon|null $sent_at
 * @property Carbon|null $received_at
 * @property string|null $error_message
 * @property string|null $idempotency_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Conversation $conversation
 */
class Message extends WhatsappModel
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'conversation_id',
        'external_id',
        'direction',
        'body',
        'status',
        'sent_at',
        'received_at',
        'error_message',
        'idempotency_key',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
