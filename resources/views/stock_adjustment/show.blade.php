@extends('layouts.app')
@section('title', $record->ref_no)
@section('page_title', __('lang_v1.stock_adjustment').' — '.$record->ref_no)

@section('content')

@php
    $isAbnormal = $record->adjustment_type === 'abnormal';
    $recovered = (float) $record->total_amount_recovered;
    $totalQuantity = $record->stock_adjustment_lines->sum('quantity');
@endphp

<x-page-head :back="route('stock-adjustments.index')" :backLabel="__('lang_v1.stock_adjustments')">
    <x-slot:subtitle>
        <span class="inline-flex flex-wrap items-center gap-x-2 gap-y-1">
            <span class="force-ltr">@format_datetime($record->transaction_date)</span>
            <span class="text-slate-300">&middot;</span>
            <span>{{ or_dash($record->location->name ?? null) }}</span>
            <span class="ms-1 inline-flex items-center gap-1.5">
                @if ($isAbnormal)
                    <span class="badge-warning">{{ __('lang_v1.abnormal') }}</span>
                @else
                    <span class="badge-muted">{{ __('lang_v1.normal') }}</span>
                @endif
            </span>
        </span>
    </x-slot:subtitle>

    <button type="button" onclick="window.print()" class="btn-secondary">
        <x-nav-icon name="printer"/>
        {{ __('lang_v1.print') }}
    </button>

    @can('stock_adjustment.update')
        <a href="{{ route('stock-adjustments.edit', $record->id) }}" class="btn-secondary">
            <x-nav-icon name="edit"/>
            {{ __('lang_v1.edit') }}
        </a>
    @endcan

    @can('stock_adjustment.delete')
        <form method="POST" action="{{ route('stock-adjustments.destroy', $record->id) }}"
              data-confirm="{{ __('lang_v1.confirm_delete_adjustment') }}">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn-secondary">
                <x-nav-icon name="trash"/>
                {{ __('lang_v1.delete') }}
            </button>
        </form>
    @endcan
</x-page-head>

<div class="grid gap-6 lg:grid-cols-4">

    <x-panel :title="__('lang_v1.items')" icon="box"
             :count="$record->stock_adjustment_lines->count()"
             class="lg:col-span-3" flush>
        <div class="table-wrap table-flush">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ __('lang_v1.product') }}</th>
                        <th class="th-numeric">{{ __('lang_v1.quantity') }}</th>
                        {{-- "Unit cost" and not "price": nothing was sold here, and
                             the figure is the FIFO cost of the units this line
                             actually took, not a catalogue number. --}}
                        <th class="th-numeric">{{ __('lang_v1.unit_cost') }}</th>
                        <th class="th-numeric">{{ __('lang_v1.value') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($record->stock_adjustment_lines as $line)
                        <tr>
                            <td>
                                <div class="flex items-center gap-3">
                                    @if ($line->variation?->product)
                                        <x-product-thumb :product="$line->variation->product" size="sm"/>
                                    @endif
                                    <div class="min-w-0">
                                        <span class="cell-primary">{{ $line->variation?->full_name }}</span>
                                        <span class="cell-meta force-ltr">{{ $line->variation?->sub_sku }}</span>
                                    </div>
                                </div>
                            </td>
                            <td class="cell-numeric">
                                @format_quantity($line->quantity)
                                @if ($line->variation?->product?->unit)
                                    <span class="cell-meta">{{ $line->variation->product->unit->short_name }}</span>
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
                        <td class="cell-numeric">@format_currency($record->final_total)</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </x-panel>

    <div class="grid gap-6 self-start">

        <x-panel :title="__('lang_v1.summary')" icon="receipt">
            <dl class="dl">
                <div class="dl-row">
                    <dt class="dl-key">{{ __('lang_v1.total_loss') }}</dt>
                    <dd class="dl-value">@format_currency($record->final_total)</dd>
                </div>

                <div class="dl-row">
                    <dt class="dl-key">{{ __('lang_v1.amount_recovered') }}</dt>
                    <dd @class(['dl-value', 'text-emerald-600' => $recovered > 0])>
                        @format_currency($recovered)
                    </dd>
                </div>

                {{-- The net figure is derived here rather than stored: the two
                     numbers above are the facts, and netting them in the database
                     would hide the size of a loss behind how well it was
                     recovered. --}}
                <div class="dl-total">
                    <dt class="font-semibold text-slate-900">{{ __('lang_v1.net_loss') }}</dt>
                    <dd class="dl-total-value">
                        @format_currency($record->final_total - $recovered)
                    </dd>
                </div>
            </dl>
        </x-panel>

        <x-panel :title="__('lang_v1.details')" icon="document">
            <x-attr-list :items="[
                'lang_v1.reference_no' => $record->ref_no,
                'lang_v1.business_location' => $record->location->name ?? null,
                'lang_v1.adjustment_type' => __('lang_v1.'.$record->adjustment_type),
                'lang_v1.added_by' => $record->created_user->user_full_name ?? null,
                'lang_v1.reason' => $record->additional_notes,
            ]"/>
        </x-panel>
    </div>
</div>
@endsection
