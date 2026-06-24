<?php

namespace App\Models;

use Database\Factories\BlockchainJobFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlockchainJob extends Model
{
    /** @use HasFactory<BlockchainJobFactory> */
    use HasFactory, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'blockchain_record_id',
        'job_type',
        'status',
        'attempts',
        'max_attempts',
        'next_attempt_at',
        'started_at',
        'finished_at',
        'last_error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function blockchainRecord(): BelongsTo
    {
        return $this->belongsTo(BlockchainRecord::class);
    }
}
