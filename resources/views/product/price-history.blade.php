@extends('layouts.app')
@section('title', __('lang_v1.price_history'))
@section('page_title', $variation->full_name.' — '.__('lang_v1.price_history'))

@section('content')

@php
    $entries = $variation->price_history->sortByDesc('created_at');
@endphp

<x-page-head :back="route('products.show', $variation->product_id)"
             :backLabel="__('lang_v1.back_to_product')"
             :subtitle="trans_choice('lang_v1.record_count', $entries->count(), ['count' => $entries->count()])"/>

<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>{{ __('lang_v1.date') }}</th>
                <th>{{ __('lang_v1.reason') }}</th>
                <th class="th-numeric">{{ __('lang_v1.purchase_price') }}</th>
                <th class="th-numeric">{{ __('lang_v1.sell_price') }}</th>
                <th>{{ __('lang_v1.by') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($entries as $entry)
                <tr>
                    <td class="whitespace-nowrap">@format_datetime($entry->created_at)</td>
                    <td>
                        <span class="badge-muted">{{ $entry->formatted_change_type }}</span>
                        @if ($entry->change_reason)
                            <span class="cell-meta">{{ $entry->change_reason }}</span>
                        @endif
                        @if ($entry->calculation_details)
                            {{-- Shows exactly how a weighted-average cost was derived,
                                 which is the one thing an accountant queries. --}}
                            <code class="force-ltr mt-1 block text-xs text-slate-400">
                                {{ $entry->calculation_details }}
                            </code>
                        @endif
                    </td>

                    {{-- Old → new, in one cell. The arrow is not .icon-directional:
                         .cell-numeric forces the cell to LTR so the two figures stay
                         readable in Arabic, and mirroring the arrow inside an island
                         that is already left-to-right would point it back at the
                         value it came from. --}}
                    <td class="cell-numeric">
                        <span class="text-slate-400">@format_currency($entry->old_purchase_price)</span>
                        <span class="mx-1 text-slate-300">→</span>
                        <span class="font-semibold">@format_currency($entry->new_purchase_price)</span>
                    </td>
                    <td class="cell-numeric">
                        <span class="text-slate-400">@format_currency($entry->old_sell_price)</span>
                        <span class="mx-1 text-slate-300">→</span>
                        <span class="font-semibold">@format_currency($entry->new_sell_price)</span>
                    </td>

                    <td>{{ or_dash($entry->createdBy->user_full_name ?? null) }}</td>
                </tr>
            @empty
                <x-table-empty :columns="5" icon="clock"
                               :title="__('lang_v1.no_price_changes_yet')"/>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
