<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $usuario
 * @property string $password
 * @property int|null $rol_id
 * @property string|null $ope_datatech
 * @property string|null $nombre
 * @property bool $activo
 * @property string|null $mail
 * @property string|null $tel
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read string $display_name
 */
class NexoUser extends Authenticatable
{
    use Notifiable, SoftDeletes;

    protected $connection = 'nexosrn';

    protected $table = 'users';

    protected $primaryKey = 'id';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->nombre ?: $this->usuario;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
}
