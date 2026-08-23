@extends('layouts.app')
@section('title', __('lang_v1.sell_returns'))
@section('page_title', __('lang_v1.sell_returns'))

@section('content')

@php
    $isFiltered = collect(['search', 'location_id', 'contact_id'])
        ->contains(fn ($key) => request()->filled($key));
@endphp

{{-- No create action in the head, and that is not an omission: sell-return.create
     takes a {sell}, so a return can only begin from an invoice. The empty state
     below says so rather than leaving someone hunting for a button. --}}
<x-page-head :subtitle="trans_choice('lang_v1.record_count', $returns->total(), ['count' => $returns->total()])"/>

<form method="GET" class="filter-bar">
    <div class="filter-grid">
        <div class="field sm:col-span-2">
            <label for="search" class="label">{{ __('lang_v1.reference_no') }}</label>
            <div class="input-search-wrap">
                <span class="input-search-icon"><x-nav-icon name="search" :size="4"/></span>
                <input type="search" id="search" name="search" value="{{ request('search') }}"
                       class="input-search" dir="ltr">
            </div>
        </div>

        <div class="field">
            <label for="contact_id" class="label">{{ __('lang_v1.customer') }}</label>
            <select id="contact_id" name="contact_id" class="select">
                @foreach ($customers as $id => $name)
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

        <div class="flex items-end gap-2">
            <button type="submit" class="btn-primary">
                <x-nav-icon name="filter"/>
                {{ __('lang_v1.apply') }}
            </button>
            @if ($isFiltered)
                <a href="{{ route('sell-return.index') }}" class="btn-secondary">
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
                <th>{{ __('lang_v1.invoice_no') }}</th>
                <th>{{ __('lang_v1.customer') }}</th>
                <th>{{ __('lang_v1.location') }}</th>
                <th>{{ __('lang_v1.parent_sale') }}</th>
                <th>{{ __('lang_v1.payment_status') }}</th>
                <th class="th-numeric">{{ __('lang_v1.total') }}</th>
                <th class="th-numeric">{{ __('lang_v1.actions') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($returns as $return)
                <tr>
                    <td class="whitespace-nowrap">@format_date($return->transaction_date)</td>
                    <td class="force-ltr">
                        <a href="{{ route('sell-return.show', $return->id) }}" class="cell-link">
                            {{ $return->invoice_no }}
                        </a>
                    </td>
                    <td>{{ or_dash($return->contact->full_name_with_business ?? null) }}</td>
                    <td>{{ or_dash($return->location->name ?? null) }}</td>
                    {{-- The invoice this reverses. A link, because the next question
                         after "the customer brought back 3" is always "off which sale". --}}
                    <td class="force-ltr">
                        @if ($return->return_parent_sell)
                            <a href="{{ route('sells.show', $return->return_parent_sell->id) }}" class="link text-xs">
                                {{ $return->return_parent_sell->invoice_no }}
                            </a>
                        @else
                            {{ or_dash(null) }}
                        @endif
                    </td>
                    <td>@payment_status($return->payment_status)</td>
                    <td class="cell-numeric">@format_currency($return->final_total)</td>
                    <td>
                        <div class="cell-actions">
                            <a href="{{ route('sell-return.show', $return->id) }}"
                               class="btn-icon" title="{{ __('lang_v1.view') }}"
                               aria-label="{{ __('lang_v1.view') }}">
                                <x-nav-icon name="eye" :size="4"/>
                            </a>
                        </div>
                    </td>
                </tr>
            @empty
                <x-table-empty :columns="8"
                               :icon="$isFiltered ? 'search' : 'undo'"
                               :title="$isFiltered ? __('lang_v1.no_records_found') : __('lang_v1.nothing_here_yet')"
                               :text="$isFiltered ? __('lang_v1.nothing_matches_filters') : __('lang_v1.returns_start_from_a_sale')">
                    @if ($isFiltered)
                        <a href="{{ route('sell-return.index') }}" class="btn-secondary btn-sm">
                            {{ __('lang_v1.clear_filters') }}
                        </a>
                    @else
                        <a href="{{ route('sells.index') }}" class="btn-secondary btn-sm">
                            <x-nav-icon name="receipt" :size="4"/>
                            {{ __('lang_v1.sales') }}
                        </a>
                    @endif
                </x-table-empty>
            @endforelse
        </tbody>
    </table>

    {{ $returns->links() }}
</div>
@endsection
