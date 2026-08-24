{{--
    Classic — the conventional invoice, and the default for a reason: it is the
    shape an accountant, a customs officer and a customer's filing system all
    already recognise. Centred letterhead, a rule, seller and buyer side by side,
    a bordered table with a shaded header, totals bottom-end.

    Its hierarchy is carried by borders and a tinted header row rather than by
    whitespace, which is what makes it survive being photocopied, faxed, or
    printed on a tired laser at 60% toner.
--}}

@if ($letterHead)
    {{-- A letterhead image replaces the typeset header entirely: a business that
         uploaded pre-printed stationery has already made these decisions. --}}
    <img src="{{ $letterHead }}" alt="" class="letter-head">
@else
    <table class="grid" style="margin-bottom:8px">
        <tr>
            @if ($logo)
                <td style="width:22%">
                    <img src="{{ $logo }}" alt="" class="logo">
                </td>
            @endif
            <td class="{{ $logo ? 'start' : 'center' }}">
                <h1 style="font-size:17px">{{ $seller['name'] }}</h1>

                @foreach ($seller['lines'] as $line)
                    <div class="small muted">{{ $line }}</div>
                @endforeach

                @foreach ($seller['taxes'] as $tax)
                    <div class="small"><span class="muted">{{ $tax['label'] }}:</span>
                        <span class="strong">{{ $tax['value'] }}</span></div>
                @endforeach
            </td>
        </tr>
    </table>
@endif

<hr class="rule">

@if ($headerText)
    <p class="small muted center" style="margin:8px 0 0">{{ $headerText }}</p>
@endif

<h2 class="center" style="font-size:15px; margin:12px 0 10px; letter-spacing:.04em;
    text-transform:uppercase">{{ $heading }}</h2>

{{-- Buyer and document facts, side by side. A table because DomPDF has no
     flexbox — see `print/_styles.blade.php`. --}}
<table class="grid">
    <tr>
        <td style="width:52%; padding-inline-end:10px">
            <div class="block-label">{{ $client['label'] }}</div>
            <div class="strong">{{ or_dash($client['name']) }}</div>

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
        <td style="width:48%">
            <table class="grid">
                @foreach ($meta as $fact)
                    <tr>
                        <td class="small muted" style="width:42%; padding:2px 0">{{ $fact['label'] }}</td>
                        <td class="small strong" style="padding:2px 0">{{ $fact['value'] }}</td>
                    </tr>
                @endforeach
            </table>
        </td>
    </tr>
</table>

<table class="items" style="border:1px solid #c2cfcb">
    <thead>
        <tr>
            @foreach ($columns as $column)
                <th class="{{ $column['align'] === 'end' ? 'num' : 'start' }}"
                    style="background:#eef3f1; border-bottom:1px solid #c2cfcb">
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
                        style="border-bottom:1px solid #dee6e3">
                        @include('print.designs._cell', ['column' => $column, 'row' => $row])
                    </td>
                @endforeach
            </tr>
        @empty
            {{-- A document with no lines is a data problem, not a layout one, but
                 it must still print as a document rather than as a table with a
                 missing body. --}}
            <tr>
                <td colspan="{{ count($columns) }}" class="center muted" style="padding:18px">
                    {{ __('lang_v1.no_records_found') }}
                </td>
            </tr>
        @endforelse
    </tbody>
</table>

<table class="totals">
    @foreach ($totals as $total)
        <tr class="{{ $total['strong'] ? 'emphasis' : '' }}">
            <td>{{ $total['label'] }}</td>
            <td class="num">{{ $total['value'] }}</td>
        </tr>
    @endforeach
</table>

@include('print.designs._appendix')
