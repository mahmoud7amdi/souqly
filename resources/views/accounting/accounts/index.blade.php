@extends('layouts.app')
@section('title', __('accounting.chart_of_accounts'))
@section('page_title', __('accounting.chart_of_accounts'))

@section('content')

{{--
    The chart of accounts.

    Sorted by type then code, which is how a chart is printed and read — the five
    types are a structure, not a filter, so grouping asset accounts away from
    liability accounts is the listing's job rather than the reader's.

    The balance column comes from `ChartOfAccount::getCurrentBalanceAttribute()`,
    which is two sums per row. That is deliberate and it is why the page is
    paginated at fifty: the accessor owns the sign rule for all five account types,
    and a joined grouped query would have to restate it. Two copies of a sign rule
    is how a screen comes to disagree with its own totals.
--}}

@php
    $isFiltered = collect(['search', 'account_type', 'state'])
        ->contains(fn ($key) => request()->filled($key));

    $canEdit = auth()->user()->can('accounting.chart_of_accounts.create');

    $columnCount = 5 + (int) $canEdit;
@endphp

<x-page-head :subtitle="__('accounting.chart_of_accounts_subtitle')">
    @if ($canEdit)
        <a href="{{ route('accounting.accounts.create') }}" class="btn-primary">
            <x-nav-icon name="plus"/>
            {{ __('accounting.add_account') }}
        </a>
    @endif
</x-page-head>

<div class="section">
    <div class="rise-group grid gap-4 sm:grid-cols-3">
        <x-stat :label="__('accounting.total_accounts')"
                :value="format_quantity($totals['total'])"
                icon="book"/>

        <x-stat :label="__('accounting.active_accounts')"
                :value="format_quantity($totals['active'])"
                icon="check-circle"/>

        <x-stat :label="__('accounting.manual_accounts')"
                :value="format_quantity($totals['manual'])"
                icon="edit"
                :hint="__('accounting.allow_manual_hint')"/>
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
                       placeholder="{{ __('accounting.search_accounts_placeholder') }}">
            </div>
        </div>

        <div class="field">
            <label for="account_type" class="label">{{ __('accounting.account_type') }}</label>
            <select id="account_type" name="account_type" class="select">
                @foreach ($types as $value => $label)
                    <option value="{{ $value }}" @selected(request('account_type') === (string) $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="state" class="label">{{ __('accounting.account_state') }}</label>
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
                <a href="{{ route('accounting.accounts.index') }}" class="btn-secondary">
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
                <th>{{ __('accounting.gl_code') }}</th>
                <th>{{ __('lang_v1.name') }}</th>
                <th>{{ __('accounting.account_type') }}</th>
                <th class="th-numeric">{{ __('lang_v1.opening_balance') }}</th>
                <th class="th-numeric">{{ __('accounting.current_balance') }}</th>
                @if ($canEdit)
                    <th class="th-numeric">{{ __('lang_v1.actions') }}</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse ($records as $account)
                <tr>
                    <td class="whitespace-nowrap force-ltr">{{ or_dash($account->gl_code) }}</td>

                    <td>
                        <a href="{{ route('accounting.accounts.show', $account->id) }}" class="cell-link">
                            {{ $account->name }}
                        </a>
                        <span class="cell-meta">
                            @if ($account->parent_id)
                                <span class="icon-directional inline-block">↳</span>
                                {{ $account->parent->name }}
                            @endif
                            @if (! $account->active)
                                <span class="badge-muted">{{ __('accounting.account_is_inactive') }}</span>
                            @endif
                            @if ($account->allow_manual)
                                <span class="badge-info">{{ __('accounting.allow_manual') }}</span>
                            @endif
                        </span>
                    </td>

                    <td>
                        <span class="badge-brand">
                            {{ \App\Modules\Accounting\Models\ChartOfAccount::accountTypes()[$account->account_type] ?? or_dash($account->account_type) }}
                        </span>
                    </td>

                    <td class="cell-numeric">@format_currency($account->opening_balance)</td>

                    {{-- Coloured only when negative, and only relative to the
                         account's own natural side: the accessor has already applied
                         the sign rule, so a negative here means an asset account in
                         credit or an income account in debit — both worth a second
                         look, neither an error the screen can decide about. --}}
                    <td @class(['cell-numeric', 'text-rose-700' => $account->current_balance < 0])>
                        @format_currency($account->current_balance)
                    </td>

                    @if ($canEdit)
                        <td>
                            <div class="cell-actions">
                                <a href="{{ route('accounting.accounts.edit', $account->id) }}"
                                   class="btn-icon" title="{{ __('lang_v1.edit') }}"
                                   aria-label="{{ __('lang_v1.edit') }}">
                                    <x-nav-icon name="edit" :size="4"/>
                                </a>

                                <form method="POST" action="{{ route('accounting.accounts.destroy', $account->id) }}"
                                      data-confirm="{{ __('lang_v1.confirm_delete') }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn-icon-danger"
                                            title="{{ __('lang_v1.delete') }}"
                                            aria-label="{{ __('lang_v1.delete') }}">
                                        <x-nav-icon name="trash" :size="4"/>
                                    </button>
                                </form>
                            </div>
                        </td>
                    @endif
                </tr>
            @empty
                <x-table-empty :columns="$columnCount"
                               :icon="$isFiltered ? 'search' : 'book'"
                               :title="$isFiltered ? __('lang_v1.no_records_found') : __('accounting.no_accounts_yet')"
                               :text="$isFiltered ? __('lang_v1.nothing_matches_filters') : __('accounting.no_accounts_yet_desc')">
                    @if ($isFiltered)
                        <a href="{{ route('accounting.accounts.index') }}" class="btn-secondary btn-sm">
                            {{ __('lang_v1.clear_filters') }}
                        </a>
                    @elseif ($canEdit)
                        <a href="{{ route('accounting.accounts.create') }}" class="btn-primary btn-sm">
                            <x-nav-icon name="plus" :size="4"/>
                            {{ __('accounting.add_account') }}
                        </a>
                    @endif
                </x-table-empty>
            @endforelse
        </tbody>
    </table>

    {{ $records->links() }}
</div>
@endsection
