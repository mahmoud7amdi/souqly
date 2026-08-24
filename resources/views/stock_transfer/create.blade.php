@extends('layouts.app')
@section('title', __('lang_v1.add_stock_transfer'))
@section('page_title', __('lang_v1.add_stock_transfer'))

@section('content')

@php
    $selectedFrom = (int) old('location_id', array_key_first($locations));
@endphp

<x-page-head :back="route('stock-transfers.index')" :backLabel="__('lang_v1.stock_transfers')"/>

<form method="POST" action="{{ route('stock-transfers.store') }}">
    @csrf

    <div class="grid gap-6 lg:grid-cols-3">

        <x-panel :title="__('lang_v1.transfer_details')" icon="transfer" class="lg:col-span-2">
            <div class="form-grid-3">
                {{-- From and to are the whole document, so they lead — and they are
                     adjacent, because the mistake this screen has to prevent is
                     picking them the wrong way round. --}}
                <div class="field">
                    <label for="location_id" class="label label-required">{{ __('lang_v1.transfer_from_location') }}</label>
                    <select id="location_id" name="location_id"
                            @class(['select', 'input-invalid' => $errors->has('location_id')]) required>
                        @foreach ($locations as $id => $name)
                            <option value="{{ $id }}" @selected($selectedFrom == $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                    @error('location_id')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="transfer_location_id" class="label label-required">{{ __('lang_v1.transfer_to_location') }}</label>
                    <select id="transfer_location_id" name="transfer_location_id"
                            @class(['select', 'input-invalid' => $errors->has('transfer_location_id')]) required>
                        <option value="">—</option>
                        @foreach ($locations as $id => $name)
                            <option value="{{ $id }}"
                                @selected(old('transfer_location_id') == $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                    @error('transfer_location_id')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="status" class="label label-required">{{ __('lang_v1.status') }}</label>
                    <select id="status" name="status" class="select" required>
                        @foreach ($statuses as $value => $name)
                            <option value="{{ $value }}"
                                @selected(old('status', array_key_first($statuses)) === $value)>{{ $name }}</option>
                        @endforeach
                    </select>
                    <p class="hint">{{ __('lang_v1.transfer_status_hint') }}</p>
                </div>

                <div class="field">
                    <label for="ref_no" class="label">{{ __('lang_v1.reference_no') }}</label>
                    <input id="ref_no" name="ref_no" class="input force-ltr"
                           value="{{ old('ref_no') }}"
                           placeholder="{{ __('lang_v1.auto_generated') }}">
                </div>

                <div class="field">
                    <label for="transaction_date" class="label label-required">{{ __('lang_v1.date') }}</label>
                    <input type="datetime-local" id="transaction_date" name="transaction_date"
                           @class(['input', 'input-invalid' => $errors->has('transaction_date')])
                           value="{{ old('transaction_date', now()->format('Y-m-d\TH:i')) }}" required>
                    @error('transaction_date')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="shipping_charges" class="label">{{ __('lang_v1.shipping_charges') }}</label>
                    <input id="shipping_charges" name="shipping_charges" class="input-numeric"
                           inputmode="decimal" value="{{ old('shipping_charges', 0) }}">
                    <p class="hint">{{ __('lang_v1.transfer_freight_hint') }}</p>
                </div>

                <div class="field sm:col-span-2">
                    <label for="shipping_details" class="label">{{ __('lang_v1.shipping_details') }}</label>
                    <input id="shipping_details" name="shipping_details" class="input"
                           value="{{ old('shipping_details') }}"
                           placeholder="{{ __('lang_v1.carrier_or_vehicle') }}">
                </div>

                <div class="field">
                    <label for="additional_notes" class="label">{{ __('lang_v1.notes') }}</label>
                    <input id="additional_notes" name="additional_notes" class="input"
                           value="{{ old('additional_notes') }}">
                </div>
            </div>
        </x-panel>

        <x-panel :title="__('lang_v1.how_this_works')" icon="info" class="self-start" quiet>
            <ul class="grid gap-3 text-sm text-slate-600">
                <li>{{ __('lang_v1.transfer_note_two_documents') }}</li>
                <li>{{ __('lang_v1.transfer_note_in_transit') }}</li>
                <li>{{ __('lang_v1.transfer_note_at_cost') }}</li>
                <li>{{ __('lang_v1.transfer_note_no_edit') }}</li>
            </ul>
        </x-panel>
    </div>

    {{-- Line items --}}
    <x-panel :title="__('lang_v1.items')" icon="box" class="mt-6" flush>
        <x-slot:actions>
            <div class="input-search-wrap">
                <span class="input-search-icon"><x-nav-icon name="search" :size="4"/></span>
                <input id="product-search" class="input-search w-72"
                       placeholder="{{ __('lang_v1.search_product_to_add') }}"
                       autocomplete="off" aria-label="{{ __('lang_v1.search_product_to_add') }}">
            </div>
            <button type="button" id="add-line" class="btn-secondary">
                <x-nav-icon name="plus" :size="4"/>
                {{ __('lang_v1.add') }}
            </button>
        </x-slot:actions>

        <div class="table-wrap table-flush">
            <table class="table" id="lines-table">
                <thead>
                    <tr>
                        <th>{{ __('lang_v1.product') }}</th>
                        <th class="th-numeric w-32">{{ __('lang_v1.available_at_source') }}</th>
                        <th class="th-numeric w-28">{{ __('lang_v1.quantity') }}</th>
                        <th class="w-12"><span class="sr-only">{{ __('lang_v1.actions') }}</span></th>
                    </tr>
                </thead>

                <tbody id="lines-body"></tbody>

                <tfoot>
                    <tr>
                        <td colspan="2">{{ __('lang_v1.total_quantity') }}</td>
                        <td class="cell-numeric" id="lines-total">0</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div id="lines-empty">
            <x-empty-state icon="box" :title="__('lang_v1.no_items_yet')"
                           :text="__('lang_v1.search_product_to_add')" compact/>
        </div>
    </x-panel>

    <div class="form-actions">
        <span class="form-actions-spacer">{{ __('lang_v1.required_fields_hint') }}</span>
        <a href="{{ route('stock-transfers.index') }}" class="btn-secondary">{{ __('lang_v1.cancel') }}</a>
        <button type="submit" class="btn-primary">
            <x-nav-icon name="save"/>
            {{ __('lang_v1.save') }}
        </button>
    </div>
</form>

<template id="line-template">
    @include('stock_transfer._line', ['index' => '__i__', 'line' => null])
</template>

@push('scripts')
<script>
(function () {
    const body = document.getElementById('lines-body');
    const empty = document.getElementById('lines-empty');
    const search = document.getElementById('product-search');
    const totalCell = document.getElementById('lines-total');
    const template = document.getElementById('line-template');
    const source = document.getElementById('location_id');
    const destination = document.getElementById('transfer_location_id');

    let index = 0;
    let picked = null;

    const recalc = function () {
        let total = 0;

        body.querySelectorAll('tr').forEach(function (row) {
            total += parseFloat(row.querySelector('[data-qty]')?.value) || 0;
        });

        totalCell.textContent = total.toFixed(2);
        empty.classList.toggle('hidden', body.querySelectorAll('tr').length > 0);
    };

    const addRow = function (product) {
        const i = index++;
        const row = template.content.cloneNode(true);

        row.querySelectorAll('[name]').forEach(function (field) {
            field.name = field.name.replace('__i__', i);
        });

        row.querySelector('[data-variation]').value = product.variation_id;
        row.querySelector('[data-name]').textContent = product.text;
        row.querySelector('[data-sku]').textContent = product.sku ?? '';
        row.querySelector('[data-available]').textContent =
            product.qty_available === null || product.qty_available === undefined
                ? '' : product.qty_available;

        body.appendChild(row);
        recalc();
    };

    /* The lookup is scoped to the SOURCE location — the quantities it returns are
       the ones the transfer is bounded by, and a product the destination happens
       to stock is irrelevant if the source has none of it. */
    let timer = null;
    search.addEventListener('input', function () {
        clearTimeout(timer);
        const term = search.value.trim();
        if (term.length < 2) { picked = null; return; }

        timer = setTimeout(async function () {
            const params = new URLSearchParams({
                term: term,
                location_id: source.value,
            });

            const response = await fetch('{{ route('products.list') }}?' + params, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (!response.ok) return;

            const results = await response.json();
            picked = results[0] ?? null;

            if (results.length === 1 && results[0].sku === term) {
                addRow(results[0]);
                search.value = '';
                picked = null;
            }
        }, 250);
    });

    document.getElementById('add-line').addEventListener('click', function () {
        if (picked) {
            addRow(picked);
            search.value = '';
            picked = null;
            search.focus();
        }
    });

    search.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            document.getElementById('add-line').click();
        }
    });

    /* Changing the source invalidates every quantity on screen, and a stale
       "available" is worse than a blank one: it is the number being trusted. The
       rows themselves are kept — the products are usually still the right ones. */
    source.addEventListener('change', function () {
        body.querySelectorAll('[data-available]').forEach(function (cell) {
            cell.textContent = '';
        });
        picked = null;
        search.value = '';

        // Same shop on both sides is refused by the server; saying so here saves
        // a round trip and a lost form.
        if (destination.value === source.value) {
            destination.value = '';
        }
    });

    destination.addEventListener('change', function () {
        if (destination.value && destination.value === source.value) {
            destination.setCustomValidity('{{ __('lang_v1.transfer_needs_two_locations') }}');
        } else {
            destination.setCustomValidity('');
        }
    });

    body.addEventListener('input', recalc);
    body.addEventListener('click', function (event) {
        if (event.target.closest('[data-remove]')) {
            event.target.closest('tr').remove();
            recalc();
        }
    });

    recalc();
})();
</script>
@endpush
@endsection
