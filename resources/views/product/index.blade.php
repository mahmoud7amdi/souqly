@extends('layouts.app')
@section('title', __('lang_v1.products'))
@section('page_title', __('lang_v1.products'))

@section('content')

@php
    /* Empty-because-nothing-exists and empty-because-the-filter-excluded-it need
       different empty states: "add your first product" is useless advice to
       someone whose search simply matched nothing. Checked across every filter,
       not just search, so narrowing by brand also gets the honest message. */
    $isFiltered = collect(['search', 'category_id', 'brand_id', 'type'])
        ->contains(fn ($key) => request()->filled($key));

    $columnCount = $showPurchasePrice ? 8 : 7;
@endphp

{{-- The count and the create action live here, not in a card header: every list
     screen in the app puts "how many" and "add one" in the same two places, and
     a card header that also has to carry a button ends up with the primary
     action of the page buried a third of the way down it. --}}
<x-page-head :subtitle="trans_choice('lang_v1.record_count', $products->total(), ['count' => $products->total()])">
    @can('product.create')
        <a href="{{ route('products.create') }}" class="btn-primary">
            <x-nav-icon name="plus"/>
            {{ __('lang_v1.add_product') }}
        </a>
    @endcan
</x-page-head>

{{-- Filters sit in a sunken strip, not a white card: they are secondary to the
     data and must not compete with the table for attention. --}}
<form method="GET" class="filter-bar">
    <div class="filter-grid">
        <div class="field sm:col-span-2">
            <label for="search" class="label">{{ __('lang_v1.search') }}</label>
            <div class="input-search-wrap">
                <span class="input-search-icon"><x-nav-icon name="search" :size="4"/></span>
                <input type="search" id="search" name="search" value="{{ request('search') }}"
                       class="input-search" placeholder="{{ __('lang_v1.name_or_sku') }}">
            </div>
        </div>

        <div class="field">
            <label for="category_id" class="label">{{ __('lang_v1.category') }}</label>
            <select id="category_id" name="category_id" class="select">
                <option value="">{{ __('lang_v1.all') }}</option>
                @foreach ($categories as $id => $name)
                    <option value="{{ $id }}" @selected(request('category_id') == $id)>{{ $name }}</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="brand_id" class="label">{{ __('lang_v1.brand') }}</label>
            <select id="brand_id" name="brand_id" class="select">
                <option value="">{{ __('lang_v1.all') }}</option>
                @foreach ($brands as $id => $name)
                    @if ($id !== '')
                        <option value="{{ $id }}" @selected(request('brand_id') == $id)>{{ $name }}</option>
                    @endif
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="type" class="label">{{ __('lang_v1.type') }}</label>
            <select id="type" name="type" class="select">
                <option value="">{{ __('lang_v1.all') }}</option>
                @foreach ($types as $value => $name)
                    <option value="{{ $value }}" @selected(request('type') === $value)>{{ $name }}</option>
                @endforeach
            </select>
        </div>

        {{-- Reset only exists once there is something to reset. --}}
        <div class="flex items-end gap-2 sm:col-span-2 lg:col-auto">
            <button type="submit" class="btn-primary">
                <x-nav-icon name="filter"/>
                {{ __('lang_v1.apply') }}
            </button>
            @if ($isFiltered)
                <a href="{{ route('products.index') }}" class="btn-secondary">
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
                <th>{{ __('lang_v1.brand') }}</th>
                <th>{{ __('lang_v1.type') }}</th>
                @if ($showPurchasePrice)
                    <th class="th-numeric">{{ __('lang_v1.purchase_price') }}</th>
                @endif
                <th class="th-numeric">{{ __('lang_v1.sell_price') }}</th>
                <th class="th-numeric">{{ __('lang_v1.stock') }}</th>
                <th class="th-numeric">{{ __('lang_v1.actions') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($products as $product)
                @php $variation = $product->variations->first(); @endphp
                <tr>
                    <td>
                        <span class="cell-primary">{{ $product->name }}</span>
                        <span class="cell-meta force-ltr">{{ $product->sku }}</span>
                    </td>
                    <td>{{ or_dash($product->category->name ?? null) }}</td>
                    <td>{{ or_dash($product->brand->name ?? null) }}</td>
                    <td><span class="badge-muted">{{ $types[$product->type] ?? $product->type }}</span></td>
                    @if ($showPurchasePrice)
                        <td class="cell-numeric">@format_currency($variation->dpp_inc_tax ?? 0)</td>
                    @endif
                    <td class="cell-numeric">@format_currency($variation->sell_price_inc_tax ?? 0)</td>
                    <td class="cell-numeric">
                        @if ($product->enable_stock)
                            @php $stock = $stockByProduct[$product->id] ?? 0; @endphp
                            {{-- Below the alert threshold is the one number on this
                                 screen worth interrupting a scan for. --}}
                            <span @class(['font-semibold text-rose-600' => $stock <= (float) $product->alert_quantity])>
                                @format_quantity($stock)
                            </span>
                        @else
                            <span class="cell-none">—</span>
                        @endif
                    </td>
                    <td>
                        {{-- Icon actions rather than three text buttons: the verbs
                             repeat on every row, and spelling them out costs a
                             third of the table's width on the busiest screen in
                             the app. Each keeps an accessible name. --}}
                        <div class="cell-actions">
                            <a href="{{ route('products.show', $product->id) }}"
                               class="btn-icon" title="{{ __('lang_v1.view') }}"
                               aria-label="{{ __('lang_v1.view') }}">
                                <x-nav-icon name="eye" :size="4"/>
                            </a>

                            @can('product.update')
                                <a href="{{ route('products.edit', $product->id) }}"
                                   class="btn-icon" title="{{ __('lang_v1.edit') }}"
                                   aria-label="{{ __('lang_v1.edit') }}">
                                    <x-nav-icon name="edit" :size="4"/>
                                </a>
                            @endcan

                            @can('product.delete')
                                <form method="POST" action="{{ route('products.destroy', $product->id) }}"
                                      data-confirm="{{ __('lang_v1.confirm_delete') }}">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn-icon-danger"
                                            title="{{ __('lang_v1.delete') }}"
                                            aria-label="{{ __('lang_v1.delete') }}">
                                        <x-nav-icon name="trash" :size="4"/>
                                    </button>
                                </form>
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <x-table-empty :columns="$columnCount"
                               :icon="$isFiltered ? 'search' : 'box'"
                               :title="$isFiltered ? __('lang_v1.no_records_found') : __('lang_v1.nothing_here_yet')"
                               :text="$isFiltered ? __('lang_v1.nothing_matches_filters') : null">
                    @if ($isFiltered)
                        <a href="{{ route('products.index') }}" class="btn-secondary btn-sm">
                            {{ __('lang_v1.clear_filters') }}
                        </a>
                    @elseif (auth()->user()->can('product.create'))
                        <a href="{{ route('products.create') }}" class="btn-primary btn-sm">
                            <x-nav-icon name="plus" :size="4"/>
                            {{ __('lang_v1.add_product') }}
                        </a>
                    @endif
                </x-table-empty>
            @endforelse
        </tbody>
    </table>

    {{ $products->links() }}
</div>
@endsection
