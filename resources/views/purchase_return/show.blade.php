@extends('layouts.app')
@section('title', $return->ref_no)
@section('page_title', __('lang_v1.purchase_return').' — '.$return->ref_no)

@section('content')

@php
    $showLot = (bool) session('business.enable_lot_number');
    $columnCount = $showLot ? 5 : 4;
@endphp

{{-- Same shape as a purchase document: the sticky header names the type and
     reference, so the head carries who, when, and the invoice this reverses. --}}
<x-page-head :back="route('purchase-return.index')" :backLabel="__('lang_v1.purchase_returns')">
    <x-slot:subtitle>
        <span class="inline-flex flex-wrap items-center gap-x-2 gap-y-1">
            <span class="font-medium text-slate-700">
                {{ or_dash($return->contact->full_name_with_business ?? null) }}
            </span>
            <span class="text-slate-300">&middot;</span>
            <span class="force-ltr">@format_datetime($return->transaction_date)</span>
            <span class="text-slate-300">&middot;</span>
            <span>{{ or_dash($return->location->name ?? null) }}</span>

            @if ($return->return_parent_sell)
                <span class="text-slate-300">&middot;</span>
                <span>
                    {{ __('lang_v1.parent_purchase') }}:
                    <a href="{{ route('purchases.show', $return->return_parent_sell->id) }}"
                       class="link force-ltr">
                        {{ $return->return_parent_sell->ref_no }}
                    </a>
                </span>
            @endif

            <span class="ms-1 inline-flex items-center">
                @payment_status($return->payment_status)
            </span>
        </span>
    </x-slot:subtitle>

    <button type="button" onclick="window.print()" class="btn-secondary">
        <x-nav-icon name="printer"/>
        {{ __('lang_v1.print') }}
    </button>
</x-page-head>

<div class="grid gap-6 lg:grid-cols-4">

    <x-panel :title="__('lang_v1.items')" icon="box" :count="$lots->count()" class="lg:col-span-3" flush>
        <div class="table-wrap table-flush">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ __('lang_v1.product') }}</th>
                        @if ($showLot)
                            <th>{{ __('lang_v1.lot_number') }}</th>
                        @endif
                        <th class="th-numeric">{{ __('lang_v1.returned_quantity') }}</th>
                        <th class="th-numeric">{{ __('lang_v1.unit_cost') }}</th>
                        <th class="th-numeric">{{ __('lang_v1.subtotal') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($lots as $lot)
                        <tr>
                            <td>
                                <span class="cell-primary">{{ $lot->variations->full_name }}</span>
                                <span class="cell-meta force-ltr">{{ $lot->variations->sub_sku }}</span>
                            </td>

                            @if ($showLot)
                                <td class="force-ltr">{{ or_dash($lot->lot_number) }}</td>
                            @endif

                            <td class="cell-numeric">@format_quantity($lot->quantity_returned)</td>
                            <td class="cell-numeric">@format_currency($lot->purchase_price_inc_tax)</td>
                            <td class="cell-numeric">
                                @format_currency($lot->quantity_returned * $lot->purchase_price_inc_tax)
                            </td>
                        </tr>
                    @empty
                        <x-table-empty :columns="$columnCount" icon="undo"/>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-panel>

    <x-panel :title="__('lang_v1.summary')" icon="coins" class="self-start">
        {{-- No .dl-total here: that primitive draws a rule to close a breakdown,
             and a return has nothing above its total to sum up. Weight alone
             makes the figure the heading of the panel it sits in. --}}
        <dl class="dl">
            <div class="dl-row">
                <dt class="font-semibold text-slate-900">{{ __('lang_v1.total') }}</dt>
                <dd class="dl-total-value">@format_currency($return->final_total)</dd>
            </div>

            <div class="dl-row">
                <dt class="dl-key">{{ __('lang_v1.paid') }}</dt>
                <dd class="dl-value text-emerald-600">@format_currency($paid)</dd>
            </div>

            {{-- Money the supplier still owes back, so it is toned only while it
                 is outstanding — a settled return should read as closed. --}}
            <div class="dl-row">
                <dt class="dl-key">{{ __('lang_v1.due') }}</dt>
                <dd @class(['dl-value', 'font-semibold text-rose-600' => $due > 0])>
                    @format_currency($due)
                </dd>
            </div>
        </dl>
    </x-panel>
</div>
@endsection
