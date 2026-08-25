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

        /*
         * `/pos`, which is the URI `pos.create` is registered at
         * (routes/web.php:315) — not `/pos/create`, which is what the route's
         * *name* suggests and which is not a route at all.
         *
         * An installed terminal launches straight here, so a wrong value is a
         * 404 as the first thing a shop sees after installing, on a screen with
         * no address bar to correct it from.
         *
         * It also has to be one of `CACHEABLE_PAGES` in public/sw.js. That list
         * is a runtime allowlist, not a precache list: `handlePage()` (sw.js:252)
         * stores a page only after a *successful online* navigation to it, and
         * install precaches nothing but the shell assets and /pwa/offline. So the
         * case this guards is not the first launch — the cashier installs from
         * this screen while online, which caches it — it is the morning after a
         * sign-out. Signing out clears the whole page cache (clearPrivateCache),
         * so a till that closes down at night and opens with a dead uplink has
         * nothing on the shelf for this path and lands on the fallback page. A
         * start_url outside the allowlist would land there *every* time.
         */
        'start_url' => '/pos',

        /*
         * Explicit, though `/pos` would default to it.
         *
         * The manifest's default scope is `start_url`'s directory, so the old
         * `/pos/create` was silently scoping the installed app to `/pos/` — every
         * other screen would have opened in an ordinary browser tab, and the
         * service worker's scope (`/`, from being served out of the document
         * root) would have disagreed with the manifest's. Stating it removes the
         * dependency on a defaulting rule that has been revised more than once,
         * and makes the two scopes visibly the same one.
         */
        'scope' => '/',
    ],
];
