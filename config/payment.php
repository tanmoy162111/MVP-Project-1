<?php

/**
 * Payment Gateway Configuration
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║  PLACEHOLDER CREDENTIALS — Set these in .env before going live          ║
 * ╠══════════════════════════════════════════════════════════════════════════╣
 * ║  bKash                                                                  ║
 * ║    BKASH_APP_KEY       Real bKash merchant App Key    ← PLACEHOLDER     ║
 * ║    BKASH_APP_SECRET    Real bKash App Secret          ← PLACEHOLDER     ║
 * ║    BKASH_USERNAME      Real bKash Username            ← PLACEHOLDER     ║
 * ║    BKASH_PASSWORD      Real bKash Password            ← PLACEHOLDER     ║
 * ║    BKASH_BASE_URL      sandbox vs production URL      ← default sandbox ║
 * ║                                                                         ║
 * ║  SSLCommerz                                                             ║
 * ║    SSLCOMMERZ_STORE_ID      Real Store ID             ← PLACEHOLDER     ║
 * ║    SSLCOMMERZ_STORE_PASSWD  Real Store Password       ← PLACEHOLDER     ║
 * ║    SSLCOMMERZ_IS_LIVE       false = sandbox           ← default false   ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * Until credentials are provided, all gateway calls return mock responses
 * and is_mock: true in the API response. No real money is moved.
 */

return [

    'bkash' => [
        'app_key'    => env('BKASH_APP_KEY',    'PLACEHOLDER'),   // ← PLACEHOLDER
        'app_secret' => env('BKASH_APP_SECRET', 'PLACEHOLDER'),   // ← PLACEHOLDER
        'username'   => env('BKASH_USERNAME',   'PLACEHOLDER'),   // ← PLACEHOLDER
        'password'   => env('BKASH_PASSWORD',   'PLACEHOLDER'),   // ← PLACEHOLDER
        'base_url'   => env('BKASH_BASE_URL',   'https://tokenized.sandbox.bka.sh/v1.2.0-beta'),
    ],

    'sslcommerz' => [
        'store_id'     => env('SSLCOMMERZ_STORE_ID',     'PLACEHOLDER'),  // ← PLACEHOLDER
        'store_passwd' => env('SSLCOMMERZ_STORE_PASSWD', 'PLACEHOLDER'),  // ← PLACEHOLDER
        'is_live'      => env('SSLCOMMERZ_IS_LIVE',      false),
    ],

];
