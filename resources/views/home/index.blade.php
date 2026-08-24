@extends('layouts.app')
@section('title', __('lang_v1.dashboard'))
@section('page_title', __('lang_v1.dashboard'))

@section('content')

@php
    /* Quick ranges as links rather than a second form: one tap is faster than
       two date pickers, and the chips double as a read-out of the active
       period. Computed here because the controller only receives the dates. */
    $today = \Carbon\Carbon::today();
    $quickRanges = [
        'today' => [__('lang_v1.today'), $today->toDateString(), $today->toDateString()],
        'week' => [__('lang_v1.last_7_days'), $today->copy()->subDays(6)->toDateString(), $today->toDateString()],
        'month' => [__('lang_v1.this_month'), $today->copy()->startOfMonth()->toDateString(), $today->toDateString()],
        'days30' => [__('lang_v1.last_30_days'), $today->copy()->subDays(29)->toDateString(), $today->toDateString()],
    ];
@endphp

{{-- ============ Page head ================================================
     No title: the sticky header already says "Dashboard". What this strip adds
     is the period the figures below cover, and the two actions a shop owner
     opens the dashboard to take — in the same position they occupy on every
     other screen. --}}
<x-page-head>
    <x-slot:subtitle>
        <span class="force-ltr">@format_date($range['start']) — @format_date($range['end'])</span>
    </x-slot:subtitle>

    @if (Route::has('sells.create'))
        @can('direct_sell.access')
            <a href="{{ route('sells.create') }}" class="btn-secondary">
                <x-nav-icon name="plus"/>
                {{ __('lang_v1.add_sale') }}
            </a>
        @endcan
    @endif
    @if (Route::has('pos.create'))
        @can('sell.create')
            <a href="{{ route('pos.create') }}" class="btn-accent">
                <x-nav-icon name="pos"/>
                {{ __('lang_v1.pos') }}
            </a>
        @endcan
    @endif
</x-page-head>

{{-- ============ Period filter =========================================== --}}
<div class="filter-bar">
    <div class="mb-3 flex flex-wrap gap-2">
        @foreach ($quickRanges as $quick)
            <a href="{{ request()->fullUrlWithQuery(['start_date' => $quick[1], 'end_date' => $quick[2]]) }}"
               @class(['chip', 'chip-active' => $range['start'] === $quick[1] && $range['end'] === $quick[2]])>
                {{ $quick[0] }}
            </a>
        @endforeach
    </div>

    <form method="GET" class="flex flex-wrap items-end gap-3">
        <div class="field">
            <label for="start_date" class="label">{{ __('lang_v1.from') }}</label>
            <input type="date" id="start_date" name="start_date" value="{{ $range['start'] }}" class="input w-auto">
        </div>
        <div class="field">
            <label for="end_date" class="label">{{ __('lang_v1.to') }}</label>
            <input type="date" id="end_date" name="end_date" value="{{ $range['end'] }}" class="input w-auto">
        </div>
        <button type="submit" class="btn-primary">
            <x-nav-icon name="filter"/>
            {{ __('lang_v1.apply') }}
        </button>
    </form>
</div>

{{-- ============ Headline figures ========================================
     Sales first (the number the business is judged on), then what it cost,
     then what leaked back out. Same order every time.

     Only the two figures that represent money leaving are toned: purchases and
     expenses are normal costs and stay neutral, while returns are the one line
     an owner scans for a spike. --}}
<div class="section">
    {{-- `.rise-group` staggers the four figures in rather than fading the row as
         one block. It composes with the page-level `.rise` in layouts/app.blade.php
         rather than replacing it: the page carries the whole content up, and this
         adds at most 80 ms of lag between the first tile and the fourth, so the row
         settles at 420 ms instead of 340. Deliberate — a row of four identical
         cards is the one place on the dashboard where arriving together reads as
         flat. --}}
    <div class="rise-group grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat :label="__('lang_v1.net_sales')"
                :value="format_currency($totals['net_sales'])"
                icon="receipt"
                :hint="isset($totals['sell_count'])
                    ? trans_choice('lang_v1.invoice_count', $totals['sell_count'], ['count' => $totals['sell_count']])
                    : null"/>

        <x-stat :label="__('lang_v1.total_purchases')"
                :value="format_currency($totals['purchase_total'])"
                icon="truck"
                :hint="isset($totals['purchase_count'])
                    ? trans_choice('lang_v1.invoice_count', $totals['purchase_count'], ['count' => $totals['purchase_count']])
                    : null"/>

        <x-stat :label="__('lang_v1.total_expenses')"
                :value="format_currency($totals['expense_total'])"
                icon="minus-circle"/>

        <x-stat :label="__('lang_v1.sell_returns')"
                :value="format_currency($totals['sell_return_total'])"
                icon="undo"
                :tone="$totals['sell_return_total'] > 0 ? 'danger' : null"/>
    </div>
</div>

{{-- ============ Needs attention =========================================
     Everything below this heading is a list of things somebody has to act on,
     grouped away from the read-only figures above it. --}}
