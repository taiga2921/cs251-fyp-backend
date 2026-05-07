<?php

namespace App\Models;

use Database\Factories\CheckpointEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class CheckpointEvent extends Model
{
    /** @use HasFactory<CheckpointEventFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'patrol_session_id',
        'checkpoint_id',
        'entered_at',
        'exited_at',
        'detected_at',
        'processed_at',
        'detection_type',
        'confidence_score',
        'status',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'entered_at' => 'datetime',
        'exited_at' => 'datetime',
        'detected_at' => 'datetime',
        'processed_at' => 'datetime',
        'confidence_score' => 'float',
    ];

    protected static function booted(): void
    {
        static::creating(function (CheckpointEvent $model): void {
            if ($model->getKey() === null || $model->getKey() === '') {
                $model->setAttribute($model->getKeyName(), (string) Str::uuid());
            }
        });
    }

    public function patrolSession(): BelongsTo
    {
        return $this->belongsTo(PatrolSession::class);
    }

    public function checkpoint(): BelongsTo
    {
        return $this->belongsTo(Checkpoint::class);
    }

    public function metric(): HasOne
    {
        return $this->hasOne(CheckpointEventMetric::class);
    }
}
