{{--
    Elegant — the same facts, arranged for a business that wants its paperwork to
    look designed rather than filed.

    Structurally different from classic, not a recolour, which is what the
    `design` enum promises:

    - the heading is large and sits on the start edge, with the document facts
      stacked beside it, instead of centred over the page;
    - the accent colour is a solid band behind that strip, so the top of the page
      has weight without a border;
    - the seller identity is small and quiet at the end edge — a business printing
      on its own stationery does not need its own name at 17px;
    - the item table is borderless, separated by hairlines, with letter-spaced
      column labels and no shaded header;
    - the totals sit in a tinted panel rather than hanging off a rule.

    That last group is where the design directive's "visual depth" lands on paper.
    Shadows are stripped on print for good reason (`app.css:1963`), so depth here
    is tint and spacing: the band, the panel, and generous vertical rhythm.
--}}

@if ($letterHead)
    <img src="{{ $letterHead }}" alt="" class="letter-head">
@endif

{{-- The accent band. A table cell with a background rather than a div, because
     DomPDF paints a table cell's background reliably and a floated div's not at
     all. --}}
<table class="grid" style="margin-bottom:18px">
    <tr>
        <td style="background:{{ $accent }}; padding:14px 16px; width:60%">
            <div style="color:#ffffff; font-size:22px; font-weight:700; letter-spacing:.02em">
                {{ $heading }}
            </div>

            @if ($headerText)
                <div style="color:#ffffff; opacity:.85" class="small">{{ $headerText }}</div>
            @endif
        </td>
        <td style="background:{{ $accent }}; padding:14px 16px; width:40%">
            <table class="grid">
                @foreach ($meta as $fact)
                    <tr>
                        <td class="tiny" style="color:#ffffff; opacity:.8; padding:1px 0; width:45%">
                            {{ $fact['label'] }}
                        </td>
                        <td class="small" style="color:#ffffff; font-weight:700; padding:1px 0">
                            {{ $fact['value'] }}
                        </td>
                    </tr>
                @endforeach
            </table>
        </td>
    </tr>
</table>

<table class="grid" style="margin-bottom:16px">
    <tr>
        <td style="width:55%; padding-inline-end:12px">
            <div class="block-label">{{ $client['label'] }}</div>
            <div class="strong" style="font-size:13px">{{ or_dash($client['name']) }}</div>

            @foreach ($client['lines'] as $line)
                <div class="small muted">{{ $line }}</div>
            @endforeach

            @if ($client['id'])
                <div class="small"><span class="muted">{{ $client['idLabel'] }}:</span>
                    {{ $client['id'] }}</div>
            @endif

            @if ($client['tax'])
                <div class="small"><span class="muted">{{ $client['taxLabel'] }}:</span>
                    {{ $client['tax'] }}</div>
            @endif
        </td>
        <td class="end" style="width:45%">
            @if ($logo)
                <img src="{{ $logo }}" alt="" class="logo" style="margin-bottom:4px">
            @endif

            <div class="strong">{{ $seller['name'] }}</div>

            @foreach ($seller['lines'] as $line)
                <div class="tiny muted">{{ $line }}</div>
            @endforeach

            @foreach ($seller['taxes'] as $tax)
                <div class="tiny"><span class="muted">{{ $tax['label'] }}:</span>
                    <span class="strong">{{ $tax['value'] }}</span></div>
            @endforeach
        </td>
    </tr>
</table>

<table class="items">
    <thead>
        <tr>
            @foreach ($columns as $column)
                <th class="{{ $column['align'] === 'end' ? 'num' : 'start' }}"
                    style="border-bottom:2px solid {{ $accent }}; padding-bottom:6px">
                    {{ $column['label'] }}
                </th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @forelse ($rows as $row)
            <tr>
                @foreach ($columns as $column)
                    <td class="{{ $column['align'] === 'end' ? 'num' : 'start' }}"
                        style="border-bottom:1px solid #eef2f1; padding-top:9px; padding-bottom:9px">
                        @include('print.designs._cell', ['column' => $column, 'row' => $row])
                    </td>
                @endforeach
            </tr>
        @empty
            <tr>
                <td colspan="{{ count($columns) }}" class="center muted" style="padding:18px">
                    {{ __('lang_v1.no_records_found') }}
                </td>
            </tr>
        @endforelse
    </tbody>
</table>

{{-- The totals panel. Nested tables: the outer one pushes the panel to the end
     edge (46% + `margin-inline-start:auto`), the inner one is the ladder. One
     table cannot do both, because a background on a `<table>` element is not
     something DomPDF paints. --}}
<table class="totals" style="margin-top:16px">
    <tr>
        <td style="background:#f6f9f8; padding:10px 12px">
            <table class="grid">
                @foreach ($totals as $total)
                    <tr>
                        <td class="{{ $total['strong'] ? 'strong' : 'muted' }}"
                            style="padding:3px 0; {{ $total['strong'] ? 'font-size:13px;' : '' }}">
                            {{ $total['label'] }}
                        </td>
                        <td class="num {{ $total['strong'] ? 'strong' : '' }}"
                            style="padding:3px 0; {{ $total['strong'] ? 'font-size:13px;' : '' }}">
                            {{ $total['value'] }}
                        </td>
                    </tr>
                @endforeach
            </table>
        </td>
    </tr>
</table>

@include('print.designs._appendix')
