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
     * Payment gateway.
     *
     * `fake` (the default) forces FakePaymentGateway for every association, so
     * a deployment takes no real money until somebody deliberately changes it.
     * `auto` lets each association's own `gateway_credentials.provider` decide -
     * `spg` for SPG directly, `payflex_spg` for SPG through PayFlex.
     *
     * The default is deliberately the safe one. An environment that has not
     * been thought about should not be able to collect money.
     *
     * Merchant CREDENTIALS never belong here: they live per association in the
     * tenant database, encrypted, because each association holds its own
     * merchant account (A-1).
     */
    'gateway' => [
        'driver' => env('PAYMENT_GATEWAY', 'fake'),
    ],

];