<div class="section">
    <div class="section-head">
        <h2 class="section-title">{{ __('lang_v1.needs_attention') }}</h2>
    </div>

    <div class="grid gap-5 lg:grid-cols-2">

        {{-- Low stock --}}
        <x-panel :title="__('lang_v1.stock_alerts')" icon="alert"
                 :count="$stockAlerts->count()" tone="warning" flush>
            @if ($stockAlerts->isEmpty())
                <x-empty-state icon="check-circle"
                               :title="__('lang_v1.stock_levels_healthy')" compact/>
            @else
                <div class="table-wrap table-flush">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('lang_v1.product') }}</th>
                                <th>{{ __('lang_v1.location') }}</th>
                                <th class="th-numeric">{{ __('lang_v1.stock') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($stockAlerts as $alert)
                                <tr>
                                    <td>
                                        <span class="cell-primary">{{ $alert->product }}</span>
                                        <span class="cell-meta force-ltr">{{ $alert->sku }}</span>
                                    </td>
                                    <td>{{ $alert->location }}</td>
                                    <td class="cell-numeric font-semibold text-rose-700">
                                        @format_quantity($alert->qty_available)
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if (Route::has('products.index') && $stockAlerts->isNotEmpty())
                <x-slot:actions>
                    <a href="{{ route('products.index') }}" class="link text-xs">
                        {{ __('lang_v1.view_all') }}
                    </a>
                </x-slot:actions>
            @endif
        </x-panel>

        {{-- Receivables --}}
        <x-panel :title="__('lang_v1.sales_payment_dues')" icon="wallet"
                 :count="$salesDues->count()" tone="danger" flush>
            @if ($salesDues->isEmpty())
                <x-empty-state icon="check-circle"
                               :title="__('lang_v1.nothing_outstanding')" compact/>
            @else
                <div class="table-wrap table-flush">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('lang_v1.customer') }}</th>
                                <th>{{ __('lang_v1.invoice_no') }}</th>
                                <th class="th-numeric">{{ __('lang_v1.due') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($salesDues as $sale)
                                <tr>
                                    <td class="cell-primary">
                                        {{ $sale->contact->full_name ?? __('lang_v1.walk_in_customer') }}
                                    </td>
                                    <td class="force-ltr">{{ $sale->invoice_no }}</td>
                                    <td class="cell-numeric font-semibold text-rose-700">
                                        @format_currency($sale->due)
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-panel>

        {{-- Payables --}}
        <x-panel :title="__('lang_v1.purchase_payment_dues')" icon="truck"
                 :count="$purchaseDues->count()" tone="danger" flush>
            @if ($purchaseDues->isEmpty())
                <x-empty-state icon="check-circle"
                               :title="__('lang_v1.nothing_outstanding')" compact/>
            @else
                <div class="table-wrap table-flush">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('lang_v1.supplier') }}</th>
                                <th>{{ __('lang_v1.reference_no') }}</th>
                                <th class="th-numeric">{{ __('lang_v1.due') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($purchaseDues as $purchase)
                                <tr>
                                    <td class="cell-primary">
                                        {{ $purchase->contact->full_name_with_business ?? '—' }}
                                    </td>
                                    <td class="force-ltr">{{ $purchase->ref_no }}</td>
                                    <td class="cell-numeric font-semibold text-rose-700">
                                        @format_currency($purchase->due)
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if (Route::has('purchases.index') && $purchaseDues->isNotEmpty())
                <x-slot:actions>
                    <a href="{{ route('purchases.index') }}" class="link text-xs">
                        {{ __('lang_v1.view_all') }}
                    </a>
                </x-slot:actions>
            @endif
        </x-panel>

        {{-- 30-day trend. Inline SVG sparkline: no chart library, and it prints. --}}
        @php
            $values = collect($salesTrend)->pluck('total');
            $peak = max(1, $values->max());
            $count = max(1, $values->count() - 1);
            $coords = $values->values()->map(
                fn ($value, $index) => round($index / $count * 100, 2)
                    .','.round(40 - ($value / $peak * 34), 2)
            );
            $points = $coords->implode(' ');
            /* Close the path along the baseline so the line reads as an area —
               a filled shape is easier to judge at a glance than a hairline. */
            $area = '0,40 '.$points.' 100,40';
        @endphp
        <x-panel :title="__('lang_v1.sales_last_30_days')" icon="chart">
            <x-slot:actions>
                <span class="font-mono text-sm font-bold tabular-nums text-slate-900 force-ltr">
                    @format_currency($values->sum())
                </span>
            </x-slot:actions>

            {{-- dir=ltr: a time axis always runs left→right, even in Arabic. --}}
            <svg viewBox="0 0 100 40" preserveAspectRatio="none" dir="ltr"
                 class="h-32 w-full" role="img"
                 aria-label="{{ __('lang_v1.sales_last_30_days') }}">
                <polygon points="{{ $area }}" fill="var(--color-brand-100)"/>
                <polyline points="{{ $points }}" fill="none"
                          stroke="var(--color-brand-600)" stroke-width="1.5"
                          stroke-linejoin="round" stroke-linecap="round"
                          vector-effect="non-scaling-stroke"/>
            </svg>
            <div class="mt-2 flex justify-between text-xs text-slate-500" dir="ltr">
                <span>@format_date($salesTrend[0]['date'])</span>
                <span>@format_date($salesTrend[count($salesTrend) - 1]['date'])</span>
            </div>
        </x-panel>
    </div>
</div>
@endsection
