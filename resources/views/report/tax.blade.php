@extends('layouts.app')
@section('title', __('lang_v1.tax_report'))
@section('page_title', __('lang_v1.tax_report'))

@section('content')

<x-page-head :subtitle="format_date($range['start']).' — '.format_date($range['end'])"
             :back="route('reports.index')"
             :backLabel="__('lang_v1.reports')"/>

{{-- Payable leads, because it is the only figure anyone has to act on. Its tone
     is deliberate and not decorative: money owed to the authority is red, a
     reclaimable balance is green. --}}
<div class="section">
    <div class="rise-group grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat :label="__('lang_v1.tax_payable')"
                :value="format_currency($summary['payable'])"
                icon="scale"
                :hint="$summary['payable'] < 0 ? __('lang_v1.reclaimable') : __('lang_v1.owed_to_authority')"
                :tone="$summary['payable'] > 0 ? 'danger' : 'success'"/>

        <x-stat :label="__('lang_v1.output_tax')"
                :value="format_currency($summary['output']['net'])"
                icon="cart"
                :hint="__('lang_v1.tax_collected_on_sales')"/>

        <x-stat :label="__('lang_v1.input_tax')"
                :value="format_currency($summary['input']['net'])"
                icon="truck"
                :hint="__('lang_v1.tax_paid_on_purchases')"/>

        <x-stat :label="__('lang_v1.tax_rates')"
                :value="$rates->count()"
                icon="percent"
                :hint="__('lang_v1.rates_used_in_period')"/>
    </div>
</div>

<x-report-filters report="tax"
                  :action="route('reports.tax')"
                  :range="$range"
                  :fields="['location']"
                  :locations="$locations"/>

{{-- Returns are shown on their own rows rather than folded into the figure above
     them. A netted total is the shape in which a double-subtracted return hides,
     and this is the one report where that error is expensive. --}}
<div class="section">
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
                    <td class="cell-primary">{{ __('lang_v1.output_tax') }}</td>
                    <td class="cell-numeric">@format_currency($summary['output']['gross'])</td>
                </tr>
                <tr>
                    <td class="ps-8 text-sm text-slate-500">
                        <span class="icon-directional inline-block">↳</span>
                        {{ __('lang_v1.output_tax_on_returns') }}
                    </td>
                    <td class="cell-numeric text-sm text-slate-500">
                        −@format_currency($summary['output']['returned'])
                    </td>
                </tr>

                <tr>
                    <td class="cell-primary">{{ __('lang_v1.input_tax') }}</td>
                    <td class="cell-numeric">−@format_currency($summary['input']['gross'])</td>
                </tr>
                <tr>
                    <td class="ps-8 text-sm text-slate-500">
                        <span class="icon-directional inline-block">↳</span>
                        {{ __('lang_v1.input_tax_on_returns') }}
                    </td>
                    <td class="cell-numeric text-sm text-slate-500">
                        +@format_currency($summary['input']['returned'])
                    </td>
                </tr>
            </tbody>
            <tfoot>
                <tr>
                    <td>{{ __('lang_v1.tax_payable') }}</td>
                    <td @class(['cell-numeric', 'text-rose-700' => $summary['payable'] > 0])>
                        @format_currency($summary['payable'])
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<div class="section-head">
    <div class="section-head-text">
        <p class="section-eyebrow">{{ __('lang_v1.breakdown') }}</p>
        <h2 class="section-title">{{ __('lang_v1.tax_by_rate') }}</h2>
        <p class="section-desc">{{ __('lang_v1.tax_by_rate_desc') }}</p>
    </div>
</div>

<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>{{ __('lang_v1.tax_rate') }}</th>
                <th class="th-numeric">{{ __('lang_v1.rate') }}</th>
                <th>{{ __('lang_v1.type') }}</th>
                <th class="th-numeric">{{ __('lang_v1.documents') }}</th>
                <th class="th-numeric">{{ __('lang_v1.taxable_amount') }}</th>
                <th class="th-numeric">{{ __('lang_v1.tax_amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rates as $rate)
                <tr>
                    <td class="cell-primary">{{ or_dash($rate->rate_name) }}</td>
                    <td class="cell-numeric">{{ format_number($rate->rate) }}%</td>
                    <td><span class="badge-muted">{{ __('lang_v1.'.$rate->type) }}</span></td>
                    <td class="cell-numeric">{{ $rate->documents }}</td>
                    <td class="cell-numeric">@format_currency($rate->taxable)</td>
                    <td class="cell-numeric">@format_currency($rate->tax)</td>
                </tr>
            @empty
                {{-- Only rates that were actually applied appear here, so an empty
                     table means no taxed document in the period — not a missing
                     tax setup. The text says which. --}}
                <x-table-empty :columns="6" icon="percent"
                               :title="__('lang_v1.no_tax_in_period')"
                               :text="__('lang_v1.no_tax_in_period_desc')"/>
            @endforelse
        </tbody>
    </table>
</div>

@endsection
