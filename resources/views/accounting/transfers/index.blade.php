@extends('layouts.app')
@section('title', __('accounting.transfers'))
@section('page_title', __('accounting.transfers'))

@section('content')

{{--
    Fund transfers.

    A transfer is not a document type of its own — `AccountingService::transfer()`
    posts an ordinary two-line journal entry and writes a `transfers` row pointing at
    it. So the number in the first column is a journal document number and it links
    to the journal, which is where the two legs and their dates actually live.

    The date column is `created_at`, deliberately labelled as when the transfer was
    *recorded*: the `transfers` table has no date of its own, and the accounting date
    the clerk typed belongs to the journal lines. Showing `created_at` under a heading
    that said "date" would quietly present the wrong one on any back-dated transfer.

    The tile total is all-time, not a period figure — there is no date filter on this
    screen, so a heading implying one would be a lie about what is being summed.
--}}

@php
    $isFiltered = request()->filled('search');
    $columnCount = 5;
@endphp

<x-page-head :subtitle="__('accounting.transfers_subtitle')">
    @if ($canTransfer)
        <a href="{{ route('accounting.transfers.create') }}" class="btn-primary">
            <x-nav-icon name="transfer"/>
            {{ __('accounting.new_transfer') }}
        </a>
    @endif
</x-page-head>

<div class="section">
    <div class="rise-group grid gap-4 sm:grid-cols-2">
        <x-stat :label="__('accounting.documents')" :value="format_quantity($records->total())" icon="transfer"/>
        <x-stat :label="__('accounting.total_transferred')" :value="format_currency($total)" icon="coins"/>
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
                       placeholder="{{ __('accounting.search_transfers_placeholder') }}">
            </div>
        </div>

        <div class="flex items-end gap-2">
            <button type="submit" class="btn-primary">
                <x-nav-icon name="filter"/>
                {{ __('lang_v1.apply') }}
            </button>
            @if ($isFiltered)
                <a href="{{ route('accounting.transfers.index') }}" class="btn-secondary">
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
                <th>{{ __('accounting.from_account') }}</th>
                <th>{{ __('accounting.to_account') }}</th>
                <th class="th-numeric">{{ __('lang_v1.amount') }}</th>
                <th>{{ __('accounting.transferred_by') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($records as $transfer)
                <tr>
                    <td class="whitespace-nowrap">
                        <a href="{{ route('accounting.journal.show', $transfer->journal_transaction_number) }}"
                           class="cell-link force-ltr">
                            {{ $transfer->journal_transaction_number }}
                        </a>
                        <span class="cell-meta">
                            {{ __('lang_v1.recorded_on') }}
                            <span class="force-ltr">@format_date($transfer->created_at)</span>
                        </span>
                    </td>

                    {{-- `transfer_from`/`transfer_to`/`transfer_by` are plain
                         BelongsTo with no withDefault(), so every one needs `?->`.
                         A transfer whose account was hard-deleted from the database
                         would otherwise take the whole screen down. --}}
                    <td>
                        <span class="cell-primary">{{ or_dash($transfer->transfer_from?->name) }}</span>
                        <span class="cell-meta force-ltr">{{ $transfer->transfer_from?->gl_code }}</span>
                    </td>

                    <td>
                        <span class="cell-primary">{{ or_dash($transfer->transfer_to?->name) }}</span>
                        <span class="cell-meta force-ltr">{{ $transfer->transfer_to?->gl_code }}</span>
                    </td>

                    <td class="cell-numeric">@format_currency($transfer->amount)</td>

                    <td>{{ or_dash($transfer->transfer_by?->user_full_name) }}</td>
                </tr>
            @empty
                <x-table-empty :columns="$columnCount"
                               :icon="$isFiltered ? 'search' : 'transfer'"
                               :title="$isFiltered ? __('lang_v1.no_records_found') : __('accounting.no_transfers_yet')"
                               :text="$isFiltered ? __('lang_v1.nothing_matches_filters') : __('accounting.no_transfers_yet_desc')">
                    @if ($isFiltered)
                        <a href="{{ route('accounting.transfers.index') }}" class="btn-secondary btn-sm">
                            {{ __('lang_v1.clear_filters') }}
                        </a>
                    @elseif ($canTransfer)
                        <a href="{{ route('accounting.transfers.create') }}" class="btn-primary btn-sm">
                            <x-nav-icon name="transfer" :size="4"/>
                            {{ __('accounting.new_transfer') }}
                        </a>
                    @endif
                </x-table-empty>
            @endforelse
        </tbody>
    </table>

    {{ $records->links() }}
</div>
@endsection
