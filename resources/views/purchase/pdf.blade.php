<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ in_array(app()->getLocale(), config('constants.langs_rtl', []), true) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} — {{ $document->ref_no }}</title>
    <style>
        /* DomPDF needs a Unicode font for Arabic; DejaVu ships with it and
           covers Arabic script. Direction is set on <html> above.

           Colours are literal rather than utility classes because DomPDF never
           sees the compiled stylesheet. They are the design-system v2.1 values
           by hand: brand-700 for the rule under the letterhead (deeper than
           brand-500, which is thin on paper), and the slate ramp for the greys. */
        * { font-family: 'DejaVu Sans', sans-serif; }
        body { font-size: 11px; color: #111; margin: 0; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        .muted { color: #666; }
        .header { border-bottom: 2px solid #007867; padding-bottom: 8px; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background: #eef3f1; text-align: start; padding: 6px; font-size: 10px;
             text-transform: uppercase; border-bottom: 1px solid #c2cfcb; }
        td { padding: 6px; border-bottom: 1px solid #dee6e3; }
        .num { text-align: end; direction: ltr; }
        .totals { width: 45%; margin-top: 12px; margin-inline-start: auto; }
        .totals td { border: 0; padding: 3px 6px; }
        .grand { font-weight: bold; border-top: 1px solid #7b8d88; }
    </style>
</head>
<body>

<div class="header">
    <h1>{{ session('business.name') }}</h1>
    <div class="muted">
        {{ $document->location->name ?? '' }}
        @if ($document->location?->mobile) &middot; {{ $document->location->mobile }} @endif
    </div>
</div>

<table style="margin:0">
    <tr>
        <td style="border:0; width:50%">
            <strong>{{ $title }}</strong><br>
            {{ __('lang_v1.reference_no') }}: {{ $document->ref_no }}<br>
            {{ __('lang_v1.date') }}: {{ app(\App\Services\FormattingService::class)->formatDate($document->transaction_date) }}
        </td>
        <td style="border:0; width:50%">
            <strong>{{ __('lang_v1.supplier') }}</strong><br>
            {{ $document->contact->full_name_with_business ?? '' }}<br>
            {{ $document->contact->mobile ?? '' }}
            @if ($document->contact?->tax_number)
                <br>{{ __('lang_v1.tax_number') }}: {{ $document->contact->tax_number }}
            @endif
        </td>
    </tr>
</table>

<table>
    <thead>
        <tr>
            <th>#</th>
            <th>{{ __('lang_v1.product') }}</th>
            <th class="num">{{ __('lang_v1.quantity') }}</th>
            <th class="num">{{ __('lang_v1.unit_cost') }}</th>
            <th class="num">{{ __('lang_v1.subtotal') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($document->purchase_lines as $index => $line)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>
                    {{ $line->variations->full_name }}<br>
                    <span class="muted">{{ $line->variations->sub_sku }}</span>
                </td>
                <td class="num">{{ app(\App\Services\FormattingService::class)->quantity($line->quantity) }}</td>
                <td class="num">{{ app(\App\Services\FormattingService::class)->currencyF($line->purchase_price_inc_tax) }}</td>
                <td class="num">{{ app(\App\Services\FormattingService::class)->currencyF($line->quantity * $line->purchase_price_inc_tax) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="totals">
    <tr>
        <td>{{ __('lang_v1.subtotal') }}</td>
        <td class="num">{{ app(\App\Services\FormattingService::class)->currencyF($document->total_before_tax) }}</td>
    </tr>
    @if ($document->discount_amount > 0)
        <tr>
            <td>{{ __('lang_v1.discount') }}</td>
            <td class="num">{{ app(\App\Services\FormattingService::class)->currencyF($document->discount_amount) }}</td>
        </tr>
    @endif
    @if ($document->tax_amount > 0)
        <tr>
            <td>{{ __('lang_v1.tax') }}</td>
            <td class="num">{{ app(\App\Services\FormattingService::class)->currencyF($document->tax_amount) }}</td>
        </tr>
    @endif
    @if ($document->shipping_charges > 0)
        <tr>
            <td>{{ __('lang_v1.shipping_charges') }}</td>
            <td class="num">{{ app(\App\Services\FormattingService::class)->currencyF($document->shipping_charges) }}</td>
        </tr>
    @endif
    <tr class="grand">
        <td>{{ __('lang_v1.total') }}</td>
        <td class="num">{{ app(\App\Services\FormattingService::class)->currencyF($document->final_total) }}</td>
    </tr>
</table>

@if ($document->additional_notes)
    <p style="margin-top:16px" class="muted">{{ $document->additional_notes }}</p>
@endif

</body>
</html>
