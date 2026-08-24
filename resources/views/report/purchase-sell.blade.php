@extends('layouts.app')
@section('title', __('lang_v1.purchase_n_sell_report'))
@section('page_title', __('lang_v1.purchase_n_sell_report'))

@section('content')

<x-page-head :subtitle="format_date($range['start']).' — '.format_date($range['end'])"
             :back="route('reports.index')"
             :backLabel="__('lang_v1.reports')"/>

{{-- Net figures lead, because the question this report answers is what actually
     moved in and out — not what the invoices said before returns. --}}
<div class="section">
    <div class="rise-group grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat :label="__('lang_v1.net_sell')"
                :value="format_currency($totals['net_sell'])"
                icon="cart"
                :hint="__('lang_v1.after_returns')"/>

        <x-stat :label="__('lang_v1.net_purchase')"
                :value="format_currency($totals['net_purchase'])"
                icon="truck"
                :hint="__('lang_v1.after_returns')"/>

        <x-stat :label="__('lang_v1.sell_minus_purchase')"
                :value="format_currency($totals['difference'])"
                icon="scale"
                :tone="$totals['difference'] < 0 ? 'danger' : 'success'"/>

        <x-stat :label="__('lang_v1.total_due')"
                :value="format_currency($totals['sell']['due'] + $totals['purchase']['due'])"
                icon="clock"
                :hint="__('lang_v1.both_directions')"
                :tone="($totals['sell']['due'] + $totals['purchase']['due']) > 0 ? 'danger' : null"/>
    </div>
</div>

<x-report-filters report="purchase-sell"
                  :action="route('reports.purchaseSell')"
                  :range="$range"
                  :fields="['location']"
                  :locations="$locations"/>

{{-- The two sides sit in one table rather than two panels so they can be read
     across: the eye compares "sold 40,000 / bought 31,000" in one movement,
     which two separate cards make impossible. --}}
<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>{{ __('lang_v1.description') }}</th>
                <th class="th-numeric">{{ __('lang_v1.count') }}</th>
                <th class="th-numeric">{{ __('lang_v1.total') }}</th>
                <th class="th-numeric">{{ __('lang_v1.paid') }}</th>
                <th class="th-numeric">{{ __('lang_v1.due') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="cell-primary">{{ __('lang_v1.total_purchase') }}</td>
                <td class="cell-numeric">{{ $totals['purchase']['count'] }}</td>
                <td class="cell-numeric">@format_currency($totals['purchase']['total'])</td>
                <td class="cell-numeric">@format_currency($totals['purchase']['paid'])</td>
                <td class="cell-numeric">@format_currency($totals['purchase']['due'])</td>
            </tr>
            <tr>
                <td class="ps-8 text-sm text-slate-500">
                    <span class="icon-directional inline-block">↳</span>
                    {{ __('lang_v1.purchase_return') }}
                </td>
                <td class="cell-numeric text-sm">{{ $totals['purchase_return']['count'] }}</td>
                <td class="cell-numeric text-sm">@format_currency($totals['purchase_return']['total'])</td>
                <td class="cell-numeric text-sm">@format_currency($totals['purchase_return']['paid'])</td>
                <td class="cell-numeric text-sm">@format_currency($totals['purchase_return']['due'])</td>
            </tr>

            <tr>
                <td class="cell-primary">{{ __('lang_v1.total_sell') }}</td>
                <td class="cell-numeric">{{ $totals['sell']['count'] }}</td>
                <td class="cell-numeric">@format_currency($totals['sell']['total'])</td>
                <td class="cell-numeric">@format_currency($totals['sell']['paid'])</td>
                <td class="cell-numeric">@format_currency($totals['sell']['due'])</td>
            </tr>
            <tr>
                <td class="ps-8 text-sm text-slate-500">
                    <span class="icon-directional inline-block">↳</span>
                    {{ __('lang_v1.sell_return') }}
                </td>
                <td class="cell-numeric text-sm">{{ $totals['sell_return']['count'] }}</td>
                <td class="cell-numeric text-sm">@format_currency($totals['sell_return']['total'])</td>
                <td class="cell-numeric text-sm">@format_currency($totals['sell_return']['paid'])</td>
                <td class="cell-numeric text-sm">@format_currency($totals['sell_return']['due'])</td>
            </tr>
        </tbody>
        <tfoot>
            <tr>
                <td>{{ __('lang_v1.sell_minus_purchase') }}</td>
                <td></td>
                <td class="cell-numeric">@format_currency($totals['difference'])</td>
                <td colspan="2"></td>
            </tr>
        </tfoot>
    </table>
</div>

{{-- Said plainly on the screen rather than only in the code: this figure is a
     cash-flow comparison, not a profit. Stock bought this month is usually sold
     next month, so the two sides do not describe the same goods.

     The icon is wrapped rather than given a class — <x-nav-icon> hard-codes its
     own class attribute and does not forward $attributes, so a class passed to
     it disappears without a word. --}}
<p class="mt-3 flex items-start gap-2 text-sm text-slate-500">
    <span class="mt-0.5 shrink-0"><x-nav-icon name="info" :size="4"/></span>
    <span>
        {{ __('lang_v1.purchase_n_sell_not_profit') }}
        @can('profit_loss_report.view')
            <a href="{{ route('reports.profitLoss') }}" class="link">{{ __('lang_v1.profit_loss_report') }}</a>
        @endcan
    </span>
</p>

@endsection
