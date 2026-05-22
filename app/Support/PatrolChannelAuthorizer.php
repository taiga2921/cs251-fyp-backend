<?php

namespace App\Support;

use App\Models\User;

class PatrolChannelAuthorizer
{
    public static function isAdmin(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        $user->loadMissing('role');

        return is_string($user->role?->name)
            && strcasecmp($user->role->name, 'Admin') === 0;
    }

    public static function canAccessPatrolMonitoring(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        $user->loadMissing('role');

        if (! is_string($user->role?->name)) {
            return false;
        }

        $roleName = $user->role->name;

        return strcasecmp($roleName, 'Admin') === 0
            || strcasecmp($roleName, 'Security Operator') === 0;
    }
}
