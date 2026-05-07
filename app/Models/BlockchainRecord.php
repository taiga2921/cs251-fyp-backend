<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class BlockchainRecord extends Model
{
    use HasUuids;

    /**
     * The primary key type.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'entity_type',
        'entity_id',
        'hash',
        'network',
        'environment',
        'tx_hash',
        'block_number',
        'status',
        'retry_count',
        'error_message',
        'submitted_at',
        'confirmed_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'submitted_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'block_number' => 'integer',
        'retry_count' => 'integer',
    ];

    public function entity(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', 'confirmed');
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }

    public function scopeByNetwork(Builder $query, string $network): Builder
    {
        return $query->where('network', $network);
    }

    public function scopeByEnvironment(Builder $query, string $environment): Builder
    {
        return $query->where('environment', $environment);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function markAsSubmitted(): bool
    {
        return $this->update([
            'status' => 'pending',
            'submitted_at' => now(),
        ]);
    }

    public function markAsConfirmed(string $txHash, int $blockNumber): bool
    {
        return $this->update([
            'status' => 'confirmed',
            'tx_hash' => $txHash,
            'block_number' => $blockNumber,
            'confirmed_at' => now(),
            'error_message' => null,
        ]);
    }

    public function markAsFailed(string $errorMessage): bool
    {
        $this->incrementRetry();

        return $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
        ]);
    }

    public function incrementRetry(): int
    {
        return $this->increment('retry_count');
    }
}
