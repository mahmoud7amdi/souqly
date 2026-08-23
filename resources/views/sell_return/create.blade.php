@extends('layouts.app')
@section('title', __('lang_v1.add_return'))
@section('page_title', __('lang_v1.add_return').' — '.$sale->invoice_no)

@section('content')

<x-page-head :back="route('sells.show', $sale->id)" :backLabel="$sale->invoice_no">
    <x-slot:subtitle>
        <span class="font-medium text-slate-700">
            {{ or_dash($sale->contact->full_name_with_business ?? null) }}
        </span>
        <span class="text-slate-300">&middot;</span>
        <span class="force-ltr">{{ $sale->invoice_no }}</span>
        <span class="text-slate-300">&middot;</span>
        @format_date($sale->transaction_date)
    </x-slot:subtitle>
</x-page-head>

@if ($existingReturn)
    {{-- A sale carries at most one return document; a second visit to this screen
         adds to it. Said up front, because otherwise the quantities on the next
         screen look like they double-counted. --}}
    <div class="alert-warning mb-6">
        <span>{{ __('lang_v1.return_already_exists', ['ref' => $existingReturn->invoice_no]) }}</span>
    </div>
@endif

<form method="POST" action="{{ route('sell-return.store', $sale->id) }}">
    @csrf

    <x-panel :title="__('lang_v1.items')" icon="box" flush>
        {{-- The date qualifies the whole return, so it sits with the table rather
             than in a panel of its own above three columns of empty space. --}}
        <x-slot:actions>
            <label for="transaction_date" class="label mb-0">{{ __('lang_v1.return_date') }}</label>
            <input type="date" id="transaction_date" name="transaction_date" class="input w-44"
                   value="{{ old('transaction_date', now()->toDateString()) }}">
        </x-slot:actions>

        <div class="table-wrap table-flush">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ __('lang_v1.product') }}</th>
                        <th class="th-numeric">{{ __('lang_v1.sold') }}</th>
                        <th class="th-numeric">{{ __('lang_v1.already_returned') }}</th>
                        <th class="th-numeric">{{ __('lang_v1.returnable') }}</th>
                        {{-- What each unit gives back. Unlike a purchase return, this
                             is money handed across a counter, so the person doing it
                             should see the rate before typing a quantity. --}}
                        <th class="th-numeric">{{ __('lang_v1.unit_price') }}</th>
                        <th class="th-numeric w-32">{{ __('lang_v1.return_quantity') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lines as $index => $row)
                        @php $exhausted = $row['returnable'] <= 0; @endphp

                        {{-- A line with nothing left to bring back keeps its row — the
                             quantities are the evidence — but is toned down so the
                             rows that can still be acted on are the ones that read. --}}
                        <tr @class(['opacity-60' => $exhausted])>
                            <td>
                                <input type="hidden" name="lines[{{ $index }}][sell_line_id]"
                                       value="{{ $row['line']->id }}">
                                <span class="cell-primary">{{ $row['line']->variations->full_name }}</span>
                                <span class="cell-meta force-ltr">{{ $row['line']->variations->sub_sku }}</span>
                            </td>

                            <td class="cell-numeric">@format_quantity($row['line']->quantity)</td>
                            <td class="cell-numeric">@format_quantity($row['already_returned'])</td>
                            <td class="cell-numeric font-semibold">@format_quantity($row['returnable'])</td>
                            <td class="cell-numeric">@format_currency($row['line']->unit_price_inc_tax)</td>

                            <td>
                                {{-- max is what has not already come back: returning more
                                     than was sold would put stock on the shelf that never
                                     left it. min stops a negative quantity, which would
                                     read as a second sale. --}}
                                <input name="lines[{{ $index }}][quantity]" class="input-numeric w-28"
                                       inputmode="decimal" value="0"
                                       type="number" min="0" step="any"
                                       max="{{ $row['returnable'] }}"
                                       aria-label="{{ __('lang_v1.return_quantity') }}"
                                       @disabled($exhausted)>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-panel>

    <div class="form-actions">
        <span class="form-actions-spacer">{{ __('lang_v1.returnable_sell_hint') }}</span>
        <a href="{{ route('sells.show', $sale->id) }}" class="btn-secondary">
            {{ __('lang_v1.cancel') }}
        </a>
        <button type="submit" class="btn-primary">
            <x-nav-icon name="undo"/>
            {{ __('lang_v1.save') }}
        </button>
    </div>
</form>
@endsection
