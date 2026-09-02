@extends('layouts.app')
@section('title', __('lang_v1.opening_stock').' — '.$product->name)
@section('page_title', __('lang_v1.opening_stock').' — '.$product->name)

@section('content')

@php
    $unit = $product->unit->short_name ?? null;
    $isSingle = $product->variations->count() === 1;

    $currentTotal = $lots->sum(fn ($lot) => (float) $lot->quantity);
    $currentValue = $lots->sum(fn ($lot) => (float) $lot->quantity * (float) $lot->purchase_price_inc_tax);
@endphp

<form method="POST" action="{{ route('opening-stock.update', $product->id) }}">
    @csrf
    @method('PUT')

    <x-page-head :back="route('opening-stock.index', ['location_id' => $locationId])"
                 :backLabel="__('lang_v1.opening_stock')">
        <x-slot:subtitle>
            <span class="inline-flex flex-wrap items-center gap-x-2 gap-y-1">
                <span class="force-ltr">{{ $product->sku }}</span>
                @if ($document)
                    <span class="text-slate-300">&middot;</span>
                    <span>{{ __('lang_v1.recorded_on') }}
                        <span class="force-ltr">@format_date($document->transaction_date)</span></span>
                @else
                    <span class="text-slate-300">&middot;</span>
                    <span class="badge-muted">{{ __('lang_v1.not_recorded') }}</span>
                @endif
            </span>
        </x-slot:subtitle>

        @if ($document)
            {{-- Withdrawing the statement entirely is a different act from setting
                 the quantities to zero, so it gets its own button rather than
                 being something you discover by typing zeros. --}}
            <button type="submit" form="withdraw-form" class="btn-secondary">
                <x-nav-icon name="trash"/>
                {{ __('lang_v1.delete') }}
            </button>
        @endif
    </x-page-head>

    <div class="grid gap-6 lg:grid-cols-3">

        <x-panel :title="__('lang_v1.opening_stock')" icon="box" class="lg:col-span-2">
            <div class="form-grid-3">
                {{-- Location is a key to the document, not a field on it. Changing
                     it navigates to the other shop's statement rather than moving
                     stock — hence the immediate submit and the hint. --}}
                <div class="field">
                    <label for="location_id" class="label label-required">{{ __('lang_v1.business_location') }}</label>
                    <select id="location_id" name="location_id" class="select"
                            onchange="window.location = '{{ route('opening-stock.edit', $product->id) }}?location_id=' + this.value">
                        @foreach ($locations as $id => $name)
                            <option value="{{ $id }}" @selected($locationId == $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                    <p class="hint">{{ __('lang_v1.opening_stock_is_per_location') }}</p>
                </div>

                <div class="field">
                    <label for="transaction_date" class="label">{{ __('lang_v1.date') }}</label>
                    <input type="date" id="transaction_date" name="transaction_date"
                           @class(['input', 'input-invalid' => $errors->has('transaction_date')])
                           value="{{ old('transaction_date', optional($document?->transaction_date)->format('Y-m-d') ?? now()->format('Y-m-d')) }}">
                    <p class="hint">{{ __('lang_v1.opening_stock_date_hint') }}</p>
                </div>
            </div>

            <div class="table-wrap mt-5">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ $isSingle ? __('lang_v1.product') : __('lang_v1.variation') }}</th>
                            <th class="th-numeric w-32">{{ __('lang_v1.quantity') }}</th>
                            <th class="th-numeric w-36">{{ __('lang_v1.unit_cost') }}</th>
                            {{-- The floor, stated up front. Trying to go below it is
                                 refused on save, and a number that explains the
                                 refusal beforehand is worth a column. --}}
                            <th class="th-numeric w-28">{{ __('lang_v1.already_used') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($product->variations as $variation)
                            @php
                                $lot = $lots[$variation->id] ?? null;
                                $used = $lot
                                    ? (float) $lot->quantity_sold
                                        + (float) $lot->quantity_adjusted
                                        + (float) $lot->quantity_returned
                                    : 0.0;
                                $label = ($variation->name && $variation->name !== 'DUMMY')
                                    ? $variation->name
                                    : $product->name;
                            @endphp
                            <tr>
                                <td>
                                    <span class="cell-primary">{{ $label }}</span>
                                    <span class="cell-meta force-ltr">{{ $variation->sub_sku }}</span>
                                </td>

                                <td>
                                    <input name="quantities[{{ $variation->id }}]"
                                           @class(['input-numeric w-28', 'input-invalid' => $errors->has('quantities.'.$variation->id)])
                                           inputmode="decimal"
                                           value="{{ old('quantities.'.$variation->id, $lot ? (float) $lot->quantity : null) }}"
                                           placeholder="0"
                                           aria-label="{{ __('lang_v1.quantity') }}">
                                    @if ($unit)
                                        <span class="cell-meta">{{ $unit }}</span>
                                    @endif
                                </td>

                                {{-- Pre-filled from the product's purchase price so
                                     the common case is one number typed, not two —
                                     but editable, because opening stock is often
                                     valued at what it actually cost years ago. --}}
                                <td>
                                    <input name="prices[{{ $variation->id }}]"
                                           @class(['input-numeric w-32', 'input-invalid' => $errors->has('prices.'.$variation->id)])
                                           inputmode="decimal"
                                           value="{{ old('prices.'.$variation->id, $lot ? (float) $lot->purchase_price_inc_tax : (float) $variation->default_purchase_price) }}"
                                           aria-label="{{ __('lang_v1.unit_cost') }}">
                                </td>

                                <td class="cell-numeric">
                                    @if ($used > 0.0001)
                                        <span class="text-amber-600">@format_quantity($used)</span>
                                    @else
                                        {{ or_dash(null) }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @error('quantities')<p class="field-error mt-2">{{ $message }}</p>@enderror
        </x-panel>

        <div class="grid gap-6 self-start">

            @if ($document)
                <x-panel :title="__('lang_v1.current_position')" icon="receipt">
                    <dl class="dl">
                        <div class="dl-row">
                            <dt class="dl-key">{{ __('lang_v1.opening_quantity') }}</dt>
                            <dd class="dl-value">
                                @format_quantity($currentTotal)
                                @if ($unit)<span class="cell-meta">{{ $unit }}</span>@endif
                            </dd>
                        </div>
                        <div class="dl-total">
                            <dt class="font-semibold text-slate-900">{{ __('lang_v1.value') }}</dt>
                            <dd class="dl-total-value">@format_currency($currentValue)</dd>
                        </div>
                    </dl>
                </x-panel>
            @endif

            <x-panel :title="__('lang_v1.how_this_works')" icon="info" class="self-start" quiet>
                <ul class="grid gap-3 text-sm text-slate-600">
                    <li>{{ __('lang_v1.opening_note_why_cost') }}</li>
                    <li>{{ __('lang_v1.opening_note_per_location') }}</li>
                    <li>{{ __('lang_v1.opening_note_zero_removes') }}</li>
                    <li>{{ __('lang_v1.opening_note_cannot_go_below_sold') }}</li>
                </ul>
            </x-panel>
        </div>
    </div>

    <div class="form-actions">
        <span class="form-actions-spacer">{{ __('lang_v1.required_fields_hint') }}</span>
        <a href="{{ route('opening-stock.index', ['location_id' => $locationId]) }}" class="btn-secondary">
            {{ __('lang_v1.cancel') }}
        </a>

        {{-- Two ways to commit, same as the product create form: this one saves the
             opening position and then hands you the group-price grid, because the
             three things are usually entered in one sitting — create the product,
             state what is on the shelf, then price it per customer group. It stays
             secondary even though it does more, and it is hidden without
             `product.update`, which is what the group-price screen demands. --}}
        @can('product.update')
            <button type="submit" name="submit_type" value="submit_n_add_selling_prices" class="btn-secondary">
                <x-nav-icon name="tag"/>
                {{ __('lang_v1.save_and_add_selling_prices') }}
            </button>
        @endcan

        <button type="submit" name="submit_type" value="save" class="btn-primary">
            <x-nav-icon name="save"/>
            {{ __('lang_v1.save') }}
        </button>
    </div>
</form>

{{-- Outside the editor form: a nested form is invalid HTML, and this one must not
     carry the quantity fields with it. --}}
@if ($document)
    <form method="POST" id="withdraw-form" class="hidden"
          action="{{ route('opening-stock.destroy', $product->id) }}"
          data-confirm="{{ __('lang_v1.confirm_delete_opening_stock') }}">
        @csrf
        @method('DELETE')
        <input type="hidden" name="location_id" value="{{ $locationId }}">
    </form>
@endif
@endsection
