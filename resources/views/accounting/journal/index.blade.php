@extends('layouts.app')
@section('title', __('accounting.journal'))
@section('page_title', __('accounting.journal'))

@section('content')

{{--
    The journal: one row per document, not per line.

    `journal_entries` has no header table — a document is the set of rows sharing a
    `transaction_number` — so a naive listing would show a two-line posting as two
    rows and the period total as twice its real value. `documentList()` groups in
    SQL; this screen is the drill-down list, and `journal.show` is where the lines
    live.

    The value column reads `document_total`, the alias the grouped query selects.
    Not `amount`: `JournalEntry` defines a `getAmountAttribute()` accessor, and an
    accessor wins over a selected column of the same name — so `->amount` here would
    silently render every document as zero.
--}}

@php
    $isFiltered = collect(['search', 'start_date', 'end_date', 'chart_of_account_id', 'cost_center_id', 'state'])
        ->contains(fn ($key) => request()->filled($key));

    $states = [
        '' => __('lang_v1.all'),
        'live' => __('accounting.state_live'),
        'reversed' => __('accounting.state_reversed'),
    ];

    /* Live lines only, per `journalTotals()`, so a mismatch here is a real one — a
       reversal cannot cause it. */
    $balanced = abs($totals['debit'] - $totals['credit']) < 0.0001;
@endphp

<x-page-head :subtitle="__('accounting.journal_subtitle')">
    @if ($canPost)
        <a href="{{ route('accounting.journal.create') }}" class="btn-primary">
            <x-nav-icon name="plus"/>
            {{ __('accounting.post_journal') }}
        </a>
    @endif
</x-page-head>

<div class="section">
    <div class="rise-group grid gap-4 sm:grid-cols-3">
        <x-stat :label="__('accounting.documents')"
                :value="format_quantity($totals['documents'])"
                icon="book"/>

        <x-stat :label="__('accounting.total_debit')"
                :value="format_currency($totals['debit'])"
                icon="arrow-forward"
                :tone="$balanced ? null : 'danger'"/>

        <x-stat :label="__('accounting.total_credit')"
                :value="format_currency($totals['credit'])"
                icon="arrow-back"
                :tone="$balanced ? null : 'danger'"
                :hint="$balanced ? __('accounting.books_balanced') : __('accounting.books_unbalanced')"/>
    </div>
</div>

