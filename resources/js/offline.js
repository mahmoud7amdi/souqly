/**
 * The offline layer: a product cache the terminal can read and a queue it can
 * sell into while the shop has no uplink.
 *
 * THE SHAPE OF THE PROBLEM
 *
 * A point-of-sale terminal is the one screen in an ERP that cannot answer "come
 * back later". The customer is at the counter with cash in their hand. So the
 * question is not "how do we degrade gracefully" but "what is the smallest set
 * of facts the till needs on the device to complete a sale, and how do we get
 * that sale to the server exactly once afterwards".
 *
 * Two answers, and they are the two halves of this file:
 *
 *   The snapshot — products with their prices, taken from the server while it is
 *   reachable, kept in IndexedDB, searched locally with the same predicates the
 *   server uses. Prices go stale; that is accepted and visible (the terminal says
 *   how old the snapshot is), because a stale price is a smaller error than a
 *   refused sale.
 *
 *   The queue — the sale itself, written to IndexedDB before anything else
 *   happens, with a `temp_id` generated once at that moment and never
 *   regenerated. That id is the whole idempotency story: it travels with every
 *   retry, and the server has a unique index on it, so a reply lost on the way
 *   back cannot turn one sale into two. See the migration that adds the index —
 *   it explains why the index and the controller's lookup are both needed.
 *
 * WHY NOT BACKGROUND SYNC IN THE SERVICE WORKER
 *
 * Because a cashier has to be able to answer "did that sale save?" by looking at
 * the screen. A queue held by the worker is invisible to the page, is not
 * enumerable from it, and drains at a time the browser chooses. Held here it has
 * a count in the header, a list the cashier can read back, and a button that
 * retries now. The worker's job stops at making the page load; see `public/sw.js`.
 *
 * NO LIBRARY
 *
 * `idb`, `workbox` and friends would each be a dependency in a bundle that is
 * currently framework-free, to wrap an API this file uses six methods of. The
 * promise wrapper below is twenty lines.
 */

/* ------------------------------------------------------------------ *
 * Configuration
 *
 * Rendered by the layout as a JSON island rather than an inline assignment, so
 * `config/pwa.php` stays the single source of truth without the layout having to
 * emit executable JavaScript. Every value has a fallback: a page that forgot the
 * island still works, it just uses the defaults.
 * ------------------------------------------------------------------ */
const DEFAULTS = {
    enabled: false,
    offline_mode: false,
    ping_interval: 20,
    auto_sync_interval: 60,
    max_queued_documents: 500,
};

let settings = null;

/**
 * The PWA settings, read once and memoised.
 *
 * A function rather than a module-level constant so that `initConnectionStatus()`
 * and `initOffline()` stay order-independent: whichever runs first pays for the
 * parse, and neither has to know the other ran. It also means the island's
 * position in the document is not a load-order constraint on this module.
 */
function pwaSettings() {
    if (!settings) settings = readSettings();

    return settings;
}

function readSettings() {
    const island = document.getElementById('pwa-config');

    if (!island) return { ...DEFAULTS };

    try {
        return { ...DEFAULTS, ...JSON.parse(island.textContent) };
    } catch {
        return { ...DEFAULTS };
    }
}

/* ------------------------------------------------------------------ *
 * IndexedDB
 * ------------------------------------------------------------------ */
const DB_NAME = 'souqly';
const DB_VERSION = 1;

const QUEUE = 'queue';
const SNAPSHOT = 'snapshot';

let dbPromise = null;

function openDb() {
    if (dbPromise) return dbPromise;

    dbPromise = new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION);

        request.onupgradeneeded = () => {
            const db = request.result;

            // Keyed by temp_id: the id IS the identity of a queued sale, on the
            // device exactly as on the server, so a re-queue of the same sale
            // overwrites rather than duplicating.
            if (!db.objectStoreNames.contains(QUEUE)) {
                db.createObjectStore(QUEUE, { keyPath: 'temp_id' });
            }

            // One record per (location, kind) — 'products@3', 'meta'.
            if (!db.objectStoreNames.contains(SNAPSHOT)) {
                db.createObjectStore(SNAPSHOT, { keyPath: 'key' });
            }
        };

        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
        request.onblocked = () => reject(new Error('IndexedDB upgrade blocked'));
    });

    return dbPromise;
}

function transact(store, mode, work) {
    return openDb().then(
        (db) =>
            new Promise((resolve, reject) => {
                const tx = db.transaction(store, mode);
                const request = work(tx.objectStore(store));

                tx.oncomplete = () => resolve(request?.result);
                tx.onerror = () => reject(tx.error);
                tx.onabort = () => reject(tx.error);
            })
    );
}

