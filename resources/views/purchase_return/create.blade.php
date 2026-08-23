@extends('layouts.app')
@section('title', __('lang_v1.add_return'))
@section('page_title', __('lang_v1.add_return').' — '.$purchase->ref_no)

@section('content')

@php
    $showLot = (bool) session('business.enable_lot_number');
@endphp

<x-page-head :back="route('purchases.show', $purchase->id)" :backLabel="$purchase->ref_no">
    <x-slot:subtitle>
        <span class="font-medium text-slate-700">
            {{ or_dash($purchase->contact->full_name_with_business ?? null) }}
        </span>
        <span class="text-slate-300">&middot;</span>
        <span class="force-ltr">{{ $purchase->ref_no }}</span>
        <span class="text-slate-300">&middot;</span>
        @format_date($purchase->transaction_date)
    </x-slot:subtitle>
</x-page-head>

@if ($existingReturn)
    <div class="alert-warning mb-6">
        <span>{{ __('lang_v1.return_already_exists', ['ref' => $existingReturn->ref_no]) }}</span>
    </div>
@endif

<form method="POST" action="{{ route('purchase-return.store', $purchase->id) }}">
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
                        @if ($showLot)
                            <th>{{ __('lang_v1.lot_number') }}</th>
                        @endif
                        <th class="th-numeric">{{ __('lang_v1.purchased') }}</th>
                        <th class="th-numeric">{{ __('lang_v1.already_returned') }}</th>
                        <th class="th-numeric">{{ __('lang_v1.returnable') }}</th>
                        <th class="th-numeric w-32">{{ __('lang_v1.return_quantity') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lines as $index => $row)
                        @php $exhausted = $row['returnable'] <= 0; @endphp

                        {{-- A lot with nothing left to send back keeps its row — the
                             quantities are the evidence — but is toned down so the
                             rows that can still be acted on are the ones that read. --}}
                        <tr @class(['opacity-60' => $exhausted])>
                            <td>
                                <input type="hidden" name="lines[{{ $index }}][purchase_line_id]"
                                       value="{{ $row['lot']->id }}">
                                <span class="cell-primary">{{ $row['lot']->variations->full_name }}</span>
                                <span class="cell-meta force-ltr">{{ $row['lot']->variations->sub_sku }}</span>
                            </td>

                            @if ($showLot)
                                <td class="force-ltr">{{ or_dash($row['lot']->lot_number) }}</td>
                            @endif

                            <td class="cell-numeric">@format_quantity($row['lot']->quantity)</td>
                            <td class="cell-numeric">@format_quantity($row['already_returned'])</td>
                            <td class="cell-numeric font-semibold">@format_quantity($row['returnable'])</td>

                            <td>
                                {{-- max is the untouched remainder of this lot: anything
                                     already sold on cannot be sent back. min stops a
                                     negative quantity being typed into a return, which
                                     would read as a second purchase. --}}
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
        <span class="form-actions-spacer">{{ __('lang_v1.returnable_hint') }}</span>
        <a href="{{ route('purchases.show', $purchase->id) }}" class="btn-secondary">
            {{ __('lang_v1.cancel') }}
        </a>
        <button type="submit" class="btn-primary">
            <x-nav-icon name="undo"/>
            {{ __('lang_v1.save') }}
        </button>
    </div>
</form>
@endsection
