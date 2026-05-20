<?php

return [

    /*
    |--------------------------------------------------------------------------
    | VAPID keys (Web Push)
    |--------------------------------------------------------------------------
    |
    | Generate with: php artisan webpush:vapid  (or openssl — see docs)
    | Public key is also exposed to the PWA as VITE_VAPID_PUBLIC_KEY.
    | Never expose the private key to the frontend.
    |
    */

    'vapid' => [
        'subject' => env('VAPID_SUBJECT', env('APP_URL', 'mailto:admin@example.com')),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],

];
