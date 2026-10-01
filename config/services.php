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
        'antiban' => [
            'enabled' => (bool) env('WA_ANTIBAN_ENABLED', true),
            'hourly_max' => (int) env('WA_ANTIBAN_HOURLY_MAX', 10),
            'daily_max' => (int) env('WA_ANTIBAN_DAILY_MAX', 50),
            'delay_min' => (int) env('WA_ANTIBAN_DELAY_MIN', 8),
            'delay_max' => (int) env('WA_ANTIBAN_DELAY_MAX', 20),
            'business_hours_start' => (int) env('WA_ANTIBAN_HOURS_START', 8),
            'business_hours_end' => (int) env('WA_ANTIBAN_HOURS_END', 20),
            'circuit_breaker_threshold' => (int) env('WA_ANTIBAN_CIRCUIT_THRESHOLD', 3),
            'circuit_cooldown_minutes' => (int) env('WA_ANTIBAN_CIRCUIT_COOLDOWN', 30),
            'unknown_reply_max_per_day' => (int) env('WA_ANTIBAN_UNKNOWN_REPLY_MAX', 2),
            'humanize_messages' => (bool) env('WA_ANTIBAN_HUMANIZE', true),
        ],
    ],

];


