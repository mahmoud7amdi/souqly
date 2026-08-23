<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ in_array(app()->getLocale(), config('constants.langs_rtl', []), true) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('lang_v1.print_labels') }}</title>

    {{-- Only Cairo's @font-face sheet, not the whole design system: this page
         carries its own print geometry and app.css would paint the grey app
         canvas onto a sticker sheet. Self-hosted, so labels still print with
         correct Arabic shaping when the shop is offline. --}}
    {{ Vite::fonts('cairo') }}
    <style>
        /* Sheet geometry comes from the selected barcode setting, so the
           stickers land on the physical label positions. */
        @page { margin: {{ $sheet->top_margin ?? 0 }}mm {{ $sheet->left_margin ?? 0 }}mm; }

        body {
            margin: 0;
            font-family: 'Cairo', sans-serif;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .sheet {
            display: flex;
            flex-wrap: wrap;
            gap: {{ $sheet->row_distance ?? 0 }}mm {{ $sheet->col_distance ?? 0 }}mm;
        }

        .label {
            width: {{ $sheet->width ?? 40 }}mm;
            height: {{ $sheet->height ?? 25 }}mm;
            box-sizing: border-box;
            padding: 1mm;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            overflow: hidden;
            page-break-inside: avoid;
        }

        .label .business { font-size: 6pt; color: #444; }
        .label .name     { font-size: 7pt; font-weight: 600; line-height: 1.15;
                           max-height: 2.4em; overflow: hidden; }
        .label .price    { font-size: 9pt; font-weight: 700; direction: ltr; }
        .label .sku      { font-size: 6pt; color: #555; direction: ltr; }
        .label svg       { width: 100%; height: 8mm; }

        .toolbar { padding: 12px; background: #eef3f1; text-align: center; }
        @media print { .toolbar { display: none; } }
    </style>
</head>
<body>

<div class="toolbar">
    <button type="button" onclick="window.print()">{{ __('lang_v1.print') }}</button>
    <span>{{ trans_choice('lang_v1.label_count', count($labels), ['count' => count($labels)]) }}</span>
</div>

<div class="sheet">
    @foreach ($labels as $label)
        <div class="label">
            @if ($showBusinessName)
                <div class="business">{{ session('business.name') }}</div>
            @endif

            @if ($showName)
                <div class="name">{{ $label['name'] }}</div>
            @endif

            {!! $label['barcode_svg'] !!}

            <div class="sku">{{ $label['sku'] }}</div>

            @if ($showPrice)
                <div class="price">@format_currency($label['price'])</div>
            @endif
        </div>
    @endforeach
</div>

</body>
</html>
