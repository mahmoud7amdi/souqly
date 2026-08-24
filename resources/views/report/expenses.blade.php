@extends('layouts.app')
@section('title', __('lang_v1.expense_report'))
@section('page_title', __('lang_v1.expense_report'))

@section('content')

@php
    /* Rows arrive ordered by spend, so the first one is the largest category.
       Worth a tile: "where did the money go" is answered by a name, not a total. */
    $top = $rows->first();

    /* Share is only meaningful against a positive net. A period whose refunds
       exceeded its spending has a zero or negative denominator, and a percentage
       of that is noise dressed as a figure. */
    $canShare = $summary['net'] > 0;
@endphp

<x-page-head :subtitle="format_date($range['start']).' — '.format_date($range['end'])"
             :back="route('reports.index')"
             :backLabel="__('lang_v1.reports')"/>

<div class="section">
    <div class="rise-group grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat :label="__('lang_v1.net_expense')"
                :value="format_currency($summary['net'])"
                icon="receipt"
                :hint="__('lang_v1.after_refunds')"/>

        <x-stat :label="__('lang_v1.total_expense')"
                :value="format_currency($summary['gross'])"
                icon="cash"
                :hint="__('lang_v1.before_refunds')"/>

        <x-stat :label="__('lang_v1.refunds')"
                :value="format_currency($summary['returned'])"
                icon="undo"/>

        <x-stat :label="__('lang_v1.largest_category')"
                :value="$top ? ($top->category_name ?: __('lang_v1.uncategorised')) : '—'"
                icon="folder"
                :hint="$top ? format_currency($top->spent) : null"/>
    </div>
</div>

<x-report-filters report="expenses"
                  :action="route('reports.expenses')"
                  :range="$range"
                  :fields="['location', 'expense_category']"
                  :locations="$locations"
                  :categories="$categories"/>

{{-- Grouped by category rather than listed document by document: the expense
     listing at expenses.index already answers "which expense was that", and this
     report answers the different question of where the money goes. --}}
<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>{{ __('lang_v1.expense_category') }}</th>
                <th class="th-numeric">{{ __('lang_v1.documents') }}</th>
                <th class="th-numeric">{{ __('lang_v1.total_expense') }}</th>
                <th class="th-numeric">{{ __('lang_v1.refunds') }}</th>
                <th class="th-numeric">{{ __('lang_v1.net_expense') }}</th>
                <th class="th-numeric">{{ __('lang_v1.share') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                @php
                    $net = (float) $row->spent - (float) $row->refunded;
                @endphp
                <tr>
                    {{-- An expense with no category is a real record, not a gap in
                         the data, so it is named rather than dashed out. --}}
                    <td class="cell-primary">
                        {{ $row->category_name ?: __('lang_v1.uncategorised') }}
                    </td>

                    <td class="cell-numeric">{{ $row->documents }}</td>
                    <td class="cell-numeric">@format_currency($row->spent)</td>

                    <td @class(['cell-numeric', 'text-amber-700' => $row->refunded > 0])>
                        @if ($row->refunded > 0)&minus;@endif@format_currency($row->refunded)
                    </td>

                    <td class="cell-numeric font-semibold">@format_currency($net)</td>

                    <td class="cell-numeric">
                        @if ($canShare)
                            {{ format_number($net / $summary['net'] * 100) }}%
                        @else
                            {{ or_dash(null) }}
                        @endif
                    </td>
                </tr>
            @empty
                <x-table-empty :columns="6" icon="receipt"
                               :title="__('lang_v1.no_expenses_in_period')"
                               :text="__('lang_v1.no_expenses_in_period_desc')"/>
            @endforelse
        </tbody>

        @if ($rows->isNotEmpty())
            <tfoot>
                <tr>
                    <td>{{ __('lang_v1.total') }}</td>
                    <td class="cell-numeric">{{ $rows->sum('documents') }}</td>
                    <td class="cell-numeric">@format_currency($summary['gross'])</td>
                    <td class="cell-numeric">&minus;@format_currency($summary['returned'])</td>
                    <td class="cell-numeric">@format_currency($summary['net'])</td>
                    <td></td>
                </tr>
            </tfoot>
        @endif
    </table>
</div>

@endsection
