<?php

namespace App\Models;

use Database\Factories\AuthLoginChallengeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthLoginChallenge extends Model
{
    /** @use HasFactory<AuthLoginChallengeFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'expires_at',
        'consumed_at',
        'failed_attempts',
        'locked_at',
        'ip_address',
        'user_agent',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'locked_at' => 'datetime',
            'failed_attempts' => 'integer',
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

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    public function isActive(): bool
    {
        return ! $this->isExpired() && ! $this->isConsumed() && ! $this->isLocked();
    }

    public function markConsumed(): void
    {
        if ($this->isConsumed()) {
            return;
        }

        $this->forceFill(['consumed_at' => now()])->save();
    }

    /**
     * @param  Builder<AuthLoginChallenge>  $query
     * @return Builder<AuthLoginChallenge>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('consumed_at')
            ->whereNull('locked_at')
            ->where('expires_at', '>', now());
    }
}
