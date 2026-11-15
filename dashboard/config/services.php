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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
     * Connections to WordPress sites running the SharePoint backup plugin.
     * Production-safe by default; relaxed for APP_ENV=local (self-signed .test sites on 127.0.0.1).
     */
    'wordpress' => [
        'verify_tls' => (bool) env('WP_VERIFY_TLS', env('APP_ENV') !== 'local'),
        'allow_private_hosts' => (bool) env('WP_ALLOW_PRIVATE_HOSTS', env('APP_ENV') === 'local'),
        // Hours past a site's schedule before its newest backup counts as overdue.
        'overdue_grace_hours' => (int) env('WP_BACKUP_OVERDUE_GRACE_HOURS', 6),
    ],

];
