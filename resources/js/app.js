/**
 * Souqly front-end runtime.
 *
 * Deliberately dependency-free: the UI needs a sidebar, dropdowns, confirm
 * dialogs, a connectivity badge and a live-notification hook. Everything else
 * is server-rendered Blade, so a framework would be dead weight.
 */

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

    const render = (online) => {
        badge.textContent = online
            ? badge.dataset.onlineLabel
            : badge.dataset.offlineLabel;
        badge.className = online ? 'badge-success' : 'badge-warning';
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
    setInterval(probe, 20000);
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

    refresh();
    setInterval(refresh, 60000);
}

document.addEventListener('DOMContentLoaded', () => {
    initSidebar();
    initDropdowns();
    initConfirmations();
    initConnectionStatus();
    initNumericInputs();
    initNotifications();
});

export { request, normaliseArabicDigits };
