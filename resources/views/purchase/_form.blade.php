{{--
    Purchase-side document form, shared by purchases, orders and requisitions.

    The line editor is a small vanilla-JS table: rows are added by picking a
    product, and each row recomputes its own subtotal. Everything is submitted as
    `lines[n][...]` so PurchaseService receives the same shape regardless of which
    document type is being edited.

    New rows are cloned from the <template> at the bottom, which renders
    purchase._line — the same partial the edit form uses for existing lines. See
    that file for why the row markup lives in one place.
--}}
@php
    $document = $document ?? null;
    $isEdit = ! is_null($document);

    $lineArgs = ['lotTracking' => $lotTracking, 'expiryTracking' => $expiryTracking];

    /* Product, quantity, cost, [lot], [expiry], subtotal, remove. The total row
       spans everything up to the subtotal column. */
    $spanBeforeTotal = 2 + ($lotTracking ? 1 : 0) + ($expiryTracking ? 1 : 0);
    $columnCount = $spanBeforeTotal + 2;

    $hasLines = $isEdit && $document->purchase_lines->isNotEmpty();
@endphp

<div class="grid gap-6 lg:grid-cols-4">

    <x-panel :title="__('lang_v1.document_details')" icon="receipt" class="lg:col-span-3">
        <div class="form-grid-3">
            <div class="field">
                <label for="contact_id" class="label label-required">{{ __('lang_v1.supplier') }}</label>
                <select id="contact_id" name="contact_id"
                        @class(['select', 'input-invalid' => $errors->has('contact_id')]) required>
                    <option value="">—</option>
                    @foreach ($suppliers as $id => $name)
                        <option value="{{ $id }}"
                            @selected(old('contact_id', $document->contact_id ?? '') == $id)>{{ $name }}</option>
                    @endforeach
                </select>
                @error('contact_id')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="location_id" class="label label-required">{{ __('lang_v1.location') }}</label>
                <select id="location_id" name="location_id"
                        @class(['select', 'input-invalid' => $errors->has('location_id')]) required>
                    @foreach ($locations as $id => $name)
                        <option value="{{ $id }}"
                            @selected(old('location_id', $document->location_id ?? '') == $id)>{{ $name }}</option>
                    @endforeach
                </select>
                @error('location_id')<p class="field-error">{{ $message }}</p>@enderror
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
                <label for="status" class="label label-required">{{ __('lang_v1.status') }}</label>
                <select id="status" name="status" class="select" required>
                    @foreach ($statuses as $value => $name)
                        <option value="{{ $value }}"
                            @selected(old('status', $document->status ?? array_key_first($statuses)) === $value)>
                            {{ $name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="delivery_date" class="label">{{ __('lang_v1.expected_delivery') }}</label>
                <input type="date" id="delivery_date" name="delivery_date" class="input"
                       value="{{ old('delivery_date', $document?->delivery_date?->toDateString() ?? '') }}">
            </div>
        </div>
    </x-panel>

    <x-panel :title="__('lang_v1.payment_terms')" icon="calendar" class="self-start">
        <div class="grid gap-4">
            {{-- Number and unit are one answer ("30 days"), so they share a row and
                 the unit carries no second label — a repeated "Pay term" above an
                 empty-headed select reads as two separate questions. --}}
            <div class="grid grid-cols-2 gap-3">
                <div class="field">
                    <label for="pay_term_number" class="label">{{ __('lang_v1.pay_term') }}</label>
                    <input id="pay_term_number" name="pay_term_number" class="input-numeric" inputmode="numeric"
                           value="{{ old('pay_term_number', $document->pay_term_number ?? '') }}">
                </div>
                <div class="field self-end">
                    <select id="pay_term_type" name="pay_term_type" class="select"
                            aria-label="{{ __('lang_v1.pay_term') }}">
                        <option value="">—</option>
                        <option value="days" @selected(old('pay_term_type', $document->pay_term_type ?? '') === 'days')>
                            {{ __('lang_v1.days') }}
                        </option>
                        <option value="months" @selected(old('pay_term_type', $document->pay_term_type ?? '') === 'months')>
                            {{ __('lang_v1.months') }}
                        </option>
                    </select>
                </div>
            </div>

            {{-- Instalment schedule: percentages must total ≤ 100. --}}
            <div class="field">
                <p class="label">{{ __('lang_v1.instalments') }}</p>

                @php $terms = $isEdit ? $document->terms : collect(); @endphp

                <div class="grid gap-2">
                    @for ($i = 0; $i < 3; $i++)
                        @php $term = $terms[$i] ?? null; @endphp
                        <div class="grid grid-cols-2 gap-2">
                            <input name="terms[{{ $i }}][payment_term]" class="input-numeric" inputmode="decimal"
                                   placeholder="%" value="{{ $term->payment_term ?? '' }}"
                                   aria-label="{{ __('lang_v1.instalments').' '.($i + 1).' — %' }}">
                            <input type="date" name="terms[{{ $i }}][due_date]" class="input"
                                   value="{{ $term?->due_date?->toDateString() ?? '' }}"
                                   aria-label="{{ __('lang_v1.instalments').' '.($i + 1).' — '.__('lang_v1.due_date') }}">
                        </div>
                    @endfor
                </div>

                <p class="hint">{{ __('lang_v1.instalments_hint') }}</p>
            </div>
        </div>
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
                    <th class="th-numeric w-28">{{ __('lang_v1.quantity') }}</th>
                    <th class="th-numeric w-32">{{ __('lang_v1.unit_cost') }}</th>
                    @if ($lotTracking)
                        <th class="w-32">{{ __('lang_v1.lot_number') }}</th>
                    @endif
                    @if ($expiryTracking)
                        <th class="w-36">{{ __('lang_v1.exp_date') }}</th>
                    @endif
                    <th class="th-numeric w-32">{{ __('lang_v1.subtotal') }}</th>
                    <th class="w-12"><span class="sr-only">{{ __('lang_v1.actions') }}</span></th>
                </tr>
            </thead>

            <tbody id="lines-body">
                @if ($isEdit)
                    @foreach ($document->purchase_lines as $index => $line)
                        @include('purchase._line', $lineArgs + ['index' => $index, 'line' => $line])
                    @endforeach
                @endif
            </tbody>

            <tfoot>
                <tr>
                    <td colspan="{{ $spanBeforeTotal }}">{{ __('lang_v1.total') }}</td>
                    <td class="cell-numeric" id="lines-total">0</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>

    {{-- Toggled by the editor, not by Blade, once rows exist. --}}
    <div id="lines-empty" class="{{ $hasLines ? 'hidden' : '' }}">
        <x-empty-state icon="box" :title="__('lang_v1.no_items_yet')"
                       :text="__('lang_v1.search_product_to_add')" compact/>
    </div>
</x-panel>

{{-- Totals and payment --}}
<div class="mt-6 grid gap-6 lg:grid-cols-2">

    <x-panel :title="__('lang_v1.charges_and_discount')" icon="percent">
        <div class="form-grid">
            <div class="field">
                <label for="discount_type" class="label">{{ __('lang_v1.discount_type') }}</label>
                <select id="discount_type" name="discount_type" class="select">
                    <option value="">—</option>
                    <option value="percentage" @selected(old('discount_type', $document->discount_type ?? '') === 'percentage')>
                        {{ __('lang_v1.percentage') }}
                    </option>
                    <option value="fixed" @selected(old('discount_type', $document->discount_type ?? '') === 'fixed')>
                        {{ __('lang_v1.fixed') }}
                    </option>
                </select>
            </div>

            <div class="field">
                <label for="discount_amount" class="label">{{ __('lang_v1.discount_amount') }}</label>
                <input id="discount_amount" name="discount_amount" class="input-numeric" inputmode="decimal"
                       value="{{ old('discount_amount', $document->discount_amount ?? 0) }}">
            </div>

            <div class="field">
                <label for="tax_id" class="label">{{ __('lang_v1.order_tax') }}</label>
                <select id="tax_id" name="tax_id" class="select">
                    @foreach ($taxes as $id => $name)
                        <option value="{{ $id }}" @selected(old('tax_id', $document->tax_id ?? '') == $id)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="shipping_charges" class="label">{{ __('lang_v1.shipping_charges') }}</label>
                <input id="shipping_charges" name="shipping_charges" class="input-numeric" inputmode="decimal"
                       value="{{ old('shipping_charges', $document->shipping_charges ?? 0) }}">
            </div>

            <div class="field sm:col-span-2">
                <label for="additional_notes" class="label">{{ __('lang_v1.notes') }}</label>
                <textarea id="additional_notes" name="additional_notes" rows="2"
                          class="textarea">{{ old('additional_notes', $document->additional_notes ?? '') }}</textarea>
            </div>
        </div>
    </x-panel>

    @unless ($isEdit)
        {{-- Payments are added from the document screen after saving, so the edit
             form never rewrites existing payment rows. --}}
        <x-panel :title="__('lang_v1.payment')" icon="cash">
            <div class="form-grid">
                <div class="field">
                    <label for="payment_amount" class="label">{{ __('lang_v1.amount') }}</label>
                    <input id="payment_amount" name="payments[0][amount]" class="input-numeric"
                           inputmode="decimal" value="{{ old('payments.0.amount') }}">
                </div>

                <div class="field">
                    <label for="payment_method" class="label">{{ __('lang_v1.payment_method') }}</label>
                    <select id="payment_method" name="payments[0][method]" class="select">
                        @foreach ($paymentMethods as $value => $name)
                            <option value="{{ $value }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="field sm:col-span-2">
                    <label for="payment_account" class="label">{{ __('lang_v1.payment_account') }}</label>
                    <select id="payment_account" name="payments[0][account_id]" class="select">
                        @foreach ($accounts as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </x-panel>
    @endunless
</div>

{{-- The row the editor clones. Inert markup: nothing inside a <template> is
     submitted, so the '__i__' placeholder cannot reach the server. --}}
<template id="line-template">
    @include('purchase._line', $lineArgs + ['index' => '__i__', 'line' => null])
</template>

@push('scripts')
<script>
(function () {
    const body = document.getElementById('lines-body');
    const empty = document.getElementById('lines-empty');
    const search = document.getElementById('product-search');
    const totalCell = document.getElementById('lines-total');
    const template = document.getElementById('line-template');

    let index = body.querySelectorAll('tr').length;
    let picked = null;

    const recalc = function () {
        let total = 0;

        body.querySelectorAll('tr').forEach(function (row) {
            const qty = parseFloat(row.querySelector('[data-qty]')?.value) || 0;
            const cost = parseFloat(row.querySelector('[data-cost]')?.value) || 0;
            const subtotal = qty * cost;

            row.querySelector('[data-subtotal]').textContent = subtotal.toFixed(2);

            total += subtotal;
        });

        totalCell.textContent = total.toFixed(2);
        empty.classList.toggle('hidden', body.querySelectorAll('tr').length > 0);
    };

    const addRow = function (product) {
        const i = index++;
        const row = template.content.cloneNode(true);

        // The template's names carry a placeholder index; every clone claims its own.
        row.querySelectorAll('[name]').forEach(function (field) {
            field.name = field.name.replace('__i__', i);
        });

        row.querySelector('[data-variation]').value = product.variation_id;
        row.querySelector('[data-name]').textContent = product.text;
        row.querySelector('[data-sku]').textContent = product.sku ?? '';
        row.querySelector('[data-cost]').value = product.purchase_price ?? 0;
        row.querySelector('[data-cost-inc]').value = product.purchase_price ?? 0;

        body.appendChild(row);
        recalc();
    };

    // Product lookup: same endpoint the POS uses.
    let timer = null;
    search.addEventListener('input', function () {
        clearTimeout(timer);
        const term = search.value.trim();
        if (term.length < 2) { picked = null; return; }

        timer = setTimeout(async function () {
            const params = new URLSearchParams({
                term: term,
                location_id: document.getElementById('location_id').value,
            });

            const response = await fetch('{{ route('products.list') }}?' + params, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (!response.ok) return;

            const results = await response.json();
            picked = results[0] ?? null;

            // Exactly one hit on a scanned barcode → add immediately.
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
