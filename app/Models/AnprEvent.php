<?php

namespace App\Models;

use Database\Factories\AnprEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AnprEvent extends Model
{
    /** @use HasFactory<AnprEventFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'vehicle_id',
        'camera_id',
        'blockchain_record_id',
        'plate_number',
        'confidence',
        'detection_time',
        'is_flagged',
        'is_valid',
        'latitude',
        'longitude',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'id' => 'string',
        'confidence' => 'decimal:4',
        'detection_time' => 'datetime',
        'is_flagged' => 'boolean',
        'is_valid' => 'boolean',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function camera(): BelongsTo
    {
        return $this->belongsTo(Camera::class);
    }

    public function blockchainRecord(): BelongsTo
    {
        return $this->belongsTo(BlockchainRecord::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(AnprImage::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(AnprEventLog::class);
    }
}
