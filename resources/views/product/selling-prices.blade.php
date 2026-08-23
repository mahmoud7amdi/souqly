@extends('layouts.app')
@section('title', __('lang_v1.selling_price_groups'))
@section('page_title', $product->name.' — '.__('lang_v1.selling_price_groups'))

@section('content')

<x-page-head :back="route('products.show', $product->id)"
             :backLabel="__('lang_v1.back_to_product')"
             :subtitle="__('lang_v1.group_price_type_hint')"/>

@if ($priceGroups->isEmpty())
    <div class="alert-info">
        <span>{{ __('lang_v1.no_active_price_groups') }}</span>
    </div>
@else
<form method="POST" action="{{ route('products.saveSellingPrices', $product->id) }}" class="card">
    @csrf

    <div class="table-wrap table-flush">
        <table class="table">
            <thead>
                <tr>
                    <th>{{ __('lang_v1.variation') }}</th>
                    <th class="th-numeric">{{ __('lang_v1.default_price') }}</th>
                    @foreach ($priceGroups as $group)
                        <th class="th-numeric">{{ $group->name }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($product->variations as $variation)
                    @php
                        $label = $variation->name === 'DUMMY' ? __('lang_v1.default') : $variation->name;
                    @endphp
                    <tr>
                        <td>
                            <span class="cell-primary">{{ $label }}</span>
                            <span class="cell-meta force-ltr">{{ $variation->sub_sku }}</span>
                        </td>
                        <td class="cell-numeric">@format_currency($variation->sell_price_inc_tax)</td>

                        @foreach ($priceGroups as $group)
                            @php
                                $existing = $variation->group_prices
                                    ->firstWhere('price_group_id', $group->id);

                                /* Two unlabelled controls per cell, and a grid of them:
                                   the column header alone leaves a screen reader
                                   announcing "edit text, blank" a dozen times, so each
                                   pair names the variation and the group it belongs to. */
                                $cellName = $label.' — '.$group->name;
                            @endphp
                            <td>
                                <div class="flex items-center justify-end gap-1.5">
                                    <input name="group_prices[{{ $variation->id }}][{{ $group->id }}][price]"
                                           class="input-numeric w-28" inputmode="decimal"
                                           aria-label="{{ $cellName }}"
                                           value="{{ $existing->price_inc_tax ?? '' }}">
                                    <select name="group_prices[{{ $variation->id }}][{{ $group->id }}][type]"
                                            class="select w-24 py-1.5 text-xs"
                                            aria-label="{{ $cellName.' — '.__('lang_v1.type') }}">
                                        <option value="fixed"
                                            @selected(($existing->price_type ?? 'fixed') === 'fixed')>
                                            {{ __('lang_v1.fixed') }}
                                        </option>
                                        <option value="percentage"
                                            @selected(($existing->price_type ?? '') === 'percentage')>%
                                        </option>
                                    </select>
                                </div>
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="card-actions">
        <a href="{{ route('products.show', $product->id) }}" class="btn-secondary">
            {{ __('lang_v1.cancel') }}
        </a>
        <button type="submit" class="btn-primary">
            <x-nav-icon name="save"/>
            {{ __('lang_v1.save') }}
        </button>
    </div>
</form>
@endif
@endsection
