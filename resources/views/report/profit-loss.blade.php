@extends('layouts.app')
@section('title', __('lang_v1.profit_loss_report'))
@section('page_title', __('lang_v1.profit_loss_report'))

@section('content')

<x-page-head :subtitle="format_date($range['start']).' — '.format_date($range['end'])"
             :back="route('reports.index')"
             :backLabel="__('lang_v1.reports')"/>

<div class="section">
    <div class="rise-group grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat :label="__('lang_v1.gross_profit')"
                :value="format_currency($figures['gross_profit'])"
                icon="coins"
                :hint="__('lang_v1.sales_minus_cost')"
                :tone="$figures['gross_profit'] < 0 ? 'danger' : 'success'"/>

        <x-stat :label="__('lang_v1.net_profit')"
                :value="format_currency($figures['net_profit'])"
                icon="chart"
                :hint="__('lang_v1.after_expenses')"
                :tone="$figures['net_profit'] < 0 ? 'danger' : 'success'"/>

        <x-stat :label="__('lang_v1.net_sell')"
                :value="format_currency($figures['sales']['net'])"
                icon="cart"/>

        <x-stat :label="__('lang_v1.gross_profit_margin')"
                :value="format_number($figures['margin']).'%'"
                icon="percent"
                :hint="__('lang_v1.on_net_sales')"/>
    </div>
</div>

<x-report-filters report="profit-loss"
                  :action="route('reports.profitLoss')"
                  :range="$range"
                  :fields="['location']"
                  :locations="$locations"/>

{{-- Built to be read top to bottom and checked line against line: every total is
     the arithmetic of the rows above it, and the two subtotals are the only rows
     that are not a raw figure. That is on purpose — a P&L nobody can reconcile is
     a P&L nobody believes. --}}
<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>{{ __('lang_v1.description') }}</th>
                <th class="th-numeric">{{ __('lang_v1.amount') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="cell-primary">{{ __('lang_v1.total_sell') }}</td>
                <td class="cell-numeric">@format_currency($figures['sales']['gross'])</td>
            </tr>
            <tr>
                <td class="ps-8 text-sm text-slate-500">
                    <span class="icon-directional inline-block">↳</span>
                    {{ __('lang_v1.sell_return') }}
                </td>
                <td class="cell-numeric text-sm text-slate-500">
                    −@format_currency($figures['sales']['returned'])
                </td>
            </tr>
            <tr class="bg-slate-50/60">
                <td class="cell-primary">{{ __('lang_v1.net_sell') }}</td>
                <td class="cell-numeric font-semibold">@format_currency($figures['sales']['net'])</td>
            </tr>

            {{-- Cost comes straight off the FIFO map, so it is the cost of the
                 goods that actually left the shelf — not an average and not this
                 period's purchases. --}}
            <tr>
                <td class="cell-primary">{{ __('lang_v1.cost_of_goods_sold') }}</td>
                <td class="cell-numeric">−@format_currency($figures['cogs']['gross'])</td>
            </tr>
            <tr>
                <td class="ps-8 text-sm text-slate-500">
                    <span class="icon-directional inline-block">↳</span>
                    {{ __('lang_v1.cost_of_returned_goods') }}
                </td>
                <td class="cell-numeric text-sm text-slate-500">
                    +@format_currency($figures['cogs']['returned'])
                </td>
            </tr>
            <tr class="bg-slate-50/60">
                <td class="cell-primary">{{ __('lang_v1.gross_profit') }}</td>
                <td @class(['cell-numeric font-semibold', 'text-rose-700' => $figures['gross_profit'] < 0])>
                    @format_currency($figures['gross_profit'])
                </td>
            </tr>

            <tr>
                <td>{{ __('lang_v1.shipping_charges') }}</td>
                <td class="cell-numeric">+@format_currency($figures['shipping'])</td>
            </tr>
            <tr>
                <td>{{ __('lang_v1.discount') }}</td>
                <td class="cell-numeric">−@format_currency($figures['discount'])</td>
            </tr>
            <tr>
                <td>{{ __('lang_v1.net_expense') }}</td>
                <td class="cell-numeric">−@format_currency($figures['expenses']['net'])</td>
            </tr>
        </tbody>
        <tfoot>
            <tr>
                <td>{{ __('lang_v1.net_profit') }}</td>
                <td @class(['cell-numeric', 'text-rose-700' => $figures['net_profit'] < 0])>
                    @format_currency($figures['net_profit'])
                </td>
            </tr>
        </tfoot>
    </table>
</div>

{{-- The one convention a reader cannot infer from the numbers, so it is stated
     on the screen rather than only in NOTES: both sides of the margin are
     tax-inclusive, because that is the cost the FIFO map records. --}}
<p class="mt-3 flex items-start gap-2 text-sm text-slate-500">
    <span class="mt-0.5 shrink-0"><x-nav-icon name="info" :size="4"/></span>
    <span>{{ __('lang_v1.profit_loss_tax_inclusive_note') }}</span>
</p>

@endsection
