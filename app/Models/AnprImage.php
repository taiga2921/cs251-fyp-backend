<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AnprImage extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'anpr_images';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'anpr_event_id',
        'image_type',
        'file_path',
        'file_size',
        'resolution',
        'expires_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'file_size' => 'integer',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function anprEvent(): BelongsTo
    {
        return $this->belongsTo(AnprEvent::class);
    }

    public function blockchainRecord(): HasOne
    {
        return $this->hasOne(BlockchainRecord::class, 'entity_id', 'id')
            ->where('blockchain_records.entity_type', 'anpr_image')
            ->where('blockchain_records.proof_type', 'evidence_file');
    }
}
