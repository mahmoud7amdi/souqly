@extends('layouts.app')
@section('title', $product->name)
@section('page_title', $product->name)

@section('content')

{{-- The header already carries the product name, so the head carries the SKU —
     the identifier a shop actually reads off a shelf — and the two edit paths. --}}
<x-page-head>
    <x-slot:subtitle>
        <span class="force-ltr">{{ $product->sku }}</span>
        <span class="badge-muted ms-2">{{ __('lang_v1.'.$product->type) }}</span>
    </x-slot:subtitle>

    @can('product.update')
        <a href="{{ route('products.addSellingPrices', $product->id) }}" class="btn-secondary">
            <x-nav-icon name="tag"/>
            {{ __('lang_v1.selling_price_groups') }}
        </a>
        <a href="{{ route('products.edit', $product->id) }}" class="btn-primary">
            <x-nav-icon name="edit"/>
            {{ __('lang_v1.edit') }}
        </a>
    @endcan
</x-page-head>

<div class="grid gap-6 lg:grid-cols-3">

    <x-panel :title="__('lang_v1.product_details')" icon="box" class="lg:col-span-2">
        <x-attr-list :columns="2" :items="[
            'lang_v1.sku' => $product->sku,
            'lang_v1.type' => __('lang_v1.'.$product->type),
            'lang_v1.unit' => $product->unit->actual_name ?? null,
            'lang_v1.brand' => $product->brand->name ?? null,
            'lang_v1.category' => $product->category->name ?? null,
            'lang_v1.tax_rate' => $product->product_tax->name ?? null,
            'lang_v1.warranty' => $product->warranty->name ?? null,
            'lang_v1.alert_quantity' => $product->alert_quantity,
        ]"/>

        @if ($product->product_description)
            <p class="mt-5 border-t border-slate-100 pt-5 text-sm text-slate-600">
                {{ $product->product_description }}
            </p>
        @endif
    </x-panel>

    <x-panel :title="__('lang_v1.stock')" icon="layers" class="self-start" flush>
        <x-slot:actions>
            <a href="{{ route('products.stockHistory', $product->id) }}" class="link text-xs">
                {{ __('lang_v1.stock_history') }}
            </a>
        </x-slot:actions>

        @php $stockRows = $product->variations->flatMap->variation_location_details; @endphp

        <div class="table-wrap table-flush">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ __('lang_v1.location') }}</th>
                        <th class="th-numeric">{{ __('lang_v1.stock') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($stockRows as $detail)
                        <tr>
                            <td>{{ or_dash($detail->location->name ?? null) }}</td>
                            <td class="cell-numeric">@format_quantity($detail->qty_available)</td>
                        </tr>
                    @empty
                        <x-table-empty :columns="2" icon="layers"
                                       :title="__('lang_v1.no_stock')" compact/>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-panel>
</div>

{{-- Variations and their price-group overrides. --}}
<x-panel :title="__('lang_v1.variations')" icon="grid"
         :count="$product->variations->count()" class="mt-6" flush>
    <div class="table-wrap table-flush">
        <table class="table">
            <thead>
                <tr>
                    <th>{{ __('lang_v1.variation') }}</th>
                    <th>{{ __('lang_v1.sku') }}</th>
                    @if ($showPurchasePrice)
                        <th class="th-numeric">{{ __('lang_v1.purchase_price') }}</th>
                    @endif
                    <th class="th-numeric">{{ __('lang_v1.sell_price') }}</th>
                    <th class="th-numeric">{{ __('lang_v1.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($product->variations as $variation)
                    <tr>
                        <td class="cell-primary">
                            {{ $variation->name === 'DUMMY' ? __('lang_v1.default') : $variation->name }}
                        </td>
                        <td class="force-ltr text-xs text-slate-500">{{ $variation->sub_sku }}</td>
                        @if ($showPurchasePrice)
                            <td class="cell-numeric">@format_currency($variation->dpp_inc_tax)</td>
                        @endif
                        <td class="cell-numeric">@format_currency($variation->sell_price_inc_tax)</td>
                        <td>
                            <div class="cell-actions">
                                <a href="{{ route('products.priceHistory', $variation->id) }}"
                                   class="btn-icon" title="{{ __('lang_v1.price_history') }}"
                                   aria-label="{{ __('lang_v1.price_history') }}">
                                    <x-nav-icon name="clock" :size="4"/>
                                </a>
                            </div>
                        </td>
                    </tr>

                    {{-- Price-group overrides, indented under their variation: the
                         arrow and the tint say "this belongs to the row above"
                         without a second table. --}}
                    @foreach ($variation->group_prices as $groupPrice)
                        <tr class="bg-slate-50/60">
                            <td class="ps-8 text-xs text-slate-500" colspan="2">
                                {{-- inline-block so the RTL mirror actually applies:
                                     transforms are a no-op on an inline element. --}}
                                <span class="icon-directional inline-block">↳</span>
                                {{ or_dash($groupPrice->price_group->name ?? null) }}
                                <span class="badge-muted ms-1">{{ __('lang_v1.'.$groupPrice->price_type) }}</span>
                            </td>
                            <td colspan="{{ $showPurchasePrice ? 2 : 1 }}" class="cell-numeric text-xs">
                                @format_currency($groupPrice->calculated_price)
                            </td>
                            <td></td>
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    </div>
</x-panel>
@endsection
