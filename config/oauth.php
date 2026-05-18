<?php

/**
 * OAuth Configuration
 * 
 * Google و Facebook OAuth settings
 */

$appUrl = env('APP_URL', 'http://localhost');
$isProduction = (env('APP_ENV', 'production') === 'production');

if ($isProduction && ($appUrl === 'http://localhost' || empty($appUrl) || !filter_var($appUrl, FILTER_VALIDATE_URL))) {
    throw new \RuntimeException('Production environment detected but APP_URL is invalid or set to localhost in environment variables.');
}

if ($isProduction && (empty(env('GOOGLE_CLIENT_ID')) || empty(env('GOOGLE_CLIENT_SECRET')))) {
    throw new \RuntimeException('Google OAuth credentials must be configured in production.');
}

return [
    // Default false: strict IP binding is only enabled in high-security contexts
    // and can be controlled by feature flag or environment.
    'strict_ip_binding' => (bool)env('OAUTH_STRICT_IP_BINDING', false),

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID', ''),
        'client_secret' => env('GOOGLE_CLIENT_SECRET', ''),
        'redirect_uri' => rtrim($appUrl, '/') . '/auth/callback/google',
    ],

    'facebook' => [
        'app_id' => env('FACEBOOK_APP_ID', ''),
        'app_secret' => env('FACEBOOK_APP_SECRET', ''),
        'redirect_uri' => rtrim($appUrl, '/') . '/auth/callback/facebook',
    ],
];
