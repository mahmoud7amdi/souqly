<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Paymob (Accept) — the project's only payment gateway
    |--------------------------------------------------------------------------
    | Egyptian gateway covering Visa / Mastercard / Meeza cards and mobile
    | wallets (Vodafone Cash, Orange Money, Etisalat Cash, WE Pay).
    |
    | Get these from the Paymob dashboard:
    |   api_key        Settings → Account Info → API Key
    |   integration_id Developers → Payment Integrations (one per method)
    |   iframe_id      Developers → iframes
    |   hmac_secret    Settings → Account Info → HMAC
    */

    'base_url' => env('PAYMOB_BASE_URL', 'https://accept.paymob.com'),

    'api_key' => env('PAYMOB_API_KEY'),

    // Card integration id. Wallet payments use a different integration id —
    // set PAYMOB_WALLET_INTEGRATION_ID to enable the wallet button.
    'integration_id' => env('PAYMOB_INTEGRATION_ID'),
    'wallet_integration_id' => env('PAYMOB_WALLET_INTEGRATION_ID'),

    'iframe_id' => env('PAYMOB_IFRAME_ID'),

    // Used to authenticate callbacks. Without it every callback is rejected.
    'hmac_secret' => env('PAYMOB_HMAC_SECRET'),

    'currency' => env('PAYMOB_CURRENCY', 'EGP'),

    // Seconds a payment key stays valid.
    'expiration' => env('PAYMOB_EXPIRATION', 3600),
];
