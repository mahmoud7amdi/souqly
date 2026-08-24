{{--
    The on-screen toolbar. Never printed.

    A print view is also how somebody *reads* an invoice — the sell screen links
    here, and a clerk checking what a customer was charged lands on this page. So
    the things they might want next are on it: print this, save it as a PDF, print
    the till receipt instead, or push it to the counter's thermal printer.
    `target="_blank"` on the receipt because it is a different paper size: swapping
    the current tab to a 72 mm document loses the A4 view the clerk was looking at.

    Expects `$backUrl` and, optionally, `$showReceipt`, `$canEnqueue` and
    `$sheetWidth` — all supplied by `PrintController::chrome()`.
--}}
<div class="no-print toolbar">
    <button type="button" onclick="window.print()" class="tb-primary">
        {{ __('lang_v1.print') }}
    </button>

    <a href="{{ route('print.pdf', $document->id) }}" class="tb-button">
        {{ __('lang_v1.download_pdf') }}
    </a>

    @if ($showReceipt ?? true)
        <a href="{{ route('print.receipt', $document->id) }}" target="_blank" rel="noopener"
           class="tb-button">
            {{ __('lang_v1.thermal_receipt') }}
        </a>
    @endif

    @if ($canEnqueue ?? false)
        {{-- A POST, because it makes the printer move. Shown only when the branch
             is actually configured for an ESC/POS agent — `PrintService::enqueue()`
             refuses with a translated reason otherwise, but a button whose only
             purpose is to explain why it cannot work is not a button. --}}
        <form method="POST" action="{{ route('print.enqueue', $document->id) }}" class="tb-form">
            @csrf
            <button type="submit" class="tb-button">{{ __('lang_v1.send_to_printer') }}</button>
        </form>
    @endif

    {{-- The way back. A print page has no sidebar and no app header, so without
         this the only exit is the browser's back button. --}}
    <a href="{{ $backUrl }}" class="tb-button tb-end">{{ __('lang_v1.back') }}</a>
</div>

<style>
    /* Matches the width of the document underneath it, so the toolbar and the
       page it acts on share an edge — 210 mm above an invoice, 72 mm above a
       receipt. */
    .toolbar {
        max-width: {{ $sheetWidth ?? '210mm' }};
        margin: 0 auto 16px;
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
    }

    .toolbar .tb-form { display: inline-flex; margin: 0; }

    .toolbar .tb-button,
    .toolbar .tb-primary {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        height: 36px;
        padding: 0 14px;
        border-radius: 8px;
        border: 1px solid #c2cfcb;
        background: #ffffff;
        color: #0f172a;
        font-size: 13px;
        font-weight: 600;
        text-decoration: none;
        cursor: pointer;
        transition: background .15s ease, transform .15s ease, box-shadow .15s ease;
    }

    .toolbar .tb-primary {
        background: #007867;
        border-color: #007867;
        color: #ffffff;
    }

    /* Micro-interaction, per the design directive: the control acknowledges the
       pointer before it is clicked. Kept to 1px so it reads as a lift rather than
       a jump. */
    .toolbar .tb-button:hover { background: #eef3f1; }
    .toolbar .tb-primary:hover { background: #00655a; }
    .toolbar .tb-button:hover,
    .toolbar .tb-primary:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px -6px rgba(15, 23, 42, .35);
    }

    .toolbar .tb-button:focus-visible,
    .toolbar .tb-primary:focus-visible {
        outline: 2px solid #007867;
        outline-offset: 2px;
    }

    /* `margin-inline-start: auto` rather than `margin-left`: in Arabic the exit
       belongs on the left edge, which is the *end* of the bar. */
    .toolbar .tb-end { margin-inline-start: auto; }
</style>
