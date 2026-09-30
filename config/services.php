<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'midtrans' => [
        'server_key' => env('MIDTRANS_SERVER_KEY', 'SB-Mid-server-TESTKEY123456789'),
        'client_key' => env('MIDTRANS_CLIENT_KEY', 'SB-Mid-client-TESTKEY123456789'),
        'is_production' => env('MIDTRANS_IS_PRODUCTION', false),
        'is_sanitized' => true,
        'is_3ds' => true,
    ],

    'whatsapp' => [
        'provider' => env('WA_PROVIDER', 'fake'), // 'fonnte', 'fake'
        'fonnte_token' => env('WA_FONNTE_TOKEN', ''),
        'webhook_secret' => env('WA_WEBHOOK_SECRET', ''),
        'send_delay_min' => (int) env('WA_SEND_DELAY_MIN', 3),
        'send_delay_max' => (int) env('WA_SEND_DELAY_MAX', 10),
        'media_max_mb' => (int) env('WA_MEDIA_MAX_MB', 5),
        'storage_disk' => env('WA_STORAGE_DISK', 'private'),
        'admin_notify_number' => env('WA_ADMIN_NOTIFY_NUMBER', ''),
    ],

];


