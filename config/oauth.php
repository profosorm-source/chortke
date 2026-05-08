<?php

/**
 * OAuth Configuration
 * 
 * Google و Facebook OAuth settings
 */

return [
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID', ''),
        'client_secret' => env('GOOGLE_CLIENT_SECRET', ''),
        'redirect_uri' => env('APP_URL', 'http://localhost') . '/auth/callback/google',
    ],

    'facebook' => [
        'app_id' => env('FACEBOOK_APP_ID', ''),
        'app_secret' => env('FACEBOOK_APP_SECRET', ''),
        'redirect_uri' => env('APP_URL', 'http://localhost') . '/auth/callback/facebook',
    ],
];
