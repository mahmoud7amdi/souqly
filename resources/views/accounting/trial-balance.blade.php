@extends('layouts.app')
@section('title', __('accounting.trial_balance'))
@section('page_title', __('accounting.trial_balance'))

@section('content')

{{--
    The trial balance: opening, movement, closing, per account.

    Five columns rather than the classic two, because the two-column form only tells
    you whether the books balance and this one tells you where a figure came from.
    Opening plus debit minus credit equals closing on every row, so a wrong closing
    can be traced along the row it sits in rather than by re-running the ledger.

    Opening and closing are signed debit-positive — `openingAsDebit()` negates the
    credit-natured types deliberately, so that the column can net to zero across the
    chart. `signed_as_debit_note` says so on screen, because a liability shown as
    -50,000 is otherwise read as a data error.

    The filter bar is hand-rolled rather than reusing `<x-report-filters>`. That
    component has no cost-centre field and builds a `reports.export` URL
    unconditionally, and the trial balance is not one of the exportable reports — so
    it would need both a new field and an opt-out, changing a component five
    committed report screens already depend on. The other four accounting screens
    hand-roll their filters too; this stays consistent with its own tranche.

    Two imbalances are reported separately because they have different causes and
    different fixes. `balanced` failing means a document was written outside the
    posting service. `opening_balanced` failing means the chart was carried in out
    of balance, which is a data-entry matter and does not implicate the period at
    all.
--}}

@php
    $isFiltered = collect(['start_date', 'end_date', 'cost_center_id'])
        ->contains(fn ($key) => request()->filled($key));

    $rows = $report['rows'];
    $totals = $report['totals'];

    /* A cost-centre filter counts only the lines tagged to that centre, so the two
       sides genuinely need not agree — the imbalance alert would be crying wolf.
       `cost_center_filter_note` explains it instead. */
    $byCostCenter = request()->filled('cost_center_id');

    $columnCount = 5;
@endphp

<x-page-head :subtitle="__('accounting.trial_balance_subtitle')">
    <button type="button" onclick="window.print()" class="btn-secondary">
        <x-nav-icon name="printer"/>
        {{ __('lang_v1.print') }}
    </button>
</x-page-head>

@if (! $report['balanced'] && ! $byCostCenter)
    <div class="alert-danger mb-6" role="alert">
        <x-nav-icon name="alert"/>
        <div class="min-w-0">
            <p class="font-semibold">{{ __('accounting.books_unbalanced') }}</p>
            <p class="mt-0.5">{{ __('accounting.books_unbalanced_desc') }}</p>
        </div>
    </div>
@endif

@if ($byCostCenter)
    <div class="alert-info mb-6" role="note">
        <x-nav-icon name="info"/>
        <div class="min-w-0">
            <p>{{ __('accounting.cost_center_filter_note') }}</p>
        </div>
    </div>
@endif

@if (! $report['opening_balanced'])
    <div class="alert-warning mb-6" role="alert">
        <x-nav-icon name="alert"/>
        <div class="min-w-0">
            <p class="font-semibold">{{ __('accounting.opening') }}</p>
            <p class="mt-0.5">{{ __('accounting.opening_not_balanced') }}</p>
        </div>
    </div>
@endif

<div class="section">
    <div class="rise-group grid gap-4 sm:grid-cols-3">
        <x-stat :label="__('accounting.total_debit')" :value="format_currency($totals['debit'])"
                icon="arrow-forward"/>

        <x-stat :label="__('accounting.total_credit')" :value="format_currency($totals['credit'])"
                icon="arrow-back"
                :tone="$report['balanced'] || $byCostCenter ? null : 'danger'"
                :hint="$report['balanced'] ? __('accounting.books_balanced') : __('accounting.books_unbalanced')"/>

        {{-- Counts both: the rows on screen, and the whole chart behind them. The
             service drops accounts with no opening balance and no movement, and a
             tile reading only the visible number would make the chart look smaller
             than it is. --}}
        <x-stat :label="__('accounting.accounts_with_movement')"
                :value="format_quantity($rows->count())" icon="book"
                :hint="__('accounting.total_accounts').': '.format_quantity($accountCount)"/>
    </div>
