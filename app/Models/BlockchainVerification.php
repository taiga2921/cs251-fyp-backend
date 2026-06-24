<?php

namespace App\Models;

use Database\Factories\BlockchainVerificationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlockchainVerification extends Model
{
    /** @use HasFactory<BlockchainVerificationFactory> */
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'blockchain_record_id',
        'verified_by',
        'verification_type',
        'stored_hash',
        'recomputed_hash',
        'onchain_hash',
        'onchain_found',
        'result',
        'error_message',
        'verified_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'onchain_found' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public function blockchainRecord(): BelongsTo
    {
        return $this->belongsTo(BlockchainRecord::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
