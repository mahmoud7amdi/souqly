@extends('layouts.app')
@section('title', __('lang_v1.stock'))
@section('page_title', $product->name.' — '.__('lang_v1.stock'))

@section('content')

@php
    /* Summed here rather than accumulated in the loop, so the head can state the
       total before the table rather than only in its footer. */
    $total = $product->variations->flatMap->variation_location_details->sum('qty_available');
@endphp

<x-page-head :back="route('products.show', $product->id)"
             :backLabel="__('lang_v1.back_to_product')"
             :title="format_quantity($total)"
             :subtitle="__('lang_v1.stock_by_location')"/>

<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>{{ __('lang_v1.variation') }}</th>
                <th>{{ __('lang_v1.location') }}</th>
                <th class="th-numeric">{{ __('lang_v1.stock') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($product->variations as $variation)
                @php
                    $label = $variation->name === 'DUMMY' ? __('lang_v1.default') : $variation->name;
                @endphp

                @forelse ($variation->variation_location_details as $detail)
                    {{-- The variation is named once per group, not once per row: a
                         product stocked in six branches repeated its own name six
                         times and the locations — the thing being compared — read
                         as the secondary column. A heavier rule opens each group so
                         the blank cells below are read as "same as above" rather
                         than "missing". --}}
                    <tr @class(['border-t-2 border-slate-100' => $loop->first && ! $loop->parent->first])>
                        <td class="cell-primary">{{ $loop->first ? $label : '' }}</td>
                        <td>{{ or_dash($detail->location->name ?? null) }}</td>
                        <td class="cell-numeric">@format_quantity($detail->qty_available)</td>
                    </tr>
                @empty
                    {{-- $loop is the *outer* loop here: @empty compiles after
                         popLoop(), so the inner loop is already off the stack. --}}
                    <tr @class(['border-t-2 border-slate-100' => ! $loop->first])>
                        <td class="cell-primary">{{ $label }}</td>
                        <td colspan="2" class="cell-none">{{ __('lang_v1.no_stock') }}</td>
                    </tr>
                @endforelse
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2">{{ __('lang_v1.total') }}</td>
                <td class="cell-numeric">@format_quantity($total)</td>
            </tr>
        </tfoot>
    </table>
</div>
@endsection
