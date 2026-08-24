{{--
    Stock adjustment form, shared by create and edit.

    Same line-editor shape as purchase._form — rows cloned from a <template>,
    submitted as `lines[n][...]` — with one thing the purchase editor does not
    need: an "available" column, refreshed from the product lookup, because a
    write-off is bounded by what is actually on the shelf and the service refuses
    rather than letting the quantity go negative. Better to see the ceiling while
    typing than to be told about it after saving.
--}}
@php
    $document = $document ?? null;
    $isEdit = ! is_null($document);

    $selectedLocation = (int) old('location_id', $document->location_id ?? array_key_first($locations));

    $lineArgs = ['locationId' => $selectedLocation];

    $hasLines = $isEdit && $document->stock_adjustment_lines->isNotEmpty();
@endphp

<div class="grid gap-6 lg:grid-cols-3">

    <x-panel :title="__('lang_v1.adjustment_details')" icon="adjust" class="lg:col-span-2">
        <div class="form-grid-3">
            <div class="field">
                <label for="location_id" class="label label-required">{{ __('lang_v1.location') }}</label>
                <select id="location_id" name="location_id"
                        @class(['select', 'input-invalid' => $errors->has('location_id')]) required>
                    @foreach ($locations as $id => $name)
                        <option value="{{ $id }}" @selected($selectedLocation == $id)>{{ $name }}</option>
                    @endforeach
                </select>
                @error('location_id')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="adjustment_type" class="label label-required">{{ __('lang_v1.adjustment_type') }}</label>
                <select id="adjustment_type" name="adjustment_type"
                        @class(['select', 'input-invalid' => $errors->has('adjustment_type')]) required>
                    @foreach ($types as $value => $name)
                        <option value="{{ $value }}"
                            @selected(old('adjustment_type', $document->adjustment_type ?? 'normal') === $value)>
                            {{ $name }}
                        </option>
                    @endforeach
                </select>
                {{-- The distinction drives the reports, so it is explained where it
                     is asked rather than in a manual nobody opens. --}}
                <p class="hint">{{ __('lang_v1.adjustment_type_hint') }}</p>
            </div>

            <div class="field">
                <label for="ref_no" class="label">{{ __('lang_v1.reference_no') }}</label>
                <input id="ref_no" name="ref_no" class="input force-ltr"
                       value="{{ old('ref_no', $document->ref_no ?? '') }}"
                       placeholder="{{ __('lang_v1.auto_generated') }}">
            </div>

            <div class="field">
                <label for="transaction_date" class="label label-required">{{ __('lang_v1.date') }}</label>
                <input type="datetime-local" id="transaction_date" name="transaction_date"
                       @class(['input', 'input-invalid' => $errors->has('transaction_date')])
                       value="{{ old('transaction_date', ($document->transaction_date ?? now())->format('Y-m-d\TH:i')) }}"
                       required>
                @error('transaction_date')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="total_amount_recovered" class="label">{{ __('lang_v1.amount_recovered') }}</label>
                <input id="total_amount_recovered" name="total_amount_recovered"
                       class="input-numeric" inputmode="decimal"
                       value="{{ old('total_amount_recovered', $document->total_amount_recovered ?? 0) }}">
                <p class="hint">{{ __('lang_v1.amount_recovered_hint') }}</p>
            </div>

            <div class="field">
                <label for="additional_notes" class="label">{{ __('lang_v1.reason') }}</label>
                <input id="additional_notes" name="additional_notes" class="input"
                       value="{{ old('additional_notes', $document->additional_notes ?? '') }}"
                       placeholder="{{ __('lang_v1.adjustment_reason_placeholder') }}">
            </div>
        </div>
    </x-panel>

    {{-- Not decoration: "why can't I add stock here?" is the first question this
         screen gets, and answering it in place is cheaper than answering it in
         support. --}}
    <x-panel :title="__('lang_v1.how_this_works')" icon="info" class="self-start" quiet>
        <ul class="grid gap-3 text-sm text-slate-600">
            <li>{{ __('lang_v1.adjustment_note_decrease_only') }}</li>
            <li>{{ __('lang_v1.adjustment_note_never_past_zero') }}</li>
            <li>{{ __('lang_v1.adjustment_note_valued_at_cost') }}</li>
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
                    <th class="th-numeric w-28">{{ __('lang_v1.available') }}</th>
                    <th class="th-numeric w-28">{{ __('lang_v1.quantity') }}</th>
                    <th class="w-12"><span class="sr-only">{{ __('lang_v1.actions') }}</span></th>
                </tr>
            </thead>

            <tbody id="lines-body">
                @if ($isEdit)
                    @foreach ($document->stock_adjustment_lines as $index => $line)
                        @include('stock_adjustment._line', $lineArgs + ['index' => $index, 'line' => $line])
                    @endforeach
                @endif
            </tbody>

            <tfoot>
                <tr>
                    <td colspan="2">{{ __('lang_v1.total_quantity') }}</td>
                    <td class="cell-numeric" id="lines-total">0</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div id="lines-empty" class="{{ $hasLines ? 'hidden' : '' }}">
        <x-empty-state icon="box" :title="__('lang_v1.no_items_yet')"
                       :text="__('lang_v1.search_product_to_add')" compact/>
    </div>
</x-panel>

<template id="line-template">
    @include('stock_adjustment._line', $lineArgs + ['index' => '__i__', 'line' => null])
</template>

@push('scripts')
<script>
(function () {
    const body = document.getElementById('lines-body');
    const empty = document.getElementById('lines-empty');
    const search = document.getElementById('product-search');
    const totalCell = document.getElementById('lines-total');
    const template = document.getElementById('line-template');
    const locationSelect = document.getElementById('location_id');

    let index = body.querySelectorAll('tr').length;
    let picked = null;

    /* Quantity, not money: see the note in _line.blade.php on why this editor
       shows no valuation. */
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

    let timer = null;
    search.addEventListener('input', function () {
        clearTimeout(timer);
        const term = search.value.trim();
        if (term.length < 2) { picked = null; return; }

        timer = setTimeout(async function () {
            const params = new URLSearchParams({
                term: term,
                location_id: locationSelect.value,
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

    /* Every "available" figure on screen was read at one location. Switching
       location makes them all wrong, and a stale number here is worse than no
       number — it is the one thing the user is trusting. */
    locationSelect.addEventListener('change', function () {
        body.querySelectorAll('[data-available]').forEach(function (cell) {
            cell.textContent = '';
        });
        picked = null;
        search.value = '';
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
