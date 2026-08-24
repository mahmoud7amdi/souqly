@extends('layouts.app')
@section('title', $record->ref_no)
@section('page_title', __('lang_v1.stock_transfer').' — '.$record->ref_no)

@section('content')

@php
    $child = $record->transfer_child;
    $inTransit = $record->status === 'in_transit';
    $goods = (float) $record->total_before_tax;
    $shipping = (float) $record->shipping_charges;
    $totalQuantity = $record->sell_lines->sum('quantity');
@endphp

<x-page-head :back="route('stock-transfers.index')" :backLabel="__('lang_v1.stock_transfers')">
    <x-slot:subtitle>
        <span class="inline-flex flex-wrap items-center gap-x-2 gap-y-1">
            <span class="force-ltr">@format_datetime($record->transaction_date)</span>
            <span class="text-slate-300">&middot;</span>
            <x-transaction-status :status="$record->status"/>
        </span>
    </x-slot:subtitle>

    <button type="button" onclick="window.print()" class="btn-secondary">
        <x-nav-icon name="printer"/>
        {{ __('lang_v1.print') }}
    </button>

    {{-- Receiving is the primary action while a transfer is on the road, and it is
         the only edit a transfer allows — so it gets the primary button and
         disappears entirely once it has been done. --}}
    @if ($canReceive)
        <form method="POST" action="{{ route('stock-transfers.updateStatus', $record->id) }}"
              data-confirm="{{ __('lang_v1.confirm_receive_transfer') }}">
            @csrf
            <button type="submit" class="btn-primary">
                <x-nav-icon name="check-circle"/>
                {{ __('lang_v1.mark_received') }}
            </button>
        </form>
    @endif

    @if ($canDelete)
        <form method="POST" action="{{ route('stock-transfers.destroy', $record->id) }}"
              data-confirm="{{ __('lang_v1.confirm_delete_transfer') }}">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn-secondary">
                <x-nav-icon name="trash"/>
                {{ __('lang_v1.delete') }}
            </button>
        </form>
    @endif
</x-page-head>

{{-- The route, full width and above everything else: a transfer's identity is
     which two shops it is between, and the in-transit case needs the direction of
     travel to be unmissable. --}}
<div class="section">
    <div class="card">
        <div class="card-body">
            <div class="flex flex-col items-stretch gap-4 sm:flex-row sm:items-center">
                <div class="min-w-0 flex-1">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">
                        {{ __('lang_v1.transfer_from_location') }}
                    </p>
                    <p class="mt-1 truncate text-lg font-semibold text-slate-900">
                        {{ or_dash($record->location->name ?? null) }}
                    </p>
                    <p class="mt-1 text-sm text-slate-500">{{ __('lang_v1.stock_already_deducted') }}</p>
                </div>

                <div class="flex shrink-0 items-center justify-center gap-2 text-slate-400">
                    <span class="hidden h-px w-8 bg-slate-200 sm:block"></span>
                    <x-nav-icon name="truck" :size="6"/>
                    <span class="hidden h-px w-8 bg-slate-200 sm:block"></span>
                </div>

                <div class="min-w-0 flex-1 sm:text-end">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">
                        {{ __('lang_v1.transfer_to_location') }}
                    </p>
                    <p class="mt-1 truncate text-lg font-semibold text-slate-900">
                        {{ or_dash($child->location->name ?? null) }}
                    </p>
                    <p class="mt-1 text-sm text-slate-500">
                        {{ $inTransit
                            ? __('lang_v1.stock_pending_receipt')
                            : __('lang_v1.stock_booked_in') }}
                    </p>
                </div>
            </div>

            @if ($inTransit)
                {{-- Said plainly, on the document itself: the goods are counted at
                     neither shop right now, and somebody has to confirm arrival
                     before the destination can sell them. --}}
                <div class="alert-info mt-5" role="note">
                    <x-nav-icon name="info"/>
                    <div class="min-w-0">
                        <p class="font-semibold">{{ __('lang_v1.transfer_in_transit_title') }}</p>
                        <p class="mt-0.5">{{ __('lang_v1.transfer_in_transit_hint') }}</p>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>

