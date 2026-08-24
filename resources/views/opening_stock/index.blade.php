@extends('layouts.app')
@section('title', __('lang_v1.opening_stock'))
@section('page_title', __('lang_v1.opening_stock'))

@section('content')

@php
    $isFiltered = collect(['search', 'category_id', 'recorded'])
        ->contains(fn ($key) => request()->filled($key));

    $canEdit = auth()->user()->can('product.opening_stock');
    $locationName = $locations[$locationId] ?? null;
@endphp

<x-page-head :subtitle="__('lang_v1.opening_stock_is_the_starting_count')"/>

@if (empty($locationId))
    {{-- No permitted location means nothing on this screen can be true, so it says
         that instead of showing an empty table that looks like "no products". --}}
    <x-empty-state icon="store" :title="__('lang_v1.no_permitted_location')"
                   :text="__('lang_v1.ask_an_admin_for_location_access')"/>
@else

<div class="section">
    <div class="rise-group grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <x-stat :label="__('lang_v1.business_location')"
                :value="$locationName"
                icon="store"
                :hint="__('lang_v1.figures_below_are_for_this_shop')"/>

        <x-stat :label="__('lang_v1.opening_stock_value')"
                :value="format_currency($totals['value'])"
                icon="coins"
                :hint="__('lang_v1.valued_at_cost')"/>

        <x-stat :label="__('lang_v1.products_recorded')"
                :value="number_format($totals['documents'])"
                icon="box"/>
    </div>
</div>

{{-- Location leads the filter bar and is not resettable: every number on the
     screen is "at this shop", and there is no such thing as opening stock without
     one. It submits on change because switching shop is the main thing done
     here — asking for a click on Apply as well would be a step for nothing. --}}
<form method="GET" class="filter-bar">
    <div class="filter-grid">
        <div class="field">
            <label for="location_id" class="label">{{ __('lang_v1.business_location') }}</label>
            <select id="location_id" name="location_id" class="select" onchange="this.form.requestSubmit()">
                @foreach ($locations as $id => $name)
                    <option value="{{ $id }}" @selected($locationId == $id)>{{ $name }}</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="search" class="label">{{ __('lang_v1.search') }}</label>
            <div class="input-search-wrap">
                <span class="input-search-icon"><x-nav-icon name="search" :size="4"/></span>
                <input type="search" id="search" name="search" value="{{ request('search') }}"
                       class="input-search"
                       placeholder="{{ __('lang_v1.product_name_or_sku') }}">
            </div>
        </div>

        <div class="field">
            <label for="category_id" class="label">{{ __('lang_v1.category') }}</label>
            <select id="category_id" name="category_id" class="select">
                @foreach ($categories as $id => $name)
                    <option value="{{ $id }}" @selected(request('category_id') == $id && $id !== '')>
                        {{ $name }}
                    </option>
                @endforeach
            </select>
        </div>

        {{-- The one filter this screen exists for: which products still have no
             starting count stated at this shop. --}}
        <div class="field">
            <label for="recorded" class="label">{{ __('lang_v1.status') }}</label>
            <select id="recorded" name="recorded" class="select">
                <option value="">{{ __('lang_v1.all') }}</option>
                <option value="yes" @selected(request('recorded') === 'yes')>
                    {{ __('lang_v1.recorded') }}
                </option>
                <option value="no" @selected(request('recorded') === 'no')>
                    {{ __('lang_v1.not_recorded') }}
                </option>
            </select>
        </div>

        <div class="flex items-end gap-2">
            <button type="submit" class="btn-primary">
                <x-nav-icon name="filter"/>
                {{ __('lang_v1.apply') }}
            </button>
            @if ($isFiltered)
                <a href="{{ route('opening-stock.index', ['location_id' => $locationId]) }}" class="btn-secondary">
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
                <th>{{ __('lang_v1.product') }}</th>
                <th>{{ __('lang_v1.category') }}</th>
                <th class="th-numeric">{{ __('lang_v1.opening_quantity') }}</th>
                <th class="th-numeric">{{ __('lang_v1.value') }}</th>
                <th>{{ __('lang_v1.date') }}</th>
                <th class="th-numeric">{{ __('lang_v1.actions') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($products as $product)
                @php
                    $summary = $summaries[$product->id] ?? null;
                    $recorded = ! is_null($summary);
                @endphp
                <tr>
                    <td>
                        <div class="flex items-center gap-3">
                            <x-product-thumb :product="$product" size="sm"/>
                            <div class="min-w-0">
                                <a href="{{ route('opening-stock.edit', ['productId' => $product->id, 'location_id' => $locationId]) }}"
                                   class="cell-link">{{ $product->name }}</a>
                                <span class="cell-meta force-ltr">{{ $product->sku }}</span>
                            </div>
                        </div>
                    </td>

                    <td>{{ or_dash($product->category->name ?? null) }}</td>

                    {{-- A recorded zero and a product nobody has stated yet are
                         different facts, and the difference is the whole point of
                         this screen — so an unstated product shows a dash and a
                         badge, never a 0. --}}
                    <td class="cell-numeric">
                        @if ($recorded)
                            @format_quantity($summary->quantity)
                            @if ($product->unit)
                                <span class="cell-meta">{{ $product->unit->short_name }}</span>
                            @endif
                        @else
                            <span class="badge-muted">{{ __('lang_v1.not_recorded') }}</span>
                        @endif
                    </td>

                    <td class="cell-numeric">
                        @if ($recorded)
                            @format_currency($summary->value)
                        @else
                            {{ or_dash(null) }}
                        @endif
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($recorded)
                            @format_date($summary->transaction_date)
                        @else
                            {{ or_dash(null) }}
                        @endif
                    </td>

                    <td>
                        <div class="cell-actions">
                            @if ($canEdit)
                                <a href="{{ route('opening-stock.edit', ['productId' => $product->id, 'location_id' => $locationId]) }}"
                                   class="btn-icon"
                                   title="{{ $recorded ? __('lang_v1.edit') : __('lang_v1.record_opening_stock') }}"
                                   aria-label="{{ $recorded ? __('lang_v1.edit') : __('lang_v1.record_opening_stock') }}">
                                    <x-nav-icon :name="$recorded ? 'edit' : 'plus'" :size="4"/>
                                </a>

                                @if ($recorded)
                                    <form method="POST"
                                          action="{{ route('opening-stock.destroy', $product->id) }}"
                                          data-confirm="{{ __('lang_v1.confirm_delete_opening_stock') }}">
                                        @csrf
                                        @method('DELETE')
                                        <input type="hidden" name="location_id" value="{{ $locationId }}">
                                        <button type="submit" class="btn-icon-danger"
                                                title="{{ __('lang_v1.delete') }}"
                                                aria-label="{{ __('lang_v1.delete') }}">
                                            <x-nav-icon name="trash" :size="4"/>
                                        </button>
                                    </form>
                                @endif
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <x-table-empty :columns="6"
                               :icon="$isFiltered ? 'search' : 'box'"
                               :title="$isFiltered ? __('lang_v1.no_records_found') : __('lang_v1.nothing_here_yet')"
                               :text="$isFiltered ? __('lang_v1.nothing_matches_filters') : __('lang_v1.only_stocked_products_appear_here')">
                    @if ($isFiltered)
                        <a href="{{ route('opening-stock.index', ['location_id' => $locationId]) }}"
                           class="btn-secondary btn-sm">
                            {{ __('lang_v1.clear_filters') }}
                        </a>
                    @endif
                </x-table-empty>
            @endforelse
        </tbody>
    </table>

    {{ $products->links() }}
</div>
@endif
@endsection
