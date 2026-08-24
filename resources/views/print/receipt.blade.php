{{--
    The till receipt: 72 mm of paper, no colour, no images beyond the barcode.

    A separate template rather than the invoice with a narrower `@page`, and its
    own style block rather than `print/_styles`, because almost nothing in the A4
    sheet survives the width. At 42 characters a line there is no two-column
    header, no totals panel pushed to 46%, no product thumbnail and no per-line tax
    column — those do not shrink, they wrap into noise. What is left is one column
    of centred identity, one stacked list of lines, and a totals ladder. Sharing a
    stylesheet between the two would mean every rule carrying a media query about
    which document it is for.

    It renders the same `PrintService::present()` payload as the invoice, so a
    receipt and an invoice for the same sale always agree on the figures — they
    only disagree on how much of the document there is room to print.

    This is also the browser fallback for `receipt_printer_type = 'browser'`,
    which is the default for a new location: the ESC/POS agent path
    ({@see \App\Http\Controllers\PrintController::enqueue()}) needs an agent
    installed, and until one is, a clerk prints this to the roll printer through
    the browser's own dialog.
--}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}"
      dir="{{ in_array(app()->getLocale(), config('constants.langs_rtl', []), true) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $heading }} — {{ $document->invoice_no }}</title>

    {{ Vite::fonts('cairo') }}

    <style>
        /* `@page` width is the roll width; the margin is the printer's own
           unprintable edge plus a hair. A 10 mm margin here — the value
           `app.css:1998` already uses for print — would eat a fifth of the paper. */
        @page { size: 72mm auto; margin: 2mm; }

        * { font-family: 'Cairo', 'DejaVu Sans', sans-serif; }

        html, body { margin: 0; padding: 0; }

        body {
            font-size: 11px;
            line-height: 1.4;
            color: #000000;
            background: #ffffff;
        }

        /* On screen the roll is shown at its real width on the app canvas, so a
           clerk can see the wrap before spending paper on it. `.receipt-thermal`
           is the class name `app.css:1990` already reserves for exactly this
           width, kept identical so the two files do not name the same idea twice. */
        @media screen {
            body { background: #f1f5f4; padding: 24px 12px; }
            .receipt-thermal {
                width: 72mm;
                margin: 0 auto;
                padding: 6mm 4mm;
                background: #ffffff;
                border-radius: 8px;
                box-shadow: 0 1px 2px rgba(15, 23, 42, .06), 0 12px 32px -12px rgba(15, 23, 42, .18);
            }
        }

        @media print {
            .receipt-thermal { width: auto; margin: 0; padding: 0; }
            .no-print { display: none !important; }
        }

        h1 { margin: 0; font-size: 13px; font-weight: 700; }

        .center { text-align: center; }
        .strong { font-weight: 700; }
        .small { font-size: 10px; }
        .tiny { font-size: 9px; }

        /* Same reasoning as the A4 sheet: a figure reads left-to-right even on an
           Arabic receipt, so the digits get `direction: ltr` while the column
           stays on the correct edge. */
        .num { text-align: end; direction: ltr; white-space: nowrap; }

        .hair { border: 0; border-top: 1px dashed #000000; margin: 6px 0; }

        table { width: 100%; border-collapse: collapse; }
        table td { padding: 1px 0; vertical-align: top; }

        /* The line block: name on its own row across the full width, then the
           arithmetic underneath it. A product name is the one field that will not
           fit beside anything, and truncating it is worse than a second row —
           "Coca Cola 1L Pack…" is not something a customer can check. */
        .line { page-break-inside: avoid; }
        .line + .line { margin-top: 4px; }
        .line-name { font-weight: 600; }
        .line-calc { color: #000000; }

        .totals td { padding: 1px 0; }
        .totals tr.emphasis td {
            font-weight: 700;
            font-size: 12px;
            border-top: 1px solid #000000;
            padding-top: 3px;
        }

        .barcode { height: 34px; max-width: 100%; }
    </style>
</head>
<body>

@unless ($forPdf)
    @include('print._toolbar', ['showReceipt' => false, 'sheetWidth' => '72mm'])
@endunless

<div class="receipt-thermal">

    <div class="center">
        @if ($logo)
            <img src="{{ $logo }}" alt="" style="max-height:40px; max-width:56mm">
        @endif

        <h1>{{ $seller['name'] }}</h1>

        @foreach ($seller['lines'] as $line)
            <div class="tiny">{{ $line }}</div>
        @endforeach

        @foreach ($seller['taxes'] as $tax)
            <div class="tiny">{{ $tax['label'] }}: {{ $tax['value'] }}</div>
        @endforeach
    </div>

    <hr class="hair">

    <div class="center strong" style="letter-spacing:.04em; text-transform:uppercase">{{ $heading }}</div>

    <table class="small" style="margin-top:4px">
        @foreach ($meta as $fact)
            <tr>
                <td>{{ $fact['label'] }}</td>
                <td class="num">{{ $fact['value'] }}</td>
            </tr>
        @endforeach

        @if (filled($client['name']))
            <tr>
                <td>{{ $client['label'] }}</td>
                <td class="num" style="direction:inherit">{{ $client['name'] }}</td>
            </tr>
        @endif
    </table>

    <hr class="hair">

    {{-- The lines. `$columns` is not looped here: the receipt prints a fixed
         reduced set whatever the layout asks for, because a 42-character line
         cannot hold a brand, a category code and a lot number as columns. The
         values still come from the same `$rows` the invoice uses, so the two
         cannot disagree about a quantity. --}}
    @forelse ($rows as $row)
        <div class="line">
            <div class="line-name">{{ $row['product'] }}</div>
            <table>
                <tr>
                    <td class="line-calc small">
                        <span style="direction:ltr; display:inline-block">
                            {{ $row['quantity'] }} × {{ $row['unit_price'] }}
                        </span>
                    </td>
                    <td class="num strong">{{ $row['subtotal'] }}</td>
                </tr>
            </table>
        </div>
    @empty
        <div class="center small">{{ __('lang_v1.no_records_found') }}</div>
    @endforelse

    <hr class="hair">

    <table class="totals">
        @foreach ($totals as $total)
            <tr class="{{ $total['strong'] ? 'emphasis' : '' }}">
                <td>{{ $total['label'] }}</td>
                <td class="num">{{ $total['value'] }}</td>
            </tr>
        @endforeach
    </table>

    @if (! empty($paymentLines))
        <hr class="hair">
        <table class="small">
            @foreach ($paymentLines as $payment)
                <tr>
                    <td>{{ $payment['method'] }}</td>
                    <td class="num">{{ $payment['amount'] }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if (! empty($qrFields))
        <hr class="hair">
        @foreach ($qrFields as $field)
            <div class="tiny">{{ $field['label'] }}: {{ $field['value'] }}</div>
        @endforeach
    @endif

    @if (filled($notes))
        <hr class="hair">
        <div class="small">{!! nl2br(e($notes)) !!}</div>
    @endif

    @if (filled($footer))
        <hr class="hair">
        {{-- Escaped, then line-broken. Tenant text, same as on the invoice. --}}
        <div class="tiny center">{!! nl2br(e($footer)) !!}</div>
    @endif

    @if ($barcode)
        <div class="center" style="margin-top:8px">
            <img src="{{ $barcode }}" alt="{{ $document->invoice_no }}" class="barcode">
        </div>
    @endif

</div>

@if ($autoPrint ?? false)
    <script>
        window.addEventListener('load', function () { window.print(); });
    </script>
@endif

</body>
</html>
