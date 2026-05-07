<?php

namespace App\Models;

use Database\Factories\CheckpointEventMetricFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CheckpointEventMetric extends Model
{
    public const UPDATED_AT = null;

    /** @use HasFactory<CheckpointEventMetricFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'checkpoint_event_id',
        'distance_score',
        'accuracy_score',
        'time_score',
        'stability_score',
        'gap_factor',
        'integrity_factor',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'distance_score' => 'decimal:2',
        'accuracy_score' => 'decimal:2',
        'time_score' => 'decimal:2',
        'stability_score' => 'decimal:2',
        'gap_factor' => 'decimal:2',
        'integrity_factor' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (CheckpointEventMetric $model): void {
            if ($model->getKey() === null || $model->getKey() === '') {
                $model->setAttribute($model->getKeyName(), (string) Str::uuid());
            }
        });
    }

    public function checkpointEvent(): BelongsTo
    {
        return $this->belongsTo(CheckpointEvent::class);
    }
}