const put = (store, value) => transact(store, 'readwrite', (s) => s.put(value));
const del = (store, key) => transact(store, 'readwrite', (s) => s.delete(key));
const get = (store, key) => transact(store, 'readonly', (s) => s.get(key));
const all = (store) => transact(store, 'readonly', (s) => s.getAll());

/* ------------------------------------------------------------------ *
 * Device identity
 *
 * Recorded on the sale as `offline_device_id`, which is what makes a duplicate or
 * a clock problem traceable to one till rather than to "the shop". localStorage
 * rather than IndexedDB because it has to be readable synchronously while
 * building a payload, and it is one short string.
 * ------------------------------------------------------------------ */
const DEVICE_KEY = 'souqly.device_id';

function deviceId() {
    let id = null;

    try {
        id = localStorage.getItem(DEVICE_KEY);
    } catch {
        /* private mode, or storage disabled */
    }

    if (!id) {
        id = uuid();

        try {
            localStorage.setItem(DEVICE_KEY, id);
        } catch {
            /* A per-session id is still better than none: it distinguishes two
               tills syncing at the same moment, which is what it is read for. */
        }
    }

    return id;
}

/**
 * `crypto.randomUUID` is unavailable on plain HTTP origins, and a shop's till is
 * exactly where someone runs the app over http://192.168.x.x. Falling back to
 * `getRandomValues` keeps the id unguessable there; falling back further to
 * `Math.random` keeps the till selling on an ancient browser, at the cost of
 * uniqueness guarantees — which is the right way round, because the unique index
 * on the server is what actually enforces uniqueness.
 */
function uuid() {
    if (crypto?.randomUUID) return crypto.randomUUID();

    if (crypto?.getRandomValues) {
        const bytes = crypto.getRandomValues(new Uint8Array(16));

        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;

        const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');

        return (
            hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) +
            '-' + hex.slice(16, 20) + '-' + hex.slice(20)
        );
    }

    return 'x-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
}

/* ------------------------------------------------------------------ *
 * The product snapshot
 * ------------------------------------------------------------------ */

const snapshotKey = (locationId) => 'products@' + (locationId || 0);

/**
 * Pull a fresh snapshot for one location.
 *
 * Per location, not per business: stock and price group differ by shop, and a
 * till only ever sells from one. Fetching all of them would multiply the payload
 * by the number of branches to cache rows the terminal will never show.
 */
async function refreshSnapshot(locationId, priceGroupId = null) {
    const params = new URLSearchParams({ location_id: locationId ?? '' });

    if (priceGroupId) params.set('price_group_id', priceGroupId);

    const response = await fetch('/offline/data?' + params, {
        headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        cache: 'no-store',
    });

    if (!response.ok) throw new Error('Snapshot refresh failed: ' + response.status);

    const body = await response.json();

    await put(SNAPSHOT, {
        key: snapshotKey(locationId),
        taken_at: new Date().toISOString(),
        price_group_id: priceGroupId ?? null,
        products: body.products ?? [],
    });

    return body.products?.length ?? 0;
}

async function snapshotFor(locationId) {
    return (await get(SNAPSHOT, snapshotKey(locationId))) ?? null;
}

/**
 * Local product search.
 *
 * Mirrors the server's feed closely enough that the POS grid cannot tell which
 * one answered — same row shape, same limit of 25. One deliberate divergence: the
 * server matches SKUs by prefix and names by substring, this matches everything
 * by substring. It errs towards finding more, because a cashier who cannot find
 * an item offline cannot sell it, whereas an extra row costs a glance. The
 * scanner path is unaffected: it tests `sku === term` exactly, not the ordering.
 */
async function searchProducts(term, locationId) {
    const snapshot = await snapshotFor(locationId);

    if (!snapshot) return [];

    const needle = (term ?? '').trim().toLowerCase();

    if (needle === '') return snapshot.products.slice(0, 25);

    return snapshot.products
        .filter((product) => (product.search ?? '').includes(needle))
        .slice(0, 25);
}

/* ------------------------------------------------------------------ *
 * The queue
 * ------------------------------------------------------------------ */

/**
 * Write a sale to the device.
 *
 * The temp id and the timestamp are stamped HERE, once, at the moment the cashier
 * finalises — not when the sale is sent. Both matter:
 *
 *   `temp_id` is the de-duplication key. Generating it at send time would mean a
 *   retry carried a different one, and the retry would be recorded as a second
 *   sale.
 *
 *   `created_at` is when money changed hands. A till that was offline all Tuesday
 *   evening and syncs on Wednesday morning must not report Tuesday's takings as
 *   Wednesday's — the day would reconcile against a drawer that was counted the
 *   night before.
 */
