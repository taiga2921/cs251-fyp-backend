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
    'otp_challenge_ttl_minutes' => (int) env('AUTH_OTP_CHALLENGE_TTL', 5),
    'otp_max_attempts' => (int) env('AUTH_OTP_MAX_ATTEMPTS', 5),
    'two_factor_setup_ttl_minutes' => (int) env('AUTH_TWO_FACTOR_SETUP_TTL', 10),
    'totp_issuer' => env('AUTH_TOTP_ISSUER', 'IKH One'),
    'totp_window' => (int) env('AUTH_TOTP_WINDOW', 1),
];
