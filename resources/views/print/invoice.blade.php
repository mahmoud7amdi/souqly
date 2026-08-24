{{--
    A printable sale document: invoice, quotation, sales order or credit note.

    Standalone on purpose — its own <html>, its own styles, no app layout. Three
    reasons, in order of how much they cost to get wrong:

    1. The same file is rendered by DomPDF, which has no stylesheet, no
       JavaScript, no flexbox and no grid. A template that extended
       `layouts.app` would produce a PDF of the sidebar.
    2. An invoice is a document, not a screen. The app's chrome — search box,
       notification bell, breadcrumb — is not "hidden on print"; it has no
       business being in the markup of something a customer keeps.
    3. `@page` geometry has to be declared by the document itself, and a shared
       layout cannot declare a different page size per child.

    Everything the templates render is resolved in `PrintService::present()`:
    columns as a list, rows keyed by those columns, totals as label/value pairs.
    So a design file is a layout and nothing else — no `?:` fallbacks, no
    `@if ($layout->show_…)` in a `<thead>` that a `<tbody>` twenty lines down has
    to remember to repeat.
--}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}"
      dir="{{ in_array(app()->getLocale(), config('constants.langs_rtl', []), true) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $heading }} — {{ $document->invoice_no }}</title>

    {{-- Only the Cairo @font-face sheet, not the design system: `app.css` would
         paint the app canvas onto an invoice. Self-hosted, so an Arabic invoice
         still shapes correctly when the shop's internet is down. Skipped on the
         PDF path, where DomPDF cannot load a web font anyway. --}}
    @unless ($forPdf)
        {{ Vite::fonts('cairo') }}
    @endunless

    @include('print._styles')
</head>
<body>

@unless ($forPdf)
    @include('print._toolbar')
@endunless

<div class="sheet">
    @include('print.designs.'.$design)
</div>

@if ($autoPrint ?? false)
    {{-- Opt-in via `?auto=1`, so the flows that *send* somebody here to print
         (the POS banner, the sell screen's print button) get the dialog, and the
         flows that send somebody here to look do not. `onload` rather than an
         inline call: the barcode and the logo are images, and printing before
         they decode produces a receipt with gaps where they should be. --}}
    <script>
        window.addEventListener('load', function () { window.print(); });
    </script>
@endif

</body>
</html>
