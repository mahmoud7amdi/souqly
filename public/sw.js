/**
 * Souqly service worker.
 *
 * WHY THIS FILE LIVES IN `public/` AND NOT IN `resources/js/`
 *
 * Vite would fingerprint it. A service worker's identity *is* its URL: the
 * registration is remembered against that exact path, and its scope is the
 * directory the script was served from. A hashed name under `/build/assets/`
 * would mean a new worker every build, scoped to `/build/assets/` — unable to
 * intercept a single page request. So this file is hand-written, unbundled, and
 * served from the document root, which is also the only way it gets scope `/`
 * without a `Service-Worker-Allowed` header.
 *
 * The cost of being unbundled is that nothing here may use `import`, and the
 * cost of being static is that it cannot read `config/pwa.php`. Neither matters:
 * the worker's job is caching, and every decision that depends on configuration
 * (whether to register at all, how often to poll, how many documents to queue)
 * belongs to the page — see `resources/js/offline.js`.
 *
 * WHAT IT DOES NOT DO
 *
 * It does not intercept the POS's POST. Queueing a sale in the worker — the
 * "background sync" shape — would put the shop's unsent takings somewhere the
 * cashier cannot see, in a store the page cannot read back, and would make
 * "did that sale save?" unanswerable from the screen. The queue is the page's,
 * held in IndexedDB, with a visible count and a list. This file only makes the
 * screen reachable while the uplink is down.
 *
 * It also never caches `/api/ping`. That endpoint is the one honest answer to
 * "are we online", and a cache hit would make the badge report a connection
 * that is not there — a cashier would keep selling into a queue they believe is
 * being drained.
 */

/* ------------------------------------------------------------------ *
 * Cache names
 *
 * The asset cache is versioned by the Vite manifest, so a rebuild rotates it on
 * its own and no constant here ever needs bumping. The two entry filenames are
 * enough: both are content-hashed, so any change to CSS or JS produces a new
 * pair, and a pair that has not changed is genuinely the same bundle.
 *
 * The page cache is deliberately NOT versioned that way. It holds authenticated
 * HTML and is cleared on sign-out, which is a different lifecycle from "a new
 * build shipped".
 * ------------------------------------------------------------------ */
const ASSET_CACHE_PREFIX = 'souqly-assets-';
const PAGE_CACHE = 'souqly-pages';

const MANIFEST_URL = '/build/manifest.json';
const OFFLINE_URL = '/pwa/offline';

/* Pages worth having on the shelf when the uplink dies. The terminal is the
   point of the exercise; the dashboard is there so the shop is not staring at a
   fallback page the moment it loses signal. Anything else falls back. */
const CACHEABLE_PAGES = ['/pos', '/home'];

/* ------------------------------------------------------------------ *
 * Install — precache the shell
 * ------------------------------------------------------------------ */

/**
 * Asset URLs to precache, read from the real Vite manifest.
 *
 * Only `.woff2` of the font files. The manifest lists a `.woff` beside every
 * `.woff2` as a fallback for browsers that predate it — but a browser without
 * woff2 also lacks service workers, so precaching both would double the install
 * download to serve nobody.
 */
async function shellAssets() {
    const response = await fetch(MANIFEST_URL, { cache: 'no-store' });

    if (!response.ok) {
        throw new Error('Vite manifest unavailable: ' + response.status);
    }

    const manifest = await response.json();
    const urls = [];
    let version = '';

    for (const key of Object.keys(manifest)) {
        const file = manifest[key].file;

        if (!file) continue;

        const isEntry = manifest[key].isEntry === true;
        const isFont = /\.woff2$/i.test(file);
        const isStylesheet = /\.css$/i.test(file);

        if (isEntry || isFont || isStylesheet) {
            urls.push('/build/' + file);
        }

        if (isEntry) {
            version += (version ? '~' : '') + file.replace(/^assets\//, '');
        }
    }

    return { urls, version };
}

self.addEventListener('install', (event) => {
    event.waitUntil(
        (async () => {
            const { urls, version } = await shellAssets();
            const cache = await caches.open(ASSET_CACHE_PREFIX + version);

            /*
             * Individually, not addAll. addAll rejects as a unit: one 404 among
             * thirty font files would abandon the whole install and leave the
             * shop with no offline shell at all. A missing asset is a degraded
             * cache; a failed install is no cache.
             */
            await Promise.all(
                urls.map((url) => cache.add(new Request(url, { cache: 'reload' })).catch(() => null))
            );

            const pages = await caches.open(PAGE_CACHE);
            await pages.add(new Request(OFFLINE_URL, { cache: 'reload' })).catch(() => null);

            /*
             * Active immediately, but WITHOUT clients.claim(). A worker that
             * takes over a tab mid-sale changes which cache is answering
             * halfway through a basket; open terminals keep the worker they
             * started with and adopt the new one on their next navigation.
             */
            await self.skipWaiting();
        })()
    );
});

/* ------------------------------------------------------------------ *
 * Activate — drop superseded asset caches
 * ------------------------------------------------------------------ */
self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            const { version } = await shellAssets().catch(() => ({ version: null }));
            const keep = version ? ASSET_CACHE_PREFIX + version : null;

            const names = await caches.keys();

            await Promise.all(
                names
                    .filter((name) => name.startsWith(ASSET_CACHE_PREFIX) && name !== keep)
                    .map((name) => caches.delete(name))
            );
        })()
    );
});

