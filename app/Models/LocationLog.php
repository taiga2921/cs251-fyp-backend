<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocationLog extends Model
{
    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'patrol_session_id',
        'user_id',
        'latitude',
        'longitude',
        'accuracy',
        'timestamp',
        'server_received_at',
        'source',
        'tracking_state',
        'speed',
        'heading',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'accuracy' => 'float',
        'timestamp' => 'integer',
        'server_received_at' => 'datetime',
        'speed' => 'float',
        'heading' => 'float',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function patrolSession(): BelongsTo
    {
        return $this->belongsTo(PatrolSession::class);
    }
}
