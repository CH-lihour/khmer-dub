<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // The FastAPI dubbing worker. DUBBER_TOKEN must equal WORKER_TOKEN in worker/.env.
    'dubber' => [
        'url' => env('DUBBER_URL', 'http://worker:8001'),
        'token' => env('DUBBER_TOKEN'),
    ],

    // Where the browser reaches Reverb for live progress: the page's own host name on this port.
    // The site reaches it inside Docker at REVERB_HOST:REVERB_PORT (config/broadcasting.php).
    'reverb_client' => [
        'port' => (int) env('REVERB_PUBLIC_PORT', 8080),
        'scheme' => env('REVERB_PUBLIC_SCHEME', 'ws'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
