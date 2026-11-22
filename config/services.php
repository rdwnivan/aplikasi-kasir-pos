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

    /*
    | Midtrans Core API, used for QRIS. Sandbox unless MIDTRANS_IS_PRODUCTION=true.
    | Keys come from the Midtrans dashboard (Settings → Access Keys).
    */
    'midtrans' => [
        'server_key' => env('MIDTRANS_SERVER_KEY'),
        'client_key' => env('MIDTRANS_CLIENT_KEY'),
        // Signs payment notifications (Access Keys → Payment → Signature Secret Key). Empty: the
        // server key is used, which only matches on accounts where the two are still equal.
        'signature_key' => env('MIDTRANS_SIGNATURE_KEY'),
        // The one before the last rotation; set briefly so retries signed with it still pass.
        'signature_key_previous' => env('MIDTRANS_SIGNATURE_KEY_PREVIOUS'),
        'is_production' => (bool) env('MIDTRANS_IS_PRODUCTION', false),
        'qris_acquirer' => env('MIDTRANS_QRIS_ACQUIRER', 'gopay'),
        'qris_expiry_minutes' => (int) env('MIDTRANS_QRIS_EXPIRY_MINUTES', 15),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
