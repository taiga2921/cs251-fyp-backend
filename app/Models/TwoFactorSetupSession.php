<?php

namespace App\Models;

use Database\Factories\TwoFactorSetupSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TwoFactorSetupSession extends Model
{
    /** @use HasFactory<TwoFactorSetupSessionFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'token_hash',
        'pending_secret',
        'expires_at',
        'verified_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function isActive(): bool
    {
        return ! $this->isExpired() && ! $this->isVerified();
    }

    /**
     * @param  Builder<TwoFactorSetupSession>  $query
     * @return Builder<TwoFactorSetupSession>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('verified_at')
            ->where('expires_at', '>', now());
    }
}