<form method="GET" class="filter-bar">
    <div class="filter-grid">
        <div class="field">
            <label for="search" class="label">{{ __('lang_v1.search') }}</label>
            <div class="input-search-wrap">
                <span class="input-search-icon"><x-nav-icon name="search" :size="4"/></span>
                <input type="search" id="search" name="search" value="{{ request('search') }}"
                       class="input-search"
                       placeholder="{{ __('accounting.search_journal_placeholder') }}">
            </div>
        </div>

        <div class="field">
            <label for="start_date" class="label">{{ __('lang_v1.from') }}</label>
            <input type="date" id="start_date" name="start_date" class="input" value="{{ $range['start'] }}">
        </div>

        <div class="field">
            <label for="end_date" class="label">{{ __('lang_v1.to') }}</label>
            <input type="date" id="end_date" name="end_date" class="input" value="{{ $range['end'] }}">
        </div>

        <div class="field">
            <label for="chart_of_account_id" class="label">{{ __('lang_v1.account') }}</label>
            <select id="chart_of_account_id" name="chart_of_account_id" class="select">
                @foreach ($accounts as $id => $label)
                    <option value="{{ $id }}" @selected((string) request('chart_of_account_id') === (string) $id)>
                        {{ $label }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="cost_center_id" class="label">{{ __('accounting.cost_center') }}</label>
            <select id="cost_center_id" name="cost_center_id" class="select">
                @foreach ($costCenters as $id => $label)
                    <option value="{{ $id }}" @selected((string) request('cost_center_id') === (string) $id)>
                        {{ $label }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="state" class="label">{{ __('accounting.document_state') }}</label>
            <select id="state" name="state" class="select">
                @foreach ($states as $value => $label)
                    <option value="{{ $value }}" @selected(request('state') === (string) $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="flex items-end gap-2">
            <button type="submit" class="btn-primary">
                <x-nav-icon name="filter"/>
                {{ __('lang_v1.apply') }}
            </button>
            @if ($isFiltered)
                <a href="{{ route('accounting.journal.index') }}" class="btn-secondary">
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
                <th>{{ __('accounting.transaction_number') }}</th>
                <th>{{ __('lang_v1.name') }}</th>
                <th class="th-numeric">{{ __('accounting.document_lines') }}</th>
                <th class="th-numeric">{{ __('accounting.document_value') }}</th>
                <th>{{ __('accounting.document_state') }}</th>
                <th class="th-numeric">{{ __('lang_v1.actions') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($records as $document)
                <tr>
                    <td class="whitespace-nowrap">
                        <a href="{{ route('accounting.journal.show', $document->transaction_number) }}"
                           class="cell-link force-ltr">
                            {{ $document->transaction_number }}
                        </a>
                        <span class="cell-meta force-ltr">@format_date($document->date)</span>
                    </td>

                    <td>
                        <span class="cell-primary">{{ or_dash($document->name) }}</span>
                        @if ($document->reference)
                            <span class="cell-meta force-ltr">{{ $document->reference }}</span>
                        @endif
                    </td>

                    <td class="cell-numeric">
                        {{ __('accounting.n_lines', ['count' => format_quantity($document->line_count)]) }}
                    </td>

                    <td class="cell-numeric">@format_currency($document->document_total)</td>

                    <td>
                        <div class="flex flex-wrap items-center gap-1">
                            @if ($document->reversed)
                                <span class="badge-muted">{{ __('accounting.state_reversed') }}</span>
                            @else
                                <span class="badge-success">{{ __('accounting.state_live') }}</span>
                            @endif

                            {{-- `'transfer'`, not `'fund_transfer'`: the value is
                                 written by `AccountingService::transfer()`, which
                                 sets `transaction_sub_type` to `'transfer'`. The
                                 longer name reads better and matches nothing, so the
                                 badge simply never appeared. --}}
                            @if ($document->transaction_sub_type === 'transfer')
                                <span class="badge-info">{{ __('accounting.transfer') }}</span>
                            @elseif ($document->transaction_sub_type === 'reversal')
                                <span class="badge-warning">{{ __('accounting.reversal') }}</span>
                            @endif
                        </div>
                    </td>

                    {{-- View only. There is no edit, and no delete: a posted document
                         is corrected by reversing it, which happens on the document's
                         own screen where the lines being undone are visible. --}}
                    <td>
                        <div class="cell-actions">
                            <a href="{{ route('accounting.journal.show', $document->transaction_number) }}"
                               class="btn-icon" title="{{ __('accounting.view_document') }}"
                               aria-label="{{ __('accounting.view_document') }}">
                                <x-nav-icon name="eye" :size="4"/>
                            </a>
                        </div>
                    </td>
                </tr>
            @empty
                <x-table-empty :columns="6"
                               :icon="$isFiltered ? 'search' : 'book'"
                               :title="$isFiltered ? __('lang_v1.no_records_found') : __('accounting.no_documents_yet')"
                               :text="$isFiltered ? __('lang_v1.nothing_matches_filters') : __('accounting.no_documents_yet_desc')">
                    @if ($isFiltered)
                        <a href="{{ route('accounting.journal.index') }}" class="btn-secondary btn-sm">
                            {{ __('lang_v1.clear_filters') }}
                        </a>
                    @elseif ($canPost)
                        <a href="{{ route('accounting.journal.create') }}" class="btn-primary btn-sm">
                            <x-nav-icon name="plus" :size="4"/>
                            {{ __('accounting.post_journal') }}
                        </a>
                    @endif
                </x-table-empty>
            @endforelse
        </tbody>
    </table>

    {{ $records->links() }}
</div>
@endsection
