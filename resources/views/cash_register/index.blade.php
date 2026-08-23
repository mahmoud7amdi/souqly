@extends('layouts.app')
@section('title', __('lang_v1.cash_register'))
@section('page_title', __('lang_v1.cash_register'))

@section('content')

@php
    $isFiltered = collect(['user_id', 'location_id', 'status', 'start_date', 'end_date'])
        ->contains(fn ($key) => request()->filled($key));

    /* The permission is the gate the close screens actually enforce; ownership
       only narrows it further. So a row gets a Close link when the viewer may
       close registers at all and the session is still open. */
    $mayClose = auth()->user()->can('close_cash_register');
@endphp

<x-page-head :subtitle="trans_choice('lang_v1.session_count', $registers->total(), ['count' => $registers->total()])">
    @if (empty($current))
        <a href="{{ route('cash-register.create') }}" class="btn-primary">
            <x-nav-icon name="plus"/>
            {{ __('lang_v1.open_register') }}
        </a>
    @endif
</x-page-head>

@if ($current)
    {{-- The open session gets a strip of its own rather than a row in the table
         below. A cashier arriving here mid-shift has exactly two things to do —
         go back to selling, or count the drawer — and both are one click from
         the top of the screen instead of found by scanning dates. --}}
    <div class="section flex flex-wrap items-center gap-4 rounded-2xl bg-brand-50 p-5 ring-1 ring-brand-600/10">
        <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-brand-600 text-white">
            <x-nav-icon name="cash" :size="5"/>
        </span>

        <div class="min-w-0 flex-1">
            <p class="font-semibold text-slate-900">{{ __('lang_v1.your_register_is_open') }}</p>
            <p class="mt-0.5 text-sm text-slate-600">
                {{ $current->location->name ?? __('lang_v1.all_locations') }}
                <span class="text-slate-300">·</span>
                {{ __('lang_v1.opened_at') }} @format_datetime($current->created_at)
            </p>
        </div>

        <a href="{{ route('pos.create') }}" class="btn-secondary">
            <x-nav-icon name="pos" :size="4"/>
            {{ __('lang_v1.back_to_selling') }}
        </a>

        @if ($mayClose)
            <a href="{{ route('cash-register.closeForm', $current->id) }}" class="btn-accent">
                <x-nav-icon name="calculator" :size="4"/>
                {{ __('lang_v1.close_register') }}
            </a>
        @else
            <a href="{{ route('cash-register.show', $current->id) }}" class="btn-primary">
                <x-nav-icon name="eye" :size="4"/>
                {{ __('lang_v1.view') }}
            </a>
        @endif
    </div>
@endif

<form method="GET" class="filter-bar">
    <div class="filter-grid">
        <div class="field">
            <label for="user_id" class="label">{{ __('lang_v1.cashier') }}</label>
            <select id="user_id" name="user_id" class="select">
                @foreach ($users as $id => $name)
                    <option value="{{ $id }}" @selected(request('user_id') == $id)>{{ $name }}</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="location_id" class="label">{{ __('lang_v1.location') }}</label>
            <select id="location_id" name="location_id" class="select">
                @foreach ($locations as $id => $name)
                    <option value="{{ $id }}" @selected(request('location_id') == $id)>{{ $name }}</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="status" class="label">{{ __('lang_v1.status') }}</label>
            <select id="status" name="status" class="select">
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}" @selected(request('status') === (string) $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="start_date" class="label">{{ __('lang_v1.from') }}</label>
            <input type="date" id="start_date" name="start_date" value="{{ request('start_date') }}" class="input">
        </div>

        <div class="field">
            <label for="end_date" class="label">{{ __('lang_v1.to') }}</label>
            <input type="date" id="end_date" name="end_date" value="{{ request('end_date') }}" class="input">
        </div>

        <div class="flex items-end gap-2">
            <button type="submit" class="btn-primary">
                <x-nav-icon name="filter"/>
                {{ __('lang_v1.apply') }}
            </button>
            @if ($isFiltered)
                <a href="{{ route('cash-register.index') }}" class="btn-secondary">
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
                <th>{{ __('lang_v1.opened') }}</th>
                <th>{{ __('lang_v1.cashier') }}</th>
                <th>{{ __('lang_v1.location') }}</th>
                <th class="th-numeric">{{ __('lang_v1.collected') }}</th>
                <th class="th-numeric">{{ __('lang_v1.counted') }}</th>
                <th>{{ __('lang_v1.status') }}</th>
                <th class="th-numeric">{{ __('lang_v1.actions') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($registers as $register)
                @php $isOpen = $register->status === 'open'; @endphp
                <tr>
                    <td class="whitespace-nowrap">
                        <a href="{{ route('cash-register.show', $register->id) }}" class="cell-link">
                            @format_datetime($register->created_at)
                        </a>
                        <span class="cell-meta force-ltr">#{{ $register->id }}</span>
                    </td>

                    <td>{{ or_dash($register->user->user_full_name ?? null) }}</td>

                    <td>{{ or_dash($register->location->name ?? null) }}</td>

                    <td class="cell-numeric">@format_currency($collected[$register->id] ?? 0)</td>

                    {{-- Blank rather than zero while the drawer is still open: it
                         has not been counted yet, and printing 0.00 would read as
                         a count that came out empty. --}}
                    <td class="cell-numeric">
                        {{ $isOpen ? '' : format_currency($register->closing_amount) }}
                    </td>

                    <td>
                        @if ($isOpen)
                            <span class="badge-success">{{ __('lang_v1.open') }}</span>
                        @else
                            <span class="badge-muted">{{ __('lang_v1.closed') }}</span>
                            @if ($register->closed_at)
                                <span class="cell-meta">@format_datetime($register->closed_at)</span>
                            @endif
                        @endif
                    </td>

                    <td>
                        <div class="cell-actions">
                            <a href="{{ route('cash-register.show', $register->id) }}"
                               class="btn-icon" title="{{ __('lang_v1.view') }}"
                               aria-label="{{ __('lang_v1.view') }}">
                                <x-nav-icon name="eye" :size="4"/>
                            </a>

                            @if ($isOpen && $mayClose)
                                <a href="{{ route('cash-register.closeForm', $register->id) }}"
                                   class="btn-icon" title="{{ __('lang_v1.close_register') }}"
                                   aria-label="{{ __('lang_v1.close_register') }}">
                                    <x-nav-icon name="calculator" :size="4"/>
                                </a>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <x-table-empty :columns="7"
                               :icon="$isFiltered ? 'search' : 'cash'"
                               :title="$isFiltered ? __('lang_v1.no_records_found') : __('lang_v1.nothing_here_yet')"
                               :text="$isFiltered ? __('lang_v1.nothing_matches_filters') : __('lang_v1.a_register_records_one_shift')">
                    @if ($isFiltered)
                        <a href="{{ route('cash-register.index') }}" class="btn-secondary btn-sm">
                            {{ __('lang_v1.clear_filters') }}
                        </a>
                    @elseif (empty($current))
                        <a href="{{ route('cash-register.create') }}" class="btn-primary btn-sm">
                            <x-nav-icon name="plus" :size="4"/>
                            {{ __('lang_v1.open_register') }}
                        </a>
                    @endif
                </x-table-empty>
            @endforelse
        </tbody>
    </table>

    {{ $registers->links() }}
</div>
@endsection
