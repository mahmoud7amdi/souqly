/**
 * Souqly front-end runtime.
 *
 * Deliberately dependency-free: the UI needs a sidebar, dropdowns, confirm
 * dialogs, a connectivity badge and a live-notification hook. Everything else
 * is server-rendered Blade, so a framework would be dead weight.
 */

import { initOffline, offline, pwaSettings } from './offline.js';

/* ------------------------------------------------------------------ *
 * CSRF for every fetch we make
 * ------------------------------------------------------------------ */
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

const request = (url, options = {}) =>
    fetch(url, {
        headers: {
            'X-CSRF-TOKEN': csrfToken,
            'X-Requested-With': 'XMLHttpRequest',
            Accept: 'application/json',
            ...(options.body instanceof FormData ? {} : { 'Content-Type': 'application/json' }),
            ...(options.headers ?? {}),
        },
        ...options,
    });

/* ------------------------------------------------------------------ *
 * Sidebar (mobile)
 * ------------------------------------------------------------------ */
function initSidebar() {
    const sidebar = document.getElementById('sidebar');
    const toggle = document.getElementById('sidebar-toggle');
    const scrim = document.getElementById('sidebar-scrim');

    if (!sidebar || !toggle) return;

    // RTL slides in from the opposite side; the class flips with it.
    const isRtl = document.documentElement.dir === 'rtl';
    const hiddenClass = isRtl ? 'translate-x-full' : '-translate-x-full';

    const setOpen = (open) => {
        sidebar.classList.toggle(hiddenClass, !open);
        scrim?.classList.toggle('hidden', !open);
        toggle.setAttribute('aria-expanded', String(open));
    };

    toggle.addEventListener('click', () =>
        setOpen(sidebar.classList.contains(hiddenClass))
    );
    scrim?.addEventListener('click', () => setOpen(false));

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') setOpen(false);
    });
}

/* ------------------------------------------------------------------ *
 * Dropdowns
 * ------------------------------------------------------------------ */
function initDropdowns() {
    const closeAll = (except) => {
        document.querySelectorAll('[data-dropdown]').forEach((dropdown) => {
            if (dropdown === except) return;
            dropdown.querySelector('[data-dropdown-panel]')?.classList.add('hidden');
            dropdown.querySelector('[data-dropdown-trigger]')?.setAttribute('aria-expanded', 'false');
        });
    };

    document.querySelectorAll('[data-dropdown]').forEach((dropdown) => {
        const trigger = dropdown.querySelector('[data-dropdown-trigger]');
        const panel = dropdown.querySelector('[data-dropdown-panel]');

        trigger?.addEventListener('click', (event) => {
            event.stopPropagation();
            const willOpen = panel.classList.contains('hidden');
            closeAll(dropdown);
            panel.classList.toggle('hidden', !willOpen);
            trigger.setAttribute('aria-expanded', String(willOpen));
        });
    });

    document.addEventListener('click', () => closeAll());
    document.addEventListener('keydown', (e) => e.key === 'Escape' && closeAll());
}

/* ------------------------------------------------------------------ *
 * Destructive-action confirmation
 * ------------------------------------------------------------------ */
function initConfirmations() {
    document.addEventListener('submit', (event) => {
        const form = event.target.closest('form[data-confirm]');
        if (!form) return;

        if (!window.confirm(form.dataset.confirm)) {
            event.preventDefault();
        }
    });
}

/* ------------------------------------------------------------------ *
 * Connectivity badge (PWA / offline POS)
 * ------------------------------------------------------------------ */
