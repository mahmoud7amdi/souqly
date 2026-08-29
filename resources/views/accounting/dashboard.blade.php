@extends('layouts.app')
@section('title', __('lang_v1.accounting'))
@section('page_title', __('lang_v1.accounting'))

@section('content')

{{--
    The accounting dashboard: what the books are worth, then what this period did
    to them.

    The order of the tiles is the order the questions get asked. The three balance
    sheet figures come first because they are true regardless of which dates are in
    the filter — an asset balance is a position, not a period. Income, expense and
    net come second because they are the only figures on the screen the date range
    actually moves, and putting them beside the positions would invite reading a
    month's income as a balance.

    The balance check is an alert rather than a tile because it is not a quantity.
    A tile reading "yes" is a worse way to say the books balance than saying
    nothing at all, so the healthy case is a quiet line and the broken case is loud.
--}}

@php
    $isFiltered = collect(['start_date', 'end_date'])->contains(fn ($key) => request()->filled($key));
@endphp

<x-page-head :subtitle="__('accounting.dashboard_subtitle')">
    @if ($canPost)
        <a href="{{ route('accounting.journal.create') }}" class="btn-primary">
            <x-nav-icon name="plus"/>
            {{ __('accounting.post_journal') }}
        </a>
    @endif
</x-page-head>

<form method="GET" class="filter-bar">
    <div class="filter-grid">
        <div class="field">
            <label for="start_date" class="label">{{ __('lang_v1.from') }}</label>
            <input type="date" id="start_date" name="start_date" class="input"
                   value="{{ $range['start'] }}">
        </div>

        <div class="field">
            <label for="end_date" class="label">{{ __('lang_v1.to') }}</label>
            <input type="date" id="end_date" name="end_date" class="input"
                   value="{{ $range['end'] }}">
        </div>

        <div class="flex items-end gap-2">
            <button type="submit" class="btn-primary">
                <x-nav-icon name="filter"/>
                {{ __('lang_v1.apply') }}
            </button>
            @if ($isFiltered)
                <a href="{{ route('accounting.dashboard') }}" class="btn-secondary">
                    <x-nav-icon name="x" :size="4"/>
                    {{ __('lang_v1.reset') }}
                </a>
            @endif
        </div>
    </div>
</form>

{{-- ============ Where the books stand ============ --}}
<div class="section">
    <div class="rise-group grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <x-stat :label="__('accounting.assets_balance')"
                :value="format_currency($totals['assets'])"
                icon="bank"/>

        <x-stat :label="__('accounting.liabilities_balance')"
                :value="format_currency($totals['liabilities'])"
                icon="scale"/>

        <x-stat :label="__('accounting.equity_balance')"
                :value="format_currency($totals['equity'])"
                icon="shield"/>
    </div>
</div>

{{-- ============ What the period did ============ --}}
<div class="section">
    <div class="rise-group grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat :label="__('accounting.period_income')"
                :value="format_currency($totals['income'])"
                icon="cash"/>

        <x-stat :label="__('accounting.period_expense')"
                :value="format_currency($totals['expense'])"
                icon="receipt"/>

        <x-stat :label="__('accounting.period_net')"
                :value="format_currency($totals['net'])"
                icon="calculator"
                :hint="__('accounting.income_minus_expense')"
                :tone="$totals['net'] < 0 ? 'danger' : ($totals['net'] > 0 ? 'success' : null)"/>

        <x-stat :label="__('accounting.accounts_in_chart')"
                :value="format_quantity($totals['accounts'])"
                icon="book"
                :hint="__('accounting.documents_posted').': '.format_quantity($totals['documents'])"/>
    </div>
</div>

{{-- The one place the module admits it can be wrong. `trialBalance()` computes this
     from the same movement map the report uses, so an alert here and a balanced
     report there cannot disagree. --}}
@if (! $totals['balanced'])
    <div class="alert-warning mb-6" role="alert">
        <x-nav-icon name="alert"/>
        <div class="min-w-0">
            <p class="font-semibold">{{ __('accounting.books_unbalanced') }}</p>
            <p class="mt-0.5">{{ __('accounting.books_unbalanced_desc') }}</p>
            <a href="{{ route('accounting.trial-balance', request()->query()) }}"
               class="btn-secondary btn-sm mt-3">
                {{ __('accounting.open_trial_balance') }}
                <x-nav-icon name="chevron-forward" :size="4"/>
            </a>
        </div>
    </div>
@endif

{{-- ============ The last ten documents ============
     Ten, not a page: this is the "did my posting land" panel, and the journal
     screen next door is where a ledger is actually read. --}}
<x-panel :title="__('accounting.recent_documents')" icon="book" :count="$recent->count()" flush>
    <x-slot:actions>
        <a href="{{ route('accounting.journal.index', request()->query()) }}" class="btn-secondary btn-sm">
            {{ __('accounting.open_journal') }}
            <x-nav-icon name="chevron-forward" :size="4"/>
        </a>
    </x-slot:actions>

    <div class="table-wrap table-flush">
        <table class="table">
            <thead>
                <tr>
                    <th>{{ __('accounting.transaction_number') }}</th>
                    <th>{{ __('lang_v1.name') }}</th>
                    <th class="th-numeric">{{ __('accounting.document_lines') }}</th>
                    <th class="th-numeric">{{ __('accounting.document_value') }}</th>
                    <th>{{ __('accounting.document_state') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($recent as $document)
                    <tr>
                        <td class="whitespace-nowrap">
                            <a href="{{ route('accounting.journal.show', $document->transaction_number) }}"
                               class="cell-link force-ltr">
                                {{ $document->transaction_number }}
                            </a>
                            <span class="cell-meta force-ltr">@format_date($document->date)</span>
                        </td>

                        <td>
                            {{ or_dash($document->name) }}
                            @if ($document->reference)
                                <span class="cell-meta force-ltr">{{ $document->reference }}</span>
                            @endif
                        </td>

                        <td class="cell-numeric">@format_quantity($document->line_count)</td>

                        <td class="cell-numeric">@format_currency($document->document_total)</td>

                        <td>
                            @if ($document->reversed)
                                <span class="badge-muted">{{ __('accounting.state_reversed') }}</span>
                            @elseif ($document->transaction_sub_type === 'transfer')
                                <span class="badge-info">{{ __('accounting.transfer') }}</span>
                            @else
                                <span class="badge-success">{{ __('accounting.state_live') }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <x-table-empty :columns="5" icon="book"
                                   :title="__('accounting.no_documents_in_period')"
                                   :text="__('accounting.no_documents_in_period_desc')">
                        @if ($canPost)
                            <a href="{{ route('accounting.journal.create') }}" class="btn-primary btn-sm">
                                <x-nav-icon name="plus" :size="4"/>
                                {{ __('accounting.post_journal') }}
                            </a>
                        @endif
                    </x-table-empty>
                @endforelse
            </tbody>
        </table>
    </div>
</x-panel>
@endsection
