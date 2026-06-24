<?php

namespace App\Models;

use Database\Factories\BlockchainRecordFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class BlockchainRecord extends Model
{
    /** @use HasFactory<BlockchainRecordFactory> */
    use HasFactory, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'entity_type',
        'entity_id',
        'proof_type',
        'canonical_version',
        'hash_algorithm',
        'record_hash',
        'payload_summary',
        'network',
        'environment',
        'chain_id',
        'contract_address',
        'tx_hash',
        'block_number',
        'confirmations',
        'status',
        'retry_count',
        'last_error',
        'submitted_at',
        'confirmed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload_summary' => 'array',
            'chain_id' => 'integer',
            'block_number' => 'integer',
            'confirmations' => 'integer',
            'retry_count' => 'integer',
            'submitted_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function entity(): MorphTo
    {
        return $this->morphTo();
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(BlockchainJob::class);
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(BlockchainVerification::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeQueued(Builder $query): Builder
    {
        return $query->where('status', 'queued');
    }

    public function scopeProcessing(Builder $query): Builder
    {
        return $query->where('status', 'processing');
    }

    public function scopeSubmitted(Builder $query): Builder
    {
        return $query->where('status', 'submitted');
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

    public function scopeForEntity(Builder $query, string $entityType, string $entityId): Builder
    {
        return $query->where('entity_type', $entityType)->where('entity_id', $entityId);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isQueued(): bool
    {
        return $this->status === 'queued';
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    public function isSubmitted(): bool
    {
        return $this->status === 'submitted';
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function markAsQueued(): bool
    {
        return $this->update([
            'status' => 'queued',
            'last_error' => null,
        ]);
    }

    public function markAsProcessing(): bool
    {
        return $this->update([
            'status' => 'processing',
        ]);
    }

    public function markAsSubmitted(?string $txHash = null): bool
    {
        return $this->update([
            'status' => 'submitted',
            'tx_hash' => $txHash ?? $this->tx_hash,
            'submitted_at' => now(),
            'last_error' => null,
        ]);
    }

    public function markAsConfirmed(string $txHash, int $blockNumber, int $confirmations = 0): bool
    {
        return $this->update([
            'status' => 'confirmed',
            'tx_hash' => $txHash,
            'block_number' => $blockNumber,
            'confirmations' => $confirmations,
            'confirmed_at' => now(),
            'last_error' => null,
        ]);
    }

    public function markAsFailed(string $errorMessage): bool
    {
        $this->incrementRetry();

        return $this->update([
            'status' => 'failed',
            'last_error' => $errorMessage,
        ]);
    }

    public function incrementRetry(): int
    {
        return $this->increment('retry_count');
    }
}
