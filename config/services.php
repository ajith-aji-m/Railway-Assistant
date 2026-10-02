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

    /*
    | RailRadar (https://railradar.in/docs). The key is read from API_KEY_RAILWAY
    | and is only ever sent in the Authorization header — never logged or exposed.
    */
    'railradar' => [
        'key' => env('API_KEY_RAILWAY'),
        'base_url' => env('RAILRADAR_BASE_URL', 'https://api.railradar.in'),
        'timeout' => (int) env('RAILRADAR_TIMEOUT', 10),
        // Free sandbox plan: 1,000 requests/month — cache live responses briefly.
        'cache_seconds' => (int) env('RAILRADAR_CACHE_SECONDS', 60),
        // Station lookups rarely change: cache search results for a day.
        'search_cache_seconds' => (int) env('RAILRADAR_SEARCH_CACHE_SECONDS', 86400),
        'test_train' => env('RAILRADAR_TEST_TRAIN'),
    ],

];