async function queueSale(payload) {
    const queued = await all(QUEUE);

    if (queued.length >= Number(pwaSettings().max_queued_documents)) {
        /*
         * Refused, loudly, rather than dropped or written over the oldest. Both
         * of those lose a real sale silently, which is the one outcome this whole
         * layer exists to prevent. The cap is not arbitrary caution either:
         * IndexedDB has a quota, and a till that has been offline for a week with
         * nobody noticing is a shop that needs to be told, not helped along.
         */
        throw new QueueFullError(queued.length);
    }

    const sale = {
        ...payload,
        temp_id: uuid(),
        device_id: deviceId(),
        created_at: new Date().toISOString(),
        queued_at: new Date().toISOString(),
        attempts: 0,
        error: null,
    };

    await put(QUEUE, sale);
    await announce();

    return sale;
}

class QueueFullError extends Error {
    constructor(count) {
        super('Offline queue is full (' + count + ' documents).');
        this.name = 'QueueFullError';
        this.count = count;
    }
}

const pending = () => all(QUEUE);

/**
 * Drop one queued sale without sending it.
 *
 * The counterpart to the terminal's write-ahead log. Every sale is written to the
 * queue *before* the online POST is attempted, so that a request which dies in
 * flight — uplink drops mid-upload, the tab is closed, the browser is killed —
 * leaves the sale on the device rather than nowhere. When the POST does succeed
 * the server hands the temp id back in the flash, and this removes the copy that
 * is no longer needed.
 *
 * Losing that acknowledgement is harmless, which is the point of doing it this
 * way: the entry is then simply synced like any other, the server recognises the
 * temp id it already stored and answers `duplicate`, and the client deletes it.
 * This function only saves the badge from briefly reading "1 pending" after a
 * sale that plainly succeeded.
 */
async function forget(tempId) {
    if (!tempId) return;

    await del(QUEUE, tempId);
    await announce();
}

/* ------------------------------------------------------------------ *
 * Sync
 * ------------------------------------------------------------------ */

let syncing = false;

/**
 * Sales per request.
 *
 * Must not exceed `OfflineSyncController::MAX_PER_REQUEST`, which refuses a
 * larger batch outright — a till that sent 500 at once would be told 422 and
 * would never drain. Chunking here rather than raising the server's cap because
 * each sale writes a document, its lines, the stock cache, the FIFO map and its
 * payments, and the number that fits in one request is a property of the server's
 * execution limit, not of how patient the till is.
 */
const CHUNK = 25;

/**
 * Send everything queued and act on the per-sale verdicts.
 *
 * Serialised behind a flag: two overlapping drains would each read the same queue
 * and send the same sales, which the server would de-duplicate correctly but which
 * would still double the upload and make the UI count jump about.
 *
 * `accepted` and `duplicate` both mean "the server has this sale", so both drop it
 * from the queue — treating `duplicate` as a failure is how a queue that can never
 * be emptied is built. `rejected` means the server refused it on its merits (a
 * product deleted while the till was offline, a validation rule it cannot satisfy);
 * those stay, with the reason attached, because a person has to look at them.
 * Anything else — a network error, a 500 — leaves the sale untouched for the next
 * attempt.
 *
 * A failed chunk stops the drain rather than moving on to the next one. Chunks
 * fail for one of two reasons, and both say "stop": the network went away again,
 * or the server is refusing requests. Ploughing on would turn one failure into
 * twenty and, on a metered connection, would spend the shop's data doing it.
 */
async function sync() {
    if (syncing) return { sent: 0, accepted: 0, duplicate: 0, rejected: 0, skipped: true };

    const queued = await pending();

    if (queued.length === 0) return { sent: 0, accepted: 0, duplicate: 0, rejected: 0 };

    syncing = true;

    const tally = { sent: 0, accepted: 0, duplicate: 0, rejected: 0 };

    try {
        for (let at = 0; at < queued.length; at += CHUNK) {
            const chunk = queued.slice(at, at + CHUNK);
            const outcome = await sendChunk(chunk, tally);

            if (outcome.error) return { ...tally, error: outcome.error };
        }

        return tally;
    } finally {
        syncing = false;
        await announce();
    }
}

/**
 * One request's worth. Mutates the running tally; returns the transport verdict.
 *
 * @param {Array<object>} chunk
 * @param {{sent: number, accepted: number, duplicate: number, rejected: number}} tally
 */
async function sendChunk(chunk, tally) {
    let body = null;

    try {
        const response = await fetch('/offline/sync', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify({
                device_id: deviceId(),
                sales: chunk.map(forWire),
            }),
        });

        if (!response.ok) return { error: 'HTTP ' + response.status };

        body = await response.json();
    } catch (error) {
        return { error: error.message };
    }

    tally.sent += chunk.length;

    for (const result of body.results ?? []) {
        const sale = chunk.find((item) => item.temp_id === result.temp_id);

        if (!sale) continue;

        if (result.status === 'accepted' || result.status === 'duplicate') {
            await del(QUEUE, sale.temp_id);
            tally[result.status] += 1;

            continue;
        }

        sale.attempts += 1;
        sale.error = result.message ?? null;
        await put(QUEUE, sale);
        tally.rejected += 1;
    }

    return {};
}

