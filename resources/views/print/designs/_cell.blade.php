{{--
    One product-table cell. Extracted because two designs and a receipt render the
    same cells, and because one column is not a string.

    Every row value from `PrintService::rows()` is text — except `image`, which is
    a URL on the browser path and a filesystem path on the PDF one. Rather than
    have the service emit markup (which the thermal payload would then have to
    strip back out), the one non-text column is handled here, in the one place
    that knows it is rendering HTML.
--}}
@if ($column['key'] === 'image')
    @if (filled($row['image'] ?? null))
        <img src="{{ $row['image'] }}" alt="" class="thumb">
    @endif
@elseif ($column['key'] === 'product')
    {{-- The product cell carries its own line breaks: the name, then the sale
         description when the layout asks for one. `nl2br` on an escaped string,
         not `{!! !!}` — a product name is tenant data and a sale note is typed at
         the counter, so neither may reach the page as markup. --}}
    {!! nl2br(e($row['product'] ?? '')) !!}
@else
    {{ $row[$column['key']] ?? '' }}
@endif