/* ------------------------------------------------------------------ *
 * Messages from the page
 * ------------------------------------------------------------------ */
self.addEventListener('message', (event) => {
    if (event.data === 'CLEAR_PRIVATE_CACHE') {
        event.waitUntil(clearPrivateCache());
    }
});

/**
 * Forget every cached authenticated page.
 *
 * A till is a shared machine. The terminal's HTML carries a customer list and
 * the shop's own figures, so leaving it in a cache that survives sign-out would
 * hand the next person on the keyboard a readable copy of the last shift. The
 * offline fallback is put straight back, because it contains nothing and is what
 * the browser needs if the next request fails.
 */
async function clearPrivateCache() {
    await caches.delete(PAGE_CACHE);

    const pages = await caches.open(PAGE_CACHE);
    await pages.add(new Request(OFFLINE_URL, { cache: 'reload' })).catch(() => null);
}

/* ------------------------------------------------------------------ *
 * Fetch
 * ------------------------------------------------------------------ */
self.addEventListener('fetch', (event) => {
    const request = event.request;

    // Anything that changes state goes to the network untouched. See the header.
    if (request.method !== 'GET') return;

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) return;

    // The connectivity oracle. Never cached, never answered from a cache.
    if (url.pathname === '/api/ping') return;

    if (request.mode === 'navigate') {
        event.respondWith(handlePage(request, url));

        return;
    }

    if (url.pathname.startsWith('/build/')) {
        event.respondWith(cacheFirst(request));

        return;
    }

    /*
     * Uploaded images — product photos and the shop's logo — are content the
     * grid looks wrong without, and they change rarely. Cache-first with a
     * network fill, in the page cache so they are cleared with everything else
     * on sign-out.
     */
    if (url.pathname.startsWith('/uploads/') || url.pathname.startsWith('/img/')) {
        event.respondWith(cacheFirst(request, PAGE_CACHE));

        return;
    }

    /*
     * Everything else — the product feed, the unread-notification count, the
     * offline snapshot — is network-only. Offline reads come out of IndexedDB,
     * which the page owns; a second copy in the HTTP cache would be a second
     * answer to the same question, and the two would disagree the moment one
     * was refreshed and the other was not.
     */
});

/**
 * Navigation: network first, cache second, fallback page last.
 *
 * Network first rather than cache first because a stale terminal is worse than a
 * slow one — prices and stock move during a shift, and the whole point of being
 * online is to see them.
 */
async function handlePage(request, url) {
    try {
        const response = await fetch(request);

        /*
         * Reaching the sign-in screen means this browser is no longer holding a
         * session, whatever the reason — signed out, expired, revoked. That is
         * the moment to drop the cached pages, and it catches the cases the
         * page's own logout hook cannot: an expired session, a sign-out from
         * another tab, a browser reopened days later.
         */
        if (new URL(response.url).pathname === '/login') {
            await clearPrivateCache();

            return response;
        }

        if (response.ok && CACHEABLE_PAGES.includes(url.pathname)) {
            const cache = await caches.open(PAGE_CACHE);
            cache.put(request, response.clone());
        }

        return response;
    } catch (error) {
        const cached = await caches.match(request, { ignoreSearch: true });

        if (cached) return cached;

        const fallback = await caches.match(OFFLINE_URL);

        if (fallback) return fallback;

        throw error;
    }
}

/**
 * Cache first, with the network as the filler.
 *
 * Correct for `/build/*` because those names are content hashes: the bytes
 * behind one never change, so a hit is never stale and a miss is a new build.
 */
async function cacheFirst(request, cacheName = null) {
    const cached = await caches.match(request);

    if (cached) return cached;

    const response = await fetch(request);

    if (response.ok && cacheName) {
        const cache = await caches.open(cacheName);
        cache.put(request, response.clone());
    }

    return response;
}
