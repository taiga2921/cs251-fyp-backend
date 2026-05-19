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
}
