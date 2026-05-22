<?php

use App\Support\PatrolChannelAuthorizer;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('patrol.monitoring', function ($user) {
    return PatrolChannelAuthorizer::canAccessPatrolMonitoring($user);
});

Broadcast::channel('patrol.session.{patrolSessionId}', function ($user) {
    return PatrolChannelAuthorizer::canAccessPatrolMonitoring($user);
});
