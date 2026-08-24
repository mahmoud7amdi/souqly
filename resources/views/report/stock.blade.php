@extends('layouts.app')
@section('title', __('lang_v1.stock_report'))
@section('page_title', __('lang_v1.stock_report'))

@section('content')

@php
    /* What the stock would earn if every unit sold at its current selling price.
       Derived here rather than in the service because it is a presentation of two
       figures the service already returns, not a third fact about the ledger. */
    $potentialProfit = $totals['potential'] - $totals['value'];
@endphp

<x-page-head :subtitle="trans_choice('lang_v1.record_count', $records->total(), ['count' => $records->total()])"
             :back="route('reports.index')"
             :backLabel="__('lang_v1.reports')"/>

<div class="section">
    <div class="rise-group grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat :label="__('lang_v1.stock_value')"
                :value="format_currency($totals['value'])"
                icon="layers"
                :hint="__('lang_v1.at_cost')"/>

        <x-stat :label="__('lang_v1.potential_sale_value')"
                :value="format_currency($totals['potential'])"
                icon="coins"
                :hint="__('lang_v1.at_selling_price')"/>

        {{-- A margin, not a promise: it assumes every unit sells at today's price
             and nothing is discounted, written off or lost. The hint says so. --}}
        <x-stat :label="__('lang_v1.potential_profit')"
                :value="format_currency($potentialProfit)"
                icon="chart"
                :hint="__('lang_v1.if_all_sold_at_current_price')"
                :tone="$potentialProfit < 0 ? 'danger' : 'success'"/>

        <x-stat :label="__('lang_v1.current_stock')"
                :value="format_quantity($totals['quantity'])"
                icon="box"
                :hint="__('lang_v1.total_units')"/>
    </div>
</div>

{{-- No date pair, and the component renders none because no range is passed:
     stock is a position, not a period. --}}
<x-report-filters report="stock"
                  :action="route('reports.stock')"
                  :fields="['location', 'category', 'brand']"
                  :locations="$locations"
                  :categories="$categories"
                  :brands="$brands"/>

<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>{{ __('lang_v1.product') }}</th>
                <th>{{ __('lang_v1.variation') }}</th>
                <th>{{ __('lang_v1.business_location') }}</th>
                <th>{{ __('lang_v1.category') }}</th>
                <th>{{ __('lang_v1.brand') }}</th>
                <th class="th-numeric">{{ __('lang_v1.current_stock') }}</th>
                <th class="th-numeric">{{ __('lang_v1.unit_cost') }}</th>
                <th class="th-numeric">{{ __('lang_v1.stock_value') }}</th>
                <th class="th-numeric">{{ __('lang_v1.potential_sale_value') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($records as $row)
                <tr>
                    <td>
                        <span class="cell-primary">{{ $row->product_name }}</span>
                        <span class="cell-meta force-ltr">{{ $row->sub_sku ?: $row->sku }}</span>
                    </td>

                    {{-- Single-variation products carry the sentinel name 'DUMMY',
                         which is an implementation detail and not a thing anyone
                         should read on a report. --}}
                    <td>
                        {{ $row->variation_name === 'DUMMY'
                            ? __('lang_v1.default')
                            : $row->variation_name }}
                    </td>

                    <td>{{ or_dash($row->location_name) }}</td>
                    <td>{{ or_dash($row->category_name) }}</td>
                    <td>{{ or_dash($row->brand_name) }}</td>

                    {{-- Zero and negative are both worth colouring. Negative stock
                         is not a rounding artefact: it means goods left without a
                         purchase or an opening quantity behind them. --}}
                    <td @class(['cell-numeric', 'text-rose-700' => $row->qty_available <= 0])>
                        @format_quantity($row->qty_available)
                        <span class="cell-meta">{{ $row->unit_name }}</span>
                    </td>

                    <td class="cell-numeric">@format_currency($row->unit_cost)</td>
                    <td class="cell-numeric">@format_currency($row->stock_value)</td>
                    <td class="cell-numeric">@format_currency($row->potential_value)</td>
                </tr>
            @empty
                <x-table-empty :columns="9" icon="layers"
                               :title="__('lang_v1.no_stock_found')"
                               :text="__('lang_v1.no_stock_found_desc')"/>
            @endforelse
        </tbody>

        @if ($records->isNotEmpty())
            {{-- The footer totals every row the filters match, not just the page on
                 screen, so it is labelled — a total that silently disagrees with
                 the column above it is worse than no total. --}}
            <tfoot>
                <tr>
                    <td colspan="5">
                        {{ __('lang_v1.total') }}
                        <span class="cell-meta">{{ __('lang_v1.across_all_pages') }}</span>
                    </td>
                    <td class="cell-numeric">@format_quantity($totals['quantity'])</td>
                    <td></td>
                    <td class="cell-numeric">@format_currency($totals['value'])</td>
                    <td class="cell-numeric">@format_currency($totals['potential'])</td>
                </tr>
            </tfoot>
        @endif
    </table>

    {{ $records->links() }}
</div>

{{-- The icon is wrapped rather than given a class: <x-nav-icon> hard-codes its own
     class attribute and does not forward $attributes. --}}
<p class="mt-3 flex items-start gap-2 text-sm text-slate-500">
    <span class="mt-0.5 shrink-0"><x-nav-icon name="info" :size="4"/></span>
    <span>{{ __('lang_v1.stock_report_position_note') }}</span>
</p>

@endsection