<div class="grid gap-6 lg:grid-cols-4">

    <x-panel :title="__('lang_v1.items')" icon="box"
             :count="$record->sell_lines->count()"
             class="lg:col-span-3" flush>
        <div class="table-wrap table-flush">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ __('lang_v1.product') }}</th>
                        <th class="th-numeric">{{ __('lang_v1.quantity') }}</th>
                        {{-- The cost is a result, not an input: FIFO decided it when
                             the document saved. It is shown because it is what the
                             destination's stock is now carried at. --}}
                        <th class="th-numeric">{{ __('lang_v1.unit_cost') }}</th>
                        <th class="th-numeric">{{ __('lang_v1.value') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($record->sell_lines as $line)
                        <tr>
                            <td>
                                <div class="flex items-center gap-3">
                                    @if ($line->variations?->product)
                                        <x-product-thumb :product="$line->variations->product" size="sm"/>
                                    @endif
                                    <div class="min-w-0">
                                        <span class="cell-primary">{{ $line->variations?->full_name }}</span>
                                        <span class="cell-meta force-ltr">{{ $line->variations?->sub_sku }}</span>
                                    </div>
                                </div>
                            </td>
                            <td class="cell-numeric">
                                @format_quantity($line->quantity)
                                @if ($line->variations?->product?->unit)
                                    <span class="cell-meta">{{ $line->variations->product->unit->short_name }}</span>
                                @endif
                            </td>
                            <td class="cell-numeric">@format_currency($line->unit_price)</td>
                            <td class="cell-numeric">@format_currency($line->quantity * $line->unit_price)</td>
                        </tr>
                    @empty
                        <x-table-empty :columns="4" icon="box" :title="__('lang_v1.no_records_found')"/>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr>
                        <td>{{ __('lang_v1.total') }}</td>
                        <td class="cell-numeric">@format_quantity($totalQuantity)</td>
                        <td></td>
                        <td class="cell-numeric">@format_currency($goods)</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </x-panel>

    <div class="grid gap-6 self-start">

        <x-panel :title="__('lang_v1.summary')" icon="receipt">
            <dl class="dl">
                <div class="dl-row">
                    <dt class="dl-key">{{ __('lang_v1.goods_value') }}</dt>
                    <dd class="dl-value">@format_currency($goods)</dd>
                </div>

                {{-- Freight sits beside the goods rather than inside them: a
                     transfer's unit costs stay what they were bought for, so the
                     shipping is a cost of the move, not of the stock. --}}
                <div class="dl-row">
                    <dt class="dl-key">{{ __('lang_v1.shipping_charges') }}</dt>
                    <dd class="dl-value">@format_currency($shipping)</dd>
                </div>

                <div class="dl-total">
                    <dt class="font-semibold text-slate-900">{{ __('lang_v1.total') }}</dt>
                    <dd class="dl-total-value">@format_currency($record->final_total)</dd>
                </div>
            </dl>

            @if ($shipping > 0)
                <p class="hint mt-3">{{ __('lang_v1.transfer_freight_hint') }}</p>
            @endif
        </x-panel>

        <x-panel :title="__('lang_v1.details')" icon="document">
            <x-attr-list :items="[
                'lang_v1.reference_no' => $record->ref_no,
                'lang_v1.shipping_details' => $record->shipping_details,
                'lang_v1.added_by' => $record->created_user->user_full_name ?? null,
                'lang_v1.notes' => $record->additional_notes,
            ]"/>
        </x-panel>

        {{-- Both halves, named and linked to their statuses, because "where is the
             other document" is the first question anyone reconciling a transfer
             asks — and a half-missing transfer needs to be visible, not silent. --}}
        <x-panel :title="__('lang_v1.documents')" icon="layers" quiet>
            <ul class="grid gap-3 text-sm">
                <li class="flex items-center justify-between gap-3">
                    <span class="min-w-0">
                        <span class="block font-medium text-slate-900">{{ __('lang_v1.stock_out') }}</span>
                        <span class="block text-slate-500">{{ or_dash($record->location->name ?? null) }}</span>
                    </span>
                    <x-transaction-status :status="$record->status"/>
                </li>

                <li class="flex items-center justify-between gap-3">
                    <span class="min-w-0">
                        <span class="block font-medium text-slate-900">{{ __('lang_v1.stock_in') }}</span>
                        <span class="block text-slate-500">{{ or_dash($child->location->name ?? null) }}</span>
                    </span>
                    @if ($child)
                        <x-transaction-status :status="$child->status"/>
                    @else
                        <span class="badge-danger">{{ __('lang_v1.missing') }}</span>
                    @endif
                </li>
            </ul>
        </x-panel>
    </div>
</div>
@endsection
