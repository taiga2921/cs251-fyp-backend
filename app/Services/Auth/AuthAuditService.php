<?php

namespace App\Services\Auth;

use App\Models\AuthAuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuthAuditService
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function record(
        string $eventType,
        ?Request $request = null,
        ?User $user = null,
        ?string $email = null,
        ?array $metadata = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): AuthAuditLog {
        return AuthAuditLog::query()->create([
            'user_id' => $user?->getKey(),
            'event_type' => $eventType,
            'email' => $email ?? $user?->email,
            'ip_address' => $ipAddress ?? $request?->ip(),
            'user_agent' => $userAgent ?? $request?->userAgent(),
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }
}
