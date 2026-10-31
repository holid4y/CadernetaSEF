<?php

return [

    'forms' => [
        'enabled' => env('FORMS_ENABLED', false),
        'webhook_secret' => env('FORMS_WEBHOOK_SECRET', ''),
        'allowed_hosts' => array_values(array_filter(array_map(
            fn (string $host): string => strtolower(trim($host)),
            explode(',', (string) env('FORMS_ALLOWED_HOSTS', '')),
        ))),
        'create_url' => env('FORMS_CREATE_URL'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    ],

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

];
