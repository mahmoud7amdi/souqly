@extends('layouts.app')
@section('title', __('lang_v1.'.$type))
@section('page_title', __('lang_v1.'.$type))

@section('content')

@php
    /* Every filter counts, not just the search box: a clerk who narrowed to one
       supplier and got nothing needs "nothing matches", not "add your first
       purchase". Supplier and location default to a real id rather than "all",
       so they only count as a filter once the request carries them. */
    $isFiltered = collect(['search', 'contact_id', 'location_id', 'status', 'start_date'])
        ->contains(fn ($key) => request()->filled($key));
@endphp

<x-page-head :subtitle="trans_choice('lang_v1.record_count', $documents->total(), ['count' => $documents->total()])">
    @if ($canCreate)
        <a href="{{ route($prefix.'.create') }}" class="btn-primary">
            <x-nav-icon name="plus"/>
            {{ __('lang_v1.add') }}
        </a>
    @endif
</x-page-head>

{{-- Total, paid, due — in that order, so the eye lands on what is still owed
     last. Only due is toned, and only when it is not zero. --}}
<div class="section">
    <div class="grid gap-4 sm:grid-cols-3">
        <x-stat :label="__('lang_v1.total')" :value="format_currency($totals['total'])" icon="truck"/>
        <x-stat :label="__('lang_v1.paid')" :value="format_currency($totals['paid'])" icon="check-circle"/>
        <x-stat :label="__('lang_v1.due')"
                :value="format_currency($totals['due'])"
                icon="wallet"
                :tone="$totals['due'] > 0 ? 'danger' : null"/>
    </div>
</div>

<form method="GET" class="filter-bar">
    <div class="filter-grid">
        <div class="field">
            <label for="search" class="label">{{ __('lang_v1.reference_no') }}</label>
            <div class="input-search-wrap">
                <span class="input-search-icon"><x-nav-icon name="search" :size="4"/></span>
                <input type="search" id="search" name="search" value="{{ request('search') }}"
                       class="input-search" dir="ltr">
            </div>
        </div>

        <div class="field">
            <label for="contact_id" class="label">{{ __('lang_v1.supplier') }}</label>
            <select id="contact_id" name="contact_id" class="select">
                @foreach ($suppliers as $id => $name)
                    <option value="{{ $id }}" @selected(request('contact_id') == $id)>{{ $name }}</option>
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
                <option value="">{{ __('lang_v1.all') }}</option>
                @foreach ($statuses as $value => $name)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $name }}</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="start_date" class="label">{{ __('lang_v1.from') }}</label>
            <input type="date" id="start_date" name="start_date" value="{{ request('start_date') }}" class="input">
        </div>

        <div class="flex items-end gap-2">
            <button type="submit" class="btn-primary">
                <x-nav-icon name="filter"/>
                {{ __('lang_v1.apply') }}
            </button>
            @if ($isFiltered)
                <a href="{{ route($prefix.'.index') }}" class="btn-secondary">
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
                <th>{{ __('lang_v1.date') }}</th>
                <th>{{ __('lang_v1.reference_no') }}</th>
                <th>{{ __('lang_v1.supplier') }}</th>
                <th>{{ __('lang_v1.location') }}</th>
                <th>{{ __('lang_v1.status') }}</th>
                <th>{{ __('lang_v1.payment_status') }}</th>
                <th class="th-numeric">{{ __('lang_v1.total') }}</th>
                <th class="th-numeric">{{ __('lang_v1.due') }}</th>
                <th class="th-numeric">{{ __('lang_v1.actions') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($documents as $document)
                <tr>
                    <td class="whitespace-nowrap">@format_date($document->transaction_date)</td>
                    <td class="force-ltr">
                        <a href="{{ route($prefix.'.show', $document->id) }}" class="cell-link">
                            {{ $document->ref_no }}
                        </a>
                    </td>
                    <td>{{ or_dash($document->contact->full_name_with_business ?? null) }}</td>
                    <td>{{ or_dash($document->location->name ?? null) }}</td>
                    <td>@transaction_status($document->status)</td>
                    <td>@payment_status($document->payment_status)</td>
                    <td class="cell-numeric">@format_currency($document->final_total)</td>
                    <td @class(['cell-numeric', 'font-semibold text-rose-600' => $document->due_amount > 0])>
                        @format_currency($document->due_amount)
                    </td>
                    <td>
                        <div class="cell-actions">
                            <a href="{{ route($prefix.'.show', $document->id) }}"
                               class="btn-icon" title="{{ __('lang_v1.view') }}"
                               aria-label="{{ __('lang_v1.view') }}">
                                <x-nav-icon name="eye" :size="4"/>
                            </a>
                            @if ($canUpdate)
                                <a href="{{ route($prefix.'.edit', $document->id) }}"
                                   class="btn-icon" title="{{ __('lang_v1.edit') }}"
                                   aria-label="{{ __('lang_v1.edit') }}">
                                    <x-nav-icon name="edit" :size="4"/>
                                </a>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <x-table-empty :columns="9"
                               :icon="$isFiltered ? 'search' : 'truck'"
                               :title="$isFiltered ? __('lang_v1.no_records_found') : __('lang_v1.nothing_here_yet')"
                               :text="$isFiltered ? __('lang_v1.nothing_matches_filters') : null">
                    @if ($isFiltered)
                        <a href="{{ route($prefix.'.index') }}" class="btn-secondary btn-sm">
                            {{ __('lang_v1.clear_filters') }}
                        </a>
                    @elseif ($canCreate)
                        <a href="{{ route($prefix.'.create') }}" class="btn-primary btn-sm">
                            <x-nav-icon name="plus" :size="4"/>
                            {{ __('lang_v1.add') }}
                        </a>
                    @endif
                </x-table-empty>
            @endforelse
        </tbody>
    </table>

    {{ $documents->links() }}
</div>
@endsection
