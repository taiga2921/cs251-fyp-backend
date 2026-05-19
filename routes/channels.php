<?php

use App\Support\PatrolChannelAuthorizer;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('patrol.monitoring', function ($user) {
    return PatrolChannelAuthorizer::isAdmin($user);
});

Broadcast::channel('patrol.session.{patrolSessionId}', function ($user) {
    return PatrolChannelAuthorizer::isAdmin($user);
});
