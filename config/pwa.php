<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Progressive Web App / offline POS
    |--------------------------------------------------------------------------
    */
    'enabled' => env('PWA_ENABLED', true),

    // When false the POS refuses to take orders without a connection.
    'offline_mode' => env('PWA_OFFLINE_MODE', true),

    // Seconds between automatic background sync attempts.
    'auto_sync_interval' => env('PWA_AUTO_SYNC_INTERVAL', 60),

    // Seconds between connectivity probes against /api/ping.
    'ping_interval' => env('PWA_PING_INTERVAL', 20),

    // Maximum number of queued offline documents before the POS warns.
    'max_queued_documents' => env('PWA_MAX_QUEUED_DOCUMENTS', 500),

    'manifest' => [
        'name' => env('APP_TITLE', 'Souqly ERP'),
        'short_name' => 'Souqly',
        // Design system v2.1 — brand-500 and the slate-100 canvas. Kept in step
        // with resources/css/app.css by hand: a manifest cannot read a CSS
        // custom property, and an installed POS showing last year's teal in its
        // task switcher looks like a different application.
        'theme_color' => '#00a76f',
        'background_color' => '#eef3f1',
        'display' => 'standalone',
        'orientation' => 'any',
        'start_url' => '/pos/create',
    ],
];