function initConnectionStatus() {
    const badge = document.getElementById('connection-status');
    if (!badge) return;

    /*
     * The text lives in its own span so writing it cannot destroy the glyph
     * beside it. `?? badge` keeps the function working against markup that has
     * no inner span, which is what the header used to ship.
     */
    const text = badge.querySelector('[data-badge-text]') ?? badge;
    const iconOnline = badge.querySelector('[data-icon-online]');
    const iconOffline = badge.querySelector('[data-icon-offline]');

    // Was hardcoded to 20 seconds while `config/pwa.php` carried a
    // `ping_interval` nothing read — an operator could set PWA_PING_INTERVAL and
    // watch it change nothing. A configuration value with no consumer is worse
    // than no value at all: it is a promise the code does not keep.
    const interval = Math.max(5, Number(pwaSettings().ping_interval) || 20) * 1000;

    // `null` rather than a boolean, so the first probe is always a transition and
    // the initial state is never mistaken for "was already online".
    let wasOnline = null;

    const render = (online) => {
        text.textContent = online
            ? badge.dataset.onlineLabel
            : badge.dataset.offlineLabel;
        badge.className = online ? 'badge-success' : 'badge-warning';

        // Both glyphs ship in the markup and one is hidden, rather than the
        // script rewriting an SVG path: the icon set stays the only place a path
        // is written, which is the rule the whole component exists to enforce.
        iconOnline?.classList.toggle('hidden', !online);
        iconOffline?.classList.toggle('hidden', online);

        /*
         * Announce the edge, not the state. This is the trigger the offline queue
         * drains on, and it is the trustworthy one: `navigator.onLine` reports
         * that a cable is plugged in, whereas reaching this line means the server
         * itself answered. Firing on every probe instead of on the transition
         * would restart the drain every interval for as long as the shop is
         * online, which is a request storm rather than a sync.
         */
        if (online && wasOnline === false) {
            document.dispatchEvent(new CustomEvent('souqly:online'));
        }

        wasOnline = online;
    };

    // navigator.onLine only reports link state, not reachability — probe the
    // server so a captive portal or dead uplink is reported honestly.
    const probe = async () => {
        if (!navigator.onLine) return render(false);

        try {
            const response = await fetch('/api/ping', { cache: 'no-store' });
            render(response.ok);
        } catch {
            render(false);
        }
    };

    window.addEventListener('online', probe);
    window.addEventListener('offline', () => render(false));

    probe();
    setInterval(probe, interval);
}

/* ------------------------------------------------------------------ *
 * Queued-sale badge
 *
 * The count of sales taken on this device that the server has not acknowledged.
 * It is in the header rather than only on the terminal because it is the one
 * number a shop must not have to go looking for: while it is above zero, the
 * takings exist on one machine and nowhere else.
 * ------------------------------------------------------------------ */
function initQueueBadge() {
    const badge = document.getElementById('queue-status');
    if (!badge) return;

    const text = badge.querySelector('[data-badge-text]') ?? badge;

    document.addEventListener('souqly:queue', (event) => {
        const pending = Number(event.detail?.pending ?? 0);

        text.textContent = badge.dataset.label.replace(':count', String(pending));
        badge.classList.toggle('hidden', pending < 1);
    });
}

/* ------------------------------------------------------------------ *
 * Numeric inputs: accept Arabic-Indic digits
 * ------------------------------------------------------------------ */
const ARABIC_DIGITS = /[٠-٩۰-۹]/g;

function normaliseArabicDigits(value) {
    return value.replace(ARABIC_DIGITS, (digit) => {
        const code = digit.charCodeAt(0);
        // ٠..٩ = U+0660..0669, ۰..۹ = U+06F0..06F9
        const base = code >= 0x06f0 ? 0x06f0 : 0x0660;
        return String(code - base);
    });
}

function initNumericInputs() {
    document.addEventListener('input', (event) => {
        const input = event.target;
        if (!input.matches?.('.input-numeric, [data-numeric]')) return;

        const normalised = normaliseArabicDigits(input.value);
        if (normalised !== input.value) {
            const caret = input.selectionStart;
            input.value = normalised;
            input.setSelectionRange?.(caret, caret);
        }
    });
}

/* ------------------------------------------------------------------ *
 * Live notifications (Pusher, when configured)
 * ------------------------------------------------------------------ */
function initNotifications() {
    const counter = document.getElementById('notification-count');
    if (!counter) return;

    const render = (count) => {
        counter.textContent = count > 99 ? '99+' : String(count);
        counter.classList.toggle('hidden', count < 1);
    };

    const refresh = async () => {
        try {
            const response = await request('/notifications/unread-count');
            if (!response.ok) return;
            const { count } = await response.json();
            render(Number(count) || 0);
        } catch {
            /* offline — leave the last known count in place */
        }
    };

    /*
     * The other interval that was hardcoded, and this one stays that way: it has
     * no configuration key and needs none. A minute is not a deployment concern —
     * nothing about a slow uplink makes a shop want its unread count sooner.
     */
    refresh();
    setInterval(refresh, 60000);
}

document.addEventListener('DOMContentLoaded', () => {
    initSidebar();
    initDropdowns();
    initConfirmations();
    initQueueBadge();
    /*
     * Before the connection badge, so the badge's first probe cannot dispatch
     * `souqly:online` before there is a listener for it. Ordering matters here in
     * a way it does not for the rest: this pair communicates by event.
     */
    initOffline();
    initConnectionStatus();
    initNumericInputs();
    initNotifications();
});

/*
 * The POS terminal's script is inlined in Blade — it is generated from
 * server-side values and is not a module, so it cannot `import`. This is the
 * bridge, and the only reason a global exists at all.
 */
window.Souqly = { request, normaliseArabicDigits, offline };

export { request, normaliseArabicDigits };
