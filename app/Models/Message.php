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
 * @property string $type
 * @property string $status
 * @property Carbon|null $sent_at
 * @property Carbon|null $received_at
 * @property string|null $error_message
 * @property string|null $idempotency_key
 * @property string|null $media_disk
 * @property string|null $media_path
 * @property string|null $media_mime_type
 * @property string|null $media_filename
 * @property int|null $media_size_bytes
 * @property string|null $media_download_status
 * @property string|null $media_error
 * @property array<string, mixed>|null $media_metadata
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
        'type',
        'status',
        'sent_at',
        'received_at',
        'error_message',
        'idempotency_key',
        'media_disk',
        'media_path',
        'media_mime_type',
        'media_filename',
        'media_size_bytes',
        'media_download_status',
        'media_error',
        'media_metadata',
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
            'media_metadata' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
