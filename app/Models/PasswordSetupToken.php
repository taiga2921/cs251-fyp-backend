<?php

namespace App\Models;

use Database\Factories\PasswordSetupTokenFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PasswordSetupToken extends Model
{
    /** @use HasFactory<PasswordSetupTokenFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'token_hash',
        'expires_at',
        'used_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
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

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function isActive(): bool
    {
        return ! $this->isExpired() && ! $this->isUsed();
    }

    public function markUsed(): void
    {
        if ($this->isUsed()) {
            return;
        }

        $this->forceFill(['used_at' => now()])->save();
    }

    /**
     * @param  Builder<PasswordSetupToken>  $query
     * @return Builder<PasswordSetupToken>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('used_at')
            ->where('expires_at', '>', now());
    }
}
