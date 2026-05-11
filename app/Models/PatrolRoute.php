<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatrolRoute extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'patrol_session_id',
        'latitude',
        'longitude',
        'accuracy',
        'altitude',
        'recorded_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'accuracy' => 'float',
        'altitude' => 'float',
        'recorded_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function patrolSession(): BelongsTo
    {
        return $this->belongsTo(PatrolSession::class);
    }
}