</div>

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

        <div class="field">
            <label for="cost_center_id" class="label">{{ __('accounting.cost_center') }}</label>
            <select id="cost_center_id" name="cost_center_id" class="select">
                @foreach ($costCenters as $id => $label)
                    <option value="{{ $id }}"
                            @selected((string) request('cost_center_id') === (string) $id)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="flex items-end gap-2">
            <button type="submit" class="btn-primary">
                <x-nav-icon name="filter"/>
                {{ __('lang_v1.apply') }}
            </button>
            @if ($isFiltered)
                <a href="{{ route('accounting.trial-balance') }}" class="btn-secondary">
                    <x-nav-icon name="x" :size="4"/>
                    {{ __('lang_v1.reset') }}
                </a>
            @endif
        </div>
    </div>
</form>

<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>{{ __('lang_v1.account') }}</th>
                <th class="th-numeric">{{ __('accounting.opening') }}</th>
                <th class="th-numeric">{{ __('lang_v1.debit') }}</th>
                <th class="th-numeric">{{ __('lang_v1.credit') }}</th>
                <th class="th-numeric">{{ __('accounting.closing') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                @php $account = $row['account']; @endphp
                <tr>
                    <td>
                        <a href="{{ route('accounting.accounts.show', $account->id) }}" class="cell-link">
                            {{ $account->name }}
                        </a>
                        <span class="cell-meta">
                            <span class="force-ltr">{{ $account->gl_code }}</span>
                            {{-- The map, not `__('accounting.type_'.$type)`. Account
                                 types are keyed bare in the lang file — `asset`,
                                 `liability` — and only cost-centre types carry the
                                 `type_` prefix, so the interpolated key would miss
                                 and print the key itself. The file's own header
                                 records that exact bug happening once already. --}}
                            <span class="badge-muted">
                                {{ \App\Modules\Accounting\Models\ChartOfAccount::accountTypes()[$account->account_type] ?? or_dash($account->account_type) }}
                            </span>
                        </span>
                    </td>

                    {{-- Signed, and negative means the credit side. `text-rose-700`
                         is deliberately *not* used for a negative here — a credit
                         balance on a liability is the normal state of that account,
                         not a fault, and colouring it red would report every
                         healthy balance sheet as broken. --}}
                    <td class="cell-numeric">
                        @if ($row['opening'] != 0.0)
                            @format_currency($row['opening'])
                        @endif
                    </td>

                    {{-- Blank rather than zero on an untouched side, the same reading
                         the account ledger and the document view already use. --}}
                    <td class="cell-numeric">
                        @if ($row['debit'] != 0.0)
                            @format_currency($row['debit'])
                        @endif
                    </td>

                    <td class="cell-numeric">
                        @if ($row['credit'] != 0.0)
                            @format_currency($row['credit'])
                        @endif
                    </td>

                    <td class="cell-numeric font-semibold">@format_currency($row['closing'])</td>
                </tr>
            @empty
                <x-table-empty :columns="$columnCount" icon="book"
                               :title="__('accounting.no_movement')"
                               :text="__('accounting.no_movement_desc')">
                    @if ($isFiltered)
                        <a href="{{ route('accounting.trial-balance') }}" class="btn-secondary btn-sm">
                            {{ __('lang_v1.clear_filters') }}
                        </a>
                    @endif
                </x-table-empty>
            @endforelse
        </tbody>

        @if ($rows->isNotEmpty())
            <tfoot>
                <tr>
                    <th class="text-end">{{ __('lang_v1.total') }}</th>
                    <td @class(['cell-numeric', 'font-semibold', 'text-rose-700' => ! $report['opening_balanced']])>
                        @format_currency($totals['opening'])
                    </td>
                    <td class="cell-numeric font-semibold">@format_currency($totals['debit'])</td>
                    <td @class(['cell-numeric', 'font-semibold', 'text-rose-700' => ! $report['balanced'] && ! $byCostCenter])>
                        @format_currency($totals['credit'])
                    </td>
                    <td class="cell-numeric font-semibold">@format_currency($totals['closing'])</td>
                </tr>
            </tfoot>
        @endif
    </table>
</div>

{{-- Both notes below the table rather than above it: they explain how to read a
     figure, which is a question you have after seeing one. --}}
<div class="section">
    <div class="grid gap-4 lg:grid-cols-2">
        <x-panel :title="__('lang_v1.how_this_works')" icon="info" quiet>
            <p class="text-sm text-slate-600">{{ __('accounting.trial_balance_note') }}</p>
        </x-panel>

        <x-panel :title="__('accounting.opening').' / '.__('accounting.closing')" icon="scale" quiet>
            <p class="text-sm text-slate-600">{{ __('accounting.signed_as_debit_note') }}</p>
        </x-panel>
    </div>
</div>
@endsection
