@extends('layouts.app')
@section('title', __('lang_v1.edit').' — '.$product->name)
@section('page_title', __('lang_v1.edit').' — '.$product->name)

@section('content')

{{-- Back to the product, not the list: someone who opened edit from a product
     and changed their mind wants that product again. --}}
<x-page-head :back="route('products.show', $product->id)" :backLabel="$product->name"/>

<form method="POST" action="{{ route('products.update', $product->id) }}">
    @csrf
    @method('PUT')
    @include('product._form')

    {{-- Variable products price each variation individually, which is why
         product._form drops its single-price panel for them. --}}
    @if ($product->type === 'variable')
        <x-panel :title="__('lang_v1.variation_prices')" icon="layers"
                 :count="$product->variations->count()" class="mt-6" flush>
            <div class="table-wrap table-flush">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('lang_v1.variation') }}</th>
                            <th class="th-numeric w-36">{{ __('lang_v1.purchase_price') }}</th>
                            <th class="th-numeric w-32">{{ __('lang_v1.profit_percent') }}</th>
                            <th class="th-numeric w-36">{{ __('lang_v1.sell_price') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($product->variations as $variation)
                            @php
                                /* Three unlabelled inputs per row, and a grid of them: the
                                   column header alone leaves a screen reader announcing
                                   "edit text, 45.00" with no idea which variation it belongs
                                   to, so each input names its own row. */
                                $rowName = $variation->name ?: $variation->sub_sku;
                            @endphp

                            <tr>
                                <td>
                                    <span class="cell-primary">{{ $variation->name }}</span>
                                    <span class="cell-meta force-ltr">{{ $variation->sub_sku }}</span>
                                </td>

                                <td>
                                    <input name="variation_prices[{{ $variation->id }}][default_purchase_price]"
                                           class="input-numeric w-32" inputmode="decimal"
                                           value="{{ $variation->default_purchase_price }}"
                                           aria-label="{{ $rowName.' — '.__('lang_v1.purchase_price') }}">
                                </td>

                                <td>
                                    <input name="variation_prices[{{ $variation->id }}][profit_percent]"
                                           class="input-numeric w-28" inputmode="decimal"
                                           value="{{ $variation->profit_percent }}"
                                           aria-label="{{ $rowName.' — '.__('lang_v1.profit_percent') }}">
                                </td>

                                <td>
                                    <input name="variation_prices[{{ $variation->id }}][default_sell_price]"
                                           class="input-numeric w-32" inputmode="decimal"
                                           value="{{ $variation->default_sell_price }}"
                                           aria-label="{{ $rowName.' — '.__('lang_v1.sell_price') }}">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-panel>
    @endif

    <div class="form-actions">
        <span class="form-actions-spacer">{{ __('lang_v1.required_fields_hint') }}</span>
        <a href="{{ route('products.show', $product->id) }}" class="btn-secondary">
            {{ __('lang_v1.cancel') }}
        </a>
        <button type="submit" class="btn-primary">
            <x-nav-icon name="save"/>
            {{ __('lang_v1.update') }}
        </button>
    </div>
</form>
@endsection
