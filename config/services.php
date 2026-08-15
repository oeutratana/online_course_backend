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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'bakong' => [
        'base_url' => env('BAKONG_API_BASE_URL', 'https://api.bakong-api.online'),
        'official_base_url' => env('BAKONG_OFFICIAL_BASE_URL', 'https://api-bakong.nbc.gov.kh'),
        'token' => env('BAKONG_TOKEN'),
        'bakong_id' => env('BAKONG_ID'),
        'merchant_name' => env('BAKONG_MERCHANT_NAME', env('APP_NAME', 'Online Course')),
        'merchant_city' => env('BAKONG_MERCHANT_CITY', 'Phnom Penh'),
        'currency' => env('BAKONG_CURRENCY', '840'),
        'qr_mode' => env('BAKONG_QR_MODE', 'local'),
        'qr_ttl_minutes' => (int) env('BAKONG_QR_TTL_MINUTES', 60),
    ],

];
