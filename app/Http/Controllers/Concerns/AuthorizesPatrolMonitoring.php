<?php

namespace App\Http\Controllers\Concerns;

use App\Support\PatrolChannelAuthorizer;
use Illuminate\Auth\Access\AuthorizationException;

trait AuthorizesPatrolMonitoring
{
    /**
     * @throws AuthorizationException
     */
    protected function authorizePatrolMonitoring(): void
    {
        if (! PatrolChannelAuthorizer::canAccessPatrolMonitoring(request()->user('api'))) {
            throw new AuthorizationException(
                'Only administrators and security operators may perform this action.'
            );
        }
    }
}
