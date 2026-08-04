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

    'whatsapp' => [
        'meta' => [
            'base_url' => env('WHATSAPP_META_BASE_URL', 'https://graph.facebook.com'),
            'api_version' => env('WHATSAPP_META_API_VERSION', 'v23.0'),
            'webhook_verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
            'app_id' => env('META_APP_ID'),
            'app_secret' => env('META_APP_SECRET'),
            'embedded_signup_config_id' => env('META_ES_CONFIG_ID'),
        ],
    ],

    'openai' => [
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
        'api_key' => env('OPENAI_API_KEY'),
        'default_model_key' => env('OPENAI_DEFAULT_MODEL_KEY', env('OPENAI_DEFAULT_MODEL', 'balanced')),
        'reasoning_effort' => env('OPENAI_REASONING_EFFORT', 'low'),
        'timeout' => (int) env('OPENAI_TIMEOUT', 30),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_CALENDAR_REDIRECT_URI'),
        'calendar_base_url' => env('GOOGLE_CALENDAR_BASE_URL', 'https://www.googleapis.com/calendar/v3'),
        'oauth_token_url' => env('GOOGLE_OAUTH_TOKEN_URL', 'https://oauth2.googleapis.com/token'),
        'oauth_authorize_url' => env('GOOGLE_OAUTH_AUTHORIZE_URL', 'https://accounts.google.com/o/oauth2/v2/auth'),
    ],

];
