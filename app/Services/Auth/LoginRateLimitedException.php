<?php

namespace App\Services\Auth;

use Exception;

class LoginRateLimitedException extends Exception
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('Too many unsuccessful sign-in attempts.');
    }
}