/**
 * The wire form of a queued sale.
 *
 * Bookkeeping fields — `attempts`, `error`, `queued_at` — are the device's own
 * notes and are stripped: the server has no use for them, and sending them would
 * invite a validation rule that has to know about them.
 */
function forWire(sale) {
    const { attempts, error, queued_at, ...rest } = sale;

    return rest;
}

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

/* ------------------------------------------------------------------ *
 * Telling the page
 *
 * A DOM event rather than a callback registry: the header badge and the POS
 * drawer both want the count, they are rendered by different Blade files, and
 * neither should have to be wired to this module by hand.
 * ------------------------------------------------------------------ */
async function announce() {
    const queued = await pending();

    document.dispatchEvent(
        new CustomEvent('souqly:queue', {
            detail: { pending: queued.length, sales: queued },
        })
    );
}

/* ------------------------------------------------------------------ *
 * Form serialisation
 *
 * Turns `lines[0][variation_id]` into `{lines: [{variation_id: …}]}` so a queued
 * sale is the same JSON the sync endpoint validates and the POS controller
 * already accepts. Numeric segments become array indices, which is what PHP does
 * with the same names on the way in — the two ends agree by construction rather
 * than by a second schema.
 * ------------------------------------------------------------------ */
function serialise(form) {
    const output = {};

    for (const [name, value] of new FormData(form).entries()) {
        if (name === '_token') continue;

        const path = name.replace(/\]/g, '').split('[');

        let node = output;

        path.forEach((segment, depth) => {
            const last = depth === path.length - 1;
            const next = path[depth + 1];

            if (last) {
                node[segment] = value;

                return;
            }

            node[segment] ??= /^\d+$/.test(next) ? [] : {};
            node = node[segment];
        });
    }

    // FormData gives sparse arrays holes where a cart row was removed; PHP would
    // receive those as `null` entries and the line validator would refuse them.
    for (const key of Object.keys(output)) {
        if (Array.isArray(output[key])) {
            output[key] = output[key].filter(Boolean);
        }
    }

    return output;
}

/* ------------------------------------------------------------------ *
 * Service worker registration
 * ------------------------------------------------------------------ */
function registerWorker() {
    if (!('serviceWorker' in navigator)) return;

    if (!pwaSettings().enabled) {
        /*
         * Turning the feature off has to actually turn it off. A worker
         * registered by an earlier visit keeps serving cached pages forever
         * otherwise, and the operator who set PWA_ENABLED=false would have no way
         * to tell why the shop is still seeing yesterday's terminal.
         */
        navigator.serviceWorker.getRegistrations().then((registrations) => {
            registrations.forEach((registration) => registration.unregister());
        });

        return;
    }

    navigator.serviceWorker.register('/sw.js').catch(() => {
        /* No worker means no offline shell. The queue still works for as long as
           the page stays open, which is the case that matters mid-shift. */
    });

    // Sign-out is the page's cue to drop cached authenticated HTML from a shared
    // till. The worker also clears it whenever it sees the sign-in screen, which
    // covers an expired session; this covers the deliberate case immediately.
    document.addEventListener('submit', (event) => {
        if (event.target.matches?.('form[action$="/logout"]')) {
            navigator.serviceWorker.controller?.postMessage('CLEAR_PRIVATE_CACHE');
        }
    });
}

/* ------------------------------------------------------------------ *
 * Init
 * ------------------------------------------------------------------ */
function initOffline() {
    const config = pwaSettings();

    // Registration runs either way: when the feature is off it is what tears down
    // a worker a previous visit left behind.
    registerWorker();

    if (!config.enabled || !config.offline_mode) return;

    announce();

    /*
     * Drain on the way back up, on two triggers because they fail in opposite
     * directions. `online` fires on link state — early, and true behind a captive
     * portal that answers nothing. The connectivity probe dispatches
     * `souqly:online` only after /api/ping has actually replied — right, and up to
     * one interval late.
     */
    window.addEventListener('online', () => sync());
    document.addEventListener('souqly:online', () => sync());

    setInterval(() => {
        if (navigator.onLine) sync();
    }, Number(config.auto_sync_interval) * 1000);
}

const offline = {
    settings: pwaSettings,
    deviceId,
    refreshSnapshot,
    snapshotFor,
    searchProducts,
    queueSale,
    pending,
    forget,
    sync,
    serialise,
    QueueFullError,
};

export { initOffline, offline, pwaSettings };
