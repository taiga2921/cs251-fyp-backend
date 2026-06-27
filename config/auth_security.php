<?php

return [
    'access_token_ttl_minutes' => (int) env('AUTH_ACCESS_TOKEN_TTL', env('JWT_TTL', 30)),
    'refresh_token_ttl_hours' => (int) env('AUTH_REFRESH_TOKEN_TTL_HOURS', 12),
    'refresh_cookie_name' => env('AUTH_REFRESH_COOKIE_NAME', 'refresh_token'),
    'refresh_cookie_secure' => filter_var(env('AUTH_REFRESH_COOKIE_SECURE', false), FILTER_VALIDATE_BOOL),
    'refresh_cookie_same_site' => env('AUTH_REFRESH_COOKIE_SAME_SITE', 'lax'),
    'refresh_cookie_path' => env('AUTH_REFRESH_COOKIE_PATH', '/api/auth'),
    'password_min_length' => (int) env('AUTH_PASSWORD_MIN_LENGTH', 12),
    'password_setup_token_ttl_hours' => (int) env('AUTH_PASSWORD_SETUP_TOKEN_TTL_HOURS', 24),
];
