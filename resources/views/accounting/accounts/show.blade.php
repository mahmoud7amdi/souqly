@extends('layouts.app')
@section('title', $record->name)
@section('page_title', __('accounting.chart_of_accounts').' — '.$record->name)

@section('content')

{{--
    One account, as the movements that made its balance.

    The entries are the body and the balance is the header, because an account is
    only meaningful as its history: a figure with nothing behind it cannot be
    checked, and checking is the whole point of a ledger.

    No period debit/credit tiles. `$entries` is a page of fifty, so summing it would
    state one page's movement as the period's — and the trial balance next door
    already answers that question for every account at once, correctly.
--}}

@php
    $isFiltered = collect(['start_date', 'end_date'])->contains(fn ($key) => request()->filled($key));

    $typeLabel = \App\Modules\Accounting\Models\ChartOfAccount::accountTypes()[$record->account_type]
        ?? $record->account_type;
@endphp

<x-page-head :title="$record->name" :back="route('accounting.accounts.index')"
             :backLabel="__('accounting.chart_of_accounts')">
    <x-slot:subtitle>
        <span class="inline-flex flex-wrap items-center gap-x-2 gap-y-1">
            @if ($record->gl_code)
                <span class="force-ltr">{{ $record->gl_code }}</span>
                <span class="text-slate-300">&middot;</span>
            @endif
            <span class="inline-flex flex-wrap items-center gap-1.5">
                <span class="badge-brand">{{ $typeLabel }}</span>

                @if (! $record->active)
                    <span class="badge-muted">{{ __('accounting.account_is_inactive') }}</span>
                @endif

                @if ($record->allow_manual)
                    <span class="badge-info">{{ __('accounting.allow_manual') }}</span>
                @endif
            </span>
        </span>
    </x-slot:subtitle>

    @if ($canEdit)
        <a href="{{ route('accounting.accounts.edit', $record->id) }}" class="btn-secondary">
            <x-nav-icon name="edit"/>
            {{ __('lang_v1.edit') }}
        </a>
    @endif
</x-page-head>

<div class="section">
    <div class="rise-group grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat :label="__('accounting.current_balance')"
                :value="format_currency($record->current_balance)"
                icon="scale"
                :tone="$record->current_balance < 0 ? 'danger' : null"/>

        <x-stat :label="__('lang_v1.opening_balance')"
                :value="format_currency($record->opening_balance)"
                icon="book"
                :hint="__('accounting.opening_balance_hint')"/>

        <x-stat :label="__('accounting.entries')"
                :value="format_quantity($entries->total())"
                icon="list"/>

        <x-stat :label="__('accounting.sub_accounts')"
                :value="format_quantity($children->count())"
                icon="layers"/>
    </div>
</div>

<form method="GET" class="filter-bar">
    <div class="filter-grid">
        <div class="field">
            <label for="start_date" class="label">{{ __('lang_v1.from') }}</label>
            <input type="date" id="start_date" name="start_date" class="input" value="{{ $range['start'] }}">
        </div>

        <div class="field">
            <label for="end_date" class="label">{{ __('lang_v1.to') }}</label>
            <input type="date" id="end_date" name="end_date" class="input" value="{{ $range['end'] }}">
        </div>

        <div class="flex items-end gap-2">
            <button type="submit" class="btn-primary">
                <x-nav-icon name="filter"/>
                {{ __('lang_v1.apply') }}
            </button>
            @if ($isFiltered)
                <a href="{{ route('accounting.accounts.show', $record->id) }}" class="btn-secondary">
                    <x-nav-icon name="x" :size="4"/>
                    {{ __('lang_v1.reset') }}
                </a>
            @endif
        </div>
    </div>
</form>

<div class="grid gap-6 lg:grid-cols-4">

    {{-- ============ The ledger ============ --}}
    <x-panel :title="__('accounting.account_ledger')" icon="book"
             :count="$entries->total()" class="lg:col-span-3" flush>
        <div class="table-wrap table-flush">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ __('accounting.transaction_number') }}</th>
                        <th>{{ __('lang_v1.name') }}</th>
                        <th class="th-numeric">{{ __('lang_v1.debit') }}</th>
                        <th class="th-numeric">{{ __('lang_v1.credit') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($entries as $entry)
                        <tr>
                            <td class="whitespace-nowrap">
                                <a href="{{ route('accounting.journal.show', $entry->transaction_number) }}"
                                   class="cell-link force-ltr">
                                    {{ $entry->transaction_number }}
                                </a>
                                <span class="cell-meta force-ltr">@format_date($entry->date)</span>
                            </td>

                            <td>
                                <span class="cell-primary">{{ or_dash($entry->name) }}</span>
                                <span class="cell-meta">
                                    {{ collect([
                                        $entry->reference,
                                        $entry->cost_center?->name,
                                        $entry->business_location->name ?? null,
                                    ])->filter()->join(' · ') }}
                                    @if ($entry->reversed)
                                        <span class="badge-muted">{{ __('accounting.reversed') }}</span>
                                    @endif
                                </span>
                            </td>

                            {{-- A line carries a value on one side only, so the empty
                                 side is left blank rather than printed as a zero.
                                 A column of zeros beside a column of figures makes
                                 the reader scan twice for which side the entry is
                                 actually on. --}}
                            <td class="cell-numeric">
                                @if ((float) $entry->debit > 0)
                                    @format_currency($entry->debit)
                                @endif
                            </td>

                            <td class="cell-numeric">
                                @if ((float) $entry->credit > 0)
                                    @format_currency($entry->credit)
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-table-empty :columns="4" icon="book"
                                       :title="__('accounting.no_entries_in_period')"
                                       :text="__('accounting.no_entries_in_period_desc')"/>
                    @endforelse
                </tbody>
            </table>

            {{ $entries->links() }}
        </div>
    </x-panel>

    <div class="grid gap-6 self-start">

        {{-- ============ Sub-accounts ============ --}}
        @if ($children->isNotEmpty())
            <x-panel :title="__('accounting.sub_accounts')" icon="layers" :count="$children->count()">
                @foreach ($children as $child)
                    <div @class(['mt-4 border-t border-slate-200 pt-4' => ! $loop->first])>
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <a href="{{ route('accounting.accounts.show', $child->id) }}" class="cell-link">
                                    {{ $child->name }}
                                </a>
                                @if ($child->gl_code)
                                    <p class="cell-meta force-ltr">{{ $child->gl_code }}</p>
                                @endif
                            </div>
                            @if (! $child->active)
                                <span class="badge-muted">{{ __('accounting.account_is_inactive') }}</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </x-panel>
        @endif

        {{-- ============ The paperwork ============ --}}
        <x-panel :title="__('lang_v1.details')" icon="clipboard">
            <x-attr-list :items="[
                'accounting.gl_code' => $record->gl_code,
                'accounting.account_type' => $typeLabel,
                'accounting.parent_account' => $record->parent_id ? $record->parent->name : null,
                'lang_v1.opening_balance' => format_currency($record->opening_balance),
                'accounting.current_balance' => format_currency($record->current_balance),
                'lang_v1.created_on' => $record->created_at?->format('Y-m-d'),
            ]"/>

            @if ($record->notes)
                <p class="mt-5 border-t border-slate-200 pt-4 text-sm text-slate-600">{{ $record->notes }}</p>
            @endif
        </x-panel>
    </div>
</div>
@endsection
