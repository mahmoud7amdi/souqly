@extends('layouts.app')
@section('title', __('lang_v1.payment_accounts'))
@section('page_title', __('lang_v1.payment_accounts'))

@section('content')

@php
    $showClosed = request()->boolean('show_closed');
    $isFiltered = request()->filled('search') || $showClosed;
@endphp

<x-page-head :subtitle="trans_choice('lang_v1.record_count', $accounts->total(), ['count' => $accounts->total()])">
    <a href="{{ route('accounts.create') }}" class="btn-primary">
        <x-nav-icon name="plus"/>
        {{ __('lang_v1.add_account') }}
    </a>
</x-page-head>

@if ($canSeeBalance)
    {{-- Qualified as "on this page", because it is: summing every account in the
         business behind a paginated list would print a figure the column below
         does not add up to, and the reader would trust the wrong one. --}}
    <div class="section">
        <div class="grid gap-4 sm:grid-cols-2">
            <x-stat :label="__('lang_v1.balance_on_this_page')"
                    :value="format_currency($pageTotal)"
                    icon="bank"
                    :hint="trans_choice('lang_v1.across_n_accounts', $accounts->count(), ['count' => $accounts->count()])"/>
        </div>
    </div>
@endif

<form method="GET" class="filter-bar">
    <div class="filter-grid">
        <div class="field">
            <label for="search" class="label">{{ __('lang_v1.search') }}</label>
            <div class="input-search-wrap">
                <span class="input-search-icon"><x-nav-icon name="search" :size="4"/></span>
                <input type="search" id="search" name="search" value="{{ request('search') }}"
                       class="input-search"
                       placeholder="{{ __('lang_v1.name_or_account_no') }}">
            </div>
        </div>

        <div class="field">
            <span class="label">{{ __('lang_v1.closed_accounts') }}</span>
            <div class="checkbox-row">
                <input type="checkbox" id="show_closed" name="show_closed" value="1"
                       class="checkbox" @checked($showClosed)>
                <label for="show_closed" class="checkbox-label">
                    {{ __('lang_v1.include_closed') }}
                </label>
            </div>
        </div>

        <div class="flex items-end gap-2">
            <button type="submit" class="btn-primary">
                <x-nav-icon name="filter"/>
                {{ __('lang_v1.apply') }}
            </button>
            @if ($isFiltered)
                <a href="{{ route('accounts.index') }}" class="btn-secondary">
                    <x-nav-icon name="x" :size="4"/>
                    {{ __('lang_v1.reset') }}
                </a>
            @endif
        </div>
    </div>
</form>

{{-- Movements are not offered here. Depositing needs the balance in front of you
     and transferring needs an unambiguous source, so both live on the account's
     own screen — one place where money moves, next to the figure it changes. --}}
<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>{{ __('lang_v1.account_name') }}</th>
                <th>{{ __('lang_v1.account_number') }}</th>
                <th>{{ __('lang_v1.account_type') }}</th>
                @if ($canSeeBalance)
                    <th class="th-numeric">{{ __('lang_v1.balance') }}</th>
                @endif
                <th class="th-numeric">{{ __('lang_v1.actions') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($accounts as $account)
                @php $balance = $balances[$account->id] ?? 0.0; @endphp
                <tr>
                    <td>
                        <a href="{{ route('accounts.show', $account->id) }}" class="cell-link">
                            {{ $account->name }}
                        </a>
                        @if ($account->is_closed)
                            <span class="badge-muted">{{ __('lang_v1.closed') }}</span>
                        @endif
                    </td>

                    <td><span class="force-ltr">{{ or_dash($account->account_number) }}</span></td>

                    {{-- Both kinds of "type" in one cell: the free catalogue type
                         (Bank, Till) and the fixed accounting kind (capital vs
                         current). Two columns would put a mostly-empty one beside
                         a mostly-repeating one. --}}
                    <td>
                        <span class="cell-primary">{{ or_dash($account->account_type_name) }}</span>
                        @if ($account->account_type === 'capital')
                            <span class="cell-meta">{{ __('lang_v1.capital') }}</span>
                        @endif
                    </td>

                    @if ($canSeeBalance)
                        {{-- A negative balance is an overdraft, which is a real
                             state rather than an error — so it is toned, not
                             flagged. --}}
                        <td @class(['cell-numeric', 'text-rose-600' => $balance < 0])>
                            @format_currency($balance)
                        </td>
                    @endif

                    <td>
                        <div class="cell-actions">
                            <a href="{{ route('accounts.show', $account->id) }}"
                               class="btn-icon" title="{{ __('lang_v1.view') }}"
                               aria-label="{{ __('lang_v1.view') }}">
                                <x-nav-icon name="eye" :size="4"/>
                            </a>

                            <a href="{{ route('accounts.edit', $account->id) }}"
                               class="btn-icon" title="{{ __('lang_v1.edit') }}"
                               aria-label="{{ __('lang_v1.edit') }}">
                                <x-nav-icon name="edit" :size="4"/>
                            </a>
                        </div>
                    </td>
                </tr>
            @empty
                <x-table-empty :columns="$canSeeBalance ? 5 : 4"
                               :icon="$isFiltered ? 'search' : 'bank'"
                               :title="$isFiltered ? __('lang_v1.no_records_found') : __('lang_v1.nothing_here_yet')"
                               :text="$isFiltered ? __('lang_v1.nothing_matches_filters') : __('lang_v1.accounts_are_where_money_sits')">
                    @if ($isFiltered)
                        <a href="{{ route('accounts.index') }}" class="btn-secondary btn-sm">
                            {{ __('lang_v1.clear_filters') }}
                        </a>
                    @else
                        <a href="{{ route('accounts.create') }}" class="btn-primary btn-sm">
                            <x-nav-icon name="plus" :size="4"/>
                            {{ __('lang_v1.add_account') }}
                        </a>
                    @endif
                </x-table-empty>
            @endforelse
        </tbody>
    </table>

    {{ $accounts->links() }}
</div>
@endsection
