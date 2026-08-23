@extends('layouts.app')
@section('title', __('lang_v1.print_labels'))
@section('page_title', __('lang_v1.print_labels'))

@section('content')

<x-page-head :back="route('products.index')" :backLabel="__('lang_v1.products')"/>

{{--
    target="_blank": the sheet is a print artefact, not the next step of this
    form — you come straight back here to build the next batch, so the builder
    must survive the print.

    The <form> wraps the whole grid rather than the picker column: .form-actions
    reaches out to the main padding edges with negative margins, which bleed
    sideways if it is nested inside a grid column.
--}}
<form method="POST" action="{{ route('labels.preview') }}" target="_blank" id="label-form">
    @csrf

    <div class="grid gap-6 lg:grid-cols-3">

        <x-panel :title="__('lang_v1.products')" icon="box" class="lg:col-span-2" flush>
            <div class="card-body">
                <div class="filter-grid">
                    <div class="field">
                        <label for="filter_category" class="label">{{ __('lang_v1.category') }}</label>
                        <select id="filter_category" class="select">
                            @foreach ($categories as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="field">
                        <label for="filter_brand" class="label">{{ __('lang_v1.brand') }}</label>
                        <select id="filter_brand" class="select">
                            @foreach ($brands as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="field sm:col-span-2 lg:col-span-2">
                        <label for="filter_term" class="label">{{ __('lang_v1.search') }}</label>
                        <div class="flex gap-2">
                            <div class="input-search-wrap grow">
                                <span class="input-search-icon"><x-nav-icon name="search" :size="4"/></span>
                                <input id="filter_term" class="input-search w-full"
                                       placeholder="{{ __('lang_v1.name_or_sku') }}" autocomplete="off">
                            </div>
                            <button type="button" id="load-products" class="btn-secondary">
                                <x-nav-icon name="refresh" :size="4"/>
                                {{ __('lang_v1.load') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="table-wrap table-flush">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('lang_v1.product') }}</th>
                            <th class="th-numeric w-32">{{ __('lang_v1.price') }}</th>
                            <th class="th-numeric w-28">{{ __('lang_v1.copies') }}</th>
                        </tr>
                    </thead>
                    <tbody id="label-rows"></tbody>
                </table>
            </div>

            {{-- Two empty states, not one message reworded by JS: "press Load" and
                 "that filter matched nothing" are different situations, and only
                 the second means the user has to change something. --}}
            <div id="label-hint">
                <x-empty-state icon="barcode" :title="__('lang_v1.load_products_hint')" compact/>
            </div>
            <div id="label-none" class="hidden">
                <x-empty-state icon="search" :title="__('lang_v1.no_records_found')" compact/>
            </div>
        </x-panel>

        <x-panel :title="__('lang_v1.label_options')" icon="printer" class="self-start">
            <div class="grid gap-5">
                <div class="field">
                    <label for="barcode_setting_id" class="label label-required">
                        {{ __('lang_v1.sticker_sheet') }}
                    </label>
                    <select id="barcode_setting_id" name="barcode_setting_id"
                            @class(['select', 'input-invalid' => $errors->has('barcode_setting_id')]) required>
                        @foreach ($sheets as $id => $name)
                            <option value="{{ $id }}" @selected(old('barcode_setting_id') == $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                    @error('barcode_setting_id')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                {{-- What goes on the sticker. The barcode itself is not optional —
                     a label without one is a price tag, which this screen is not. --}}
                <div class="grid gap-1">
                    @foreach ([
                        'show_name' => __('lang_v1.show_product_name'),
                        'show_price' => __('lang_v1.show_price'),
                        'show_business_name' => __('lang_v1.show_business_name'),
                    ] as $field => $labelText)
                        <label class="checkbox-row">
                            <input type="checkbox" name="{{ $field }}" value="1" class="checkbox"
                                   @checked($field !== 'show_business_name')>
                            <span class="checkbox-label">{{ $labelText }}</span>
                        </label>
                    @endforeach
                </div>

                <p class="hint mt-0">{{ __('lang_v1.labels_max_copies_hint') }}</p>
            </div>
        </x-panel>
    </div>

    {{-- Revealed by the submit guard below, so the failure is stated here rather
         than in the print tab the browser would otherwise open on a 422. --}}
    <p id="label-warning" class="alert-danger mt-6 hidden">
        {{ __('lang_v1.labels_none_requested') }}
    </p>

    <div class="form-actions">
        <span class="form-actions-spacer">
            {{ __('lang_v1.labels') }}: <span id="label-total" class="force-ltr font-mono">0</span>
        </span>
        <button type="submit" class="btn-primary">
            <x-nav-icon name="printer"/>
            {{ __('lang_v1.preview_and_print') }}
        </button>
    </div>
</form>

{{-- The row the picker clones. Inert markup: nothing inside a <template> is
     form-associated, so the '__i__' placeholder cannot reach the server. --}}
<template id="label-row-template">
    <tr>
        <td>
            <span class="cell-primary" data-name></span>
            <span class="cell-meta force-ltr" data-sku></span>
        </td>
        <td class="cell-numeric" data-price></td>
        <td>
            <input type="hidden" name="variations[__i__][variation_id]" data-variation>
            <input type="number" name="variations[__i__][quantity]" class="input-numeric w-24"
                   value="0" min="0" max="500" step="1" inputmode="numeric" data-quantity>
        </td>
    </tr>
</template>

@push('scripts')
<script>
(function () {
    const form = document.getElementById('label-form');
    const rows = document.getElementById('label-rows');
    const hint = document.getElementById('label-hint');
    const none = document.getElementById('label-none');
    const warning = document.getElementById('label-warning');
    const totalCell = document.getElementById('label-total');
    const template = document.getElementById('label-row-template');

    /* How many stickers this will actually print. A sheet holds a fixed number
       per page, so the count is what tells you whether you are about to spend
       one sheet or nine. */
    const recount = function () {
        let total = 0;

        rows.querySelectorAll('[data-quantity]').forEach(function (field) {
            total += Math.max(0, parseInt(field.value, 10) || 0);
        });

        totalCell.textContent = total;

        if (total > 0) {
            warning.classList.add('hidden');
        }
    };

    document.getElementById('load-products').addEventListener('click', async function () {
        const params = new URLSearchParams({
            category_id: document.getElementById('filter_category').value,
            brand_id: document.getElementById('filter_brand').value,
            term: document.getElementById('filter_term').value,
        });

        const response = await fetch('{{ route('labels.products') }}?' + params, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });

        if (!response.ok) return;

        const products = await response.json();

        rows.innerHTML = '';
        hint.classList.add('hidden');
        none.classList.toggle('hidden', products.length > 0);

        products.forEach(function (product, index) {
            const row = template.content.cloneNode(true);

            // The template's names carry a placeholder index; every clone claims its own.
            row.querySelectorAll('[name]').forEach(function (field) {
                field.name = field.name.replace('__i__', index);
            });

            row.querySelector('[data-variation]').value = product.variation_id;
            row.querySelector('[data-name]').textContent = product.text;
            row.querySelector('[data-sku]').textContent = product.sku ?? '';
            row.querySelector('[data-price]').textContent = product.price;
            row.querySelector('[data-quantity]').setAttribute(
                'aria-label', @json(__('lang_v1.copies')) + ' — ' + product.text
            );

            rows.appendChild(row);
        });

        recount();
    });

    rows.addEventListener('input', recount);

    /* A row left at zero is not a request for zero labels — it is a row the user
       skipped. The server validates min:1 per row, so those rows have to be left
       out of the submission rather than sent as noughts; disabled fields are not
       submitted, and they are re-enabled immediately afterwards because this form
       prints into a new tab and stays open for the next batch. */
    form.addEventListener('submit', function (event) {
        const skipped = [];

        rows.querySelectorAll('[data-quantity]').forEach(function (field) {
            if ((parseInt(field.value, 10) || 0) >= 1) return;

            skipped.push(field, field.closest('tr').querySelector('[data-variation]'));
        });

        skipped.forEach(function (field) { field.disabled = true; });

        const requested = rows.querySelectorAll('[data-quantity]:not([disabled])').length;

        if (requested === 0) {
            event.preventDefault();
            warning.classList.remove('hidden');
            rows.querySelector('[data-quantity]')?.focus();
        }

        setTimeout(function () {
            skipped.forEach(function (field) { field.disabled = false; });
        }, 0);
    });
})();
</script>
@endpush
@endsection
