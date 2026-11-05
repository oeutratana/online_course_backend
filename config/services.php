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

    'google' => [
    'client_id' => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),

    'redirect' => env('GOOGLE_REDIRECT_URI')
        ?: rtrim(env('APP_URL', 'http://localhost'), '/')
        . '/api/auth/google/callback',

    'frontend_callback' => env(
        'GOOGLE_FRONTEND_REDIRECT_URI',
        'http://localhost:5173/auth/google/callback'
    ),
],

    'payway' => [
        'env' => env('PAYWAY_ENV', 'sandbox'),
        'base_url' => env('PAYWAY_BASE_URL', env('PAYWAY_ENV', 'sandbox') === 'production'
            ? 'https://checkout.payway.com.kh'
            : 'https://checkout-sandbox.payway.com.kh'),
        'merchant_id' => env('PAYWAY_MERCHANT_ID'),
        'api_key' => env('PAYWAY_API_KEY'),
        'callback_secret' => env('PAYWAY_CALLBACK_SECRET'),
        'purchase_url' => env('PAYWAY_PURCHASE_URL'),
        'check_url' => env('PAYWAY_CHECK_URL'),
        'frontend_return_url' => env('PAYWAY_FRONTEND_RETURN_URL', env('PAYWAY_RETURN_URL', env('APP_URL', 'http://localhost'))),
        'cancel_url' => env('PAYWAY_CANCEL_URL', env('PAYWAY_FRONTEND_RETURN_URL', env('PAYWAY_RETURN_URL', env('APP_URL', 'http://localhost')))),
        'callback_url' => env('PAYWAY_CALLBACK_URL'),
        'currency' => env('PAYWAY_CURRENCY', 'USD'),
        'payment_option' => env('PAYWAY_PAYMENT_OPTION', 'abapay_khqr_deeplink'),
        'lifetime_minutes' => (int) env('PAYWAY_LIFETIME_MINUTES', 60),
    ],

];
