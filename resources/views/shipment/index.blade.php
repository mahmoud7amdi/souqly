@extends('layouts.app')
@section('title', __('lang_v1.shipments'))
@section('page_title', __('lang_v1.shipments'))

@section('content')

@php
    $isFiltered = collect(['search', 'location_id', 'shipping_status', 'delivery_person'])
        ->contains(fn ($key) => request()->filled($key));
@endphp

{{-- No create action: a shipment is a sale seen through its shipping columns, so
     it starts by giving a sale a shipping status on the sale's own screen. --}}
<x-page-head>
    <x-slot:subtitle>
        <span class="inline-flex flex-wrap items-center gap-x-2 gap-y-1">
            <span>{{ trans_choice('lang_v1.record_count', $shipments->total(), ['count' => $shipments->total()]) }}</span>
            <span class="text-slate-300">&middot;</span>
            {{-- Said out loud, because this list is ordered the opposite way from
                 every other one in the app and would otherwise read as a bug. --}}
            <span>{{ __('lang_v1.shipment_queue_hint') }}</span>
        </span>
    </x-slot:subtitle>
</x-page-head>

<form method="GET" class="filter-bar">
    <div class="filter-grid">
        <div class="field sm:col-span-2">
            <label for="search" class="label">{{ __('lang_v1.search') }}</label>
            <div class="input-search-wrap">
                <span class="input-search-icon"><x-nav-icon name="search" :size="4"/></span>
                <input type="search" id="search" name="search" value="{{ request('search') }}"
                       class="input-search">
            </div>
        </div>

        <div class="field">
            <label for="shipping_status" class="label">{{ __('lang_v1.shipping_status') }}</label>
            <select id="shipping_status" name="shipping_status" class="select">
                @foreach ($shippingStatuses as $value => $name)
                    <option value="{{ $value }}" @selected(request('shipping_status') === (string) $value)>
                        {{ $name }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="delivery_person" class="label">{{ __('lang_v1.delivery_person') }}</label>
            <select id="delivery_person" name="delivery_person" class="select">
                @foreach ($deliveryPeople as $id => $name)
                    <option value="{{ $id }}" @selected(request('delivery_person') == $id)>{{ $name }}</option>
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
                <a href="{{ route('shipments.index') }}" class="btn-secondary">
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
                <th>{{ __('lang_v1.delivered_to') }}</th>
                <th>{{ __('lang_v1.delivery_person') }}</th>
                <th>{{ __('lang_v1.shipping_status') }}</th>
                <th class="th-numeric">{{ __('lang_v1.total') }}</th>
                <th class="th-numeric">{{ __('lang_v1.actions') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($shipments as $shipment)
                <tr>
                    <td class="whitespace-nowrap">@format_date($shipment->transaction_date)</td>
                    <td class="force-ltr">
                        <a href="{{ route('sells.show', $shipment->id) }}" class="cell-link">
                            {{ $shipment->invoice_no }}
                        </a>
                    </td>
                    <td>{{ or_dash($shipment->contact->full_name_with_business ?? null) }}</td>
                    <td>{{ or_dash($shipment->location->name ?? null) }}</td>
                    <td>
                        {{ or_dash($shipment->delivered_to) }}
                        @if ($shipment->shipping_details)
                            <span class="cell-meta">{{ $shipment->shipping_details }}</span>
                        @endif
                    </td>
                    {{-- Read out of the filter's own id → name map rather than a
                         relation: delivery_person is a plain user id on the sale and
                         the relation is not eager loaded here. --}}
                    <td>{{ or_dash($deliveryPeople[$shipment->delivery_person] ?? null) }}</td>
                    <td>@transaction_status($shipment->shipping_status)</td>
                    <td class="cell-numeric">@format_currency($shipment->final_total)</td>
                    <td>
                        <div class="cell-actions">
                            <a href="{{ route('sells.show', $shipment->id) }}"
                               class="btn-icon" title="{{ __('lang_v1.view') }}"
                               aria-label="{{ __('lang_v1.view') }}">
                                <x-nav-icon name="eye" :size="4"/>
                            </a>
                        </div>
                    </td>
                </tr>
            @empty
                <x-table-empty :columns="9"
                               :icon="$isFiltered ? 'search' : 'truck'"
                               :title="$isFiltered ? __('lang_v1.no_records_found') : __('lang_v1.nothing_here_yet')"
                               :text="$isFiltered ? __('lang_v1.nothing_matches_filters') : __('lang_v1.no_shipments_hint')">
                    @if ($isFiltered)
                        <a href="{{ route('shipments.index') }}" class="btn-secondary btn-sm">
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

    {{ $shipments->links() }}
</div>
@endsection
