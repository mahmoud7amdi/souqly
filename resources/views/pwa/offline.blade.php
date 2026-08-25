{{--
    The page a request degrades to when the network is unreachable.

    Served from the service worker's cache — which is what dictates almost every
    decision below.

    IT IS BILINGUAL, AND THAT IS NOT A LAPSE FROM DECISION #3.

    Every other screen resolves `app()->getLocale()` from the session at render
    time. This one is rendered once, cached, and served weeks later to a request
    that has no session and no server to ask. Whatever locale was active at the
    moment it was cached would be frozen into it, so an Arabic shop that happened
    to cache it under an English session would meet an English page at exactly the
    moment it could least afford to be puzzled. Two short blocks, Arabic first, is
    the only version that cannot be cached in the wrong language.

    The text is therefore literal rather than `__()`-resolved, for the same reason.

    IT CARRIES NOTHING OF THE TENANT'S.

    No layout, no `session()`, no business name, no queue count. A cached copy of
    this page is readable by whoever next picks up the till, so it is written to
    have nothing worth reading. The queue count belongs on the terminal, which
    stays usable while the uplink is down; this page is only what some *other* URL
    falls back to.

    THE STYLES ARE INLINE.

    `app.css` is precached, so it would usually be there — but "usually" is the
    wrong standard for the page whose entire job is the case where things are
    missing. A fallback that depends on another cached file has two ways to fail
    instead of one. The font link is the single exception: it is a progressive
    enhancement, and if it does not load the page renders in the system sans.
--}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>لا يوجد اتصال · No connection</title>

    {{ Vite::fonts('cairo') }}

    <style>
        :root {
            --brand: #00a76f;
            --canvas: #eef3f1;
            --ink: #1c252e;
            --muted: #637381;
            --line: #dfe3e8;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            font-family: 'Cairo', system-ui, -apple-system, 'Segoe UI', sans-serif;
            color: var(--ink);
            /* The same canvas gradient the application uses, written literally:
               this file cannot read a CSS custom property from app.css. */
            background: radial-gradient(120% 120% at 50% 0%, #f7faf9 0%, var(--canvas) 60%);
        }

        .card {
            width: 100%;
            max-width: 460px;
            padding: 40px 32px 32px;
            text-align: center;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 16px;
            /* Layered rather than a single blur, per the design system: a tight
               shadow for the edge and a wide one for the lift. */
            box-shadow: 0 1px 2px rgba(28, 37, 46, .06), 0 12px 32px rgba(28, 37, 46, .08);
        }

        .mark {
            width: 64px;
            height: 64px;
            margin: 0 auto 20px;
            display: block;
        }

        h1 { margin: 0 0 8px; font-size: 22px; font-weight: 700; letter-spacing: -.01em; }
        p { margin: 0; color: var(--muted); font-size: 14px; line-height: 1.7; }

        .en {
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid var(--line);
            direction: ltr;
            text-align: left;
        }

        .en h2 { margin: 0 0 6px; font-size: 16px; font-weight: 600; }

        .actions { margin-top: 28px; display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }

        .btn {
            appearance: none;
            border: 0;
            cursor: pointer;
            padding: 11px 20px;
            border-radius: 10px;
            font: inherit;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            background: var(--brand);
            color: #fff;
            box-shadow: 0 1px 2px rgba(0, 167, 111, .3), 0 6px 16px rgba(0, 167, 111, .24);
            transition: transform .15s ease, box-shadow .15s ease, background .15s ease;
        }

        .btn:hover { background: #009162; transform: translateY(-1px); box-shadow: 0 2px 4px rgba(0, 167, 111, .3), 0 10px 22px rgba(0, 167, 111, .28); }
        .btn:active { transform: translateY(0); }

        .btn--ghost {
            background: #fff;
            color: var(--ink);
            border: 1px solid var(--line);
            box-shadow: 0 1px 2px rgba(28, 37, 46, .05);
        }

        .btn--ghost:hover { background: #f4f6f8; box-shadow: 0 2px 6px rgba(28, 37, 46, .08); }

        /* The dot is the one piece of motion on the page, and it is doing a job:
           it says the browser is still watching for a connection rather than
           waiting for the person to do something. */
        .status {
            margin-top: 22px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: var(--muted);
        }

        .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #ff5630;
            animation: pulse 1.8s ease-in-out infinite;
        }

        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: .25; } }

        @media (prefers-reduced-motion: reduce) {
            .dot { animation: none; }
            .btn { transition: none; }
        }
    </style>
</head>
<body>
    <main class="card">
        {{-- Inlined rather than <img src="/img/icon.svg">: one fewer cached file
             this page depends on. Trimmed of the detail that only reads at 512px. --}}
        <svg class="mark" viewBox="0 0 512 512" aria-hidden="true">
            <rect width="512" height="512" rx="112" fill="#00a76f"/>
            <path d="M196 176v-26a60 60 0 0 1 120 0v26" fill="none" stroke="#fff"
                  stroke-width="26" stroke-linecap="round"/>
            <path d="M136 176h240a24 24 0 0 1 23.9 26.2l-18.6 168A48 48 0 0 1 333.6 414H178.4a48 48 0 0 1-47.7-43.8l-18.6-168A24 24 0 0 1 136 176z"
                  fill="#fff"/>
        </svg>

        <h1>لا يوجد اتصال بالإنترنت</h1>
        <p>
            هذه الصفحة غير متاحة دون اتصال. المبيعات التي سُجّلت على هذا الجهاز
            محفوظة ولم تُفقد، وسيتم إرسالها تلقائيًا عند عودة الاتصال.
        </p>

        <div class="actions">
            <button type="button" class="btn" onclick="location.reload()">إعادة المحاولة</button>
            <a class="btn btn--ghost" href="/pos">نقطة البيع</a>
        </div>

        <div class="status"><span class="dot"></span><span>في انتظار الاتصال… · waiting for a connection…</span></div>

        <div class="en">
            <h2>You are offline</h2>
            <p>
                This page needs a connection. Sales already taken on this device are
                saved and will be sent on their own once the network is back — open
                the point of sale to keep selling.
            </p>
        </div>
    </main>

    <script>
        /*
         * Reload when the link comes back, so the shop does not have to notice.
         *
         * `online` is the right trigger *here* even though the application's
         * connection badge distrusts it: this page has nothing to lose to a false
         * positive. If the link is up but the server is still unreachable, the
         * reload simply lands back on this page. Elsewhere a false "online" would
         * tell a cashier their queue was draining when it was not.
         */
        window.addEventListener('online', () => location.reload());

        // Same reason: coming back to the tab is a good moment to try again.
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible' && navigator.onLine) location.reload();
        });
    </script>
</body>
</html>
