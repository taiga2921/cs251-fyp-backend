<?php

namespace App\Models;

use Database\Factories\PatrolSessionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PatrolSession extends Model
{
    /** @use HasFactory<PatrolSessionFactory> */
    use HasFactory, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'zone_id',
        'blockchain_record_id',
        'started_at',
        'ended_at',
        'status',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function blockchainRecord(): BelongsTo
    {
        return $this->belongsTo(BlockchainRecord::class);
    }

    public function locationLogs(): HasMany
    {
        return $this->hasMany(LocationLog::class);
    }

    public function checkpointEvents(): HasMany
    {
        return $this->hasMany(CheckpointEvent::class);
    }

    public function patrolRoutes(): HasMany
    {
        return $this->hasMany(PatrolRoute::class);
    }
}
