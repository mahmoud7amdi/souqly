{{--
    Sell-side document form, shared by sales, quotations, drafts and sales orders.

    Mirrors purchase._form deliberately — same line-editor mechanics, same
    <template> clone, same `lines[n][...]` submission shape — so the two sides of
    the ledger read as one system. Where it diverges, it is because the sell side
    genuinely has more to say: a price group changes what the products cost, and a
    sale can be raised against orders the customer placed earlier.

    The totals panel computes exactly what SellService::recalculateTotals() will
    save: (quantity × unit price) summed, less the document discount, plus order
    tax on the discounted figure, plus shipping and rounding. It is a preview of
    the invoice, not an approximation of one.
--}}
@php
    $document = $document ?? null;
    $isEdit = ! is_null($document);

    /* Product, quantity, unit price, note, subtotal, remove. The total row spans
       everything up to the subtotal column. */
    $spanBeforeTotal = 4;
    $columnCount = $spanBeforeTotal + 2;

    $hasLines = $isEdit && $document->sell_lines->isNotEmpty();

    /* A sales order is a promise, not a delivery: it has no shipping to record
       and cannot itself be a quotation. */
    $canQuote = ! $isSalesOrder;

    /* Importing order lines into a document that already exists would fulfil the
       same order twice — the outstanding quantity was consumed when it was first
       raised. So the picker is a create-time control only. */
    $canLinkOrders = ! $isSalesOrder && ! $isEdit;

    $showShipping = ! $isSalesOrder;
@endphp

<div class="grid gap-6 lg:grid-cols-4">

    <x-panel :title="__('lang_v1.document_details')" icon="receipt" class="lg:col-span-3">
        <div class="form-grid-3">
            <div class="field">
                <label for="contact_id" class="label label-required">{{ __('lang_v1.customer') }}</label>
                <select id="contact_id" name="contact_id"
                        @class(['select', 'input-invalid' => $errors->has('contact_id')]) required>
                    <option value="">—</option>
                    @foreach ($customers as $id => $name)
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
                <label for="invoice_no" class="label">{{ __('lang_v1.invoice_no') }}</label>
                <input id="invoice_no" name="invoice_no" class="input force-ltr"
                       value="{{ old('invoice_no', $document->invoice_no ?? '') }}"
                       placeholder="{{ __('lang_v1.auto_generated') }}">
            </div>
        </div>

        @if ($canQuote)
            {{-- Full width and below the grid: this one control changes what the
                 document IS, so it should not read as the seventh field in a row
                 of six. --}}
            <label class="checkbox-row mt-4 border-t border-slate-200 pt-4">
                <input type="hidden" name="is_quotation" value="0">
                <input type="checkbox" name="is_quotation" value="1" class="checkbox"
                       @checked(old('is_quotation', $document->is_quotation ?? 0))>
                <span class="checkbox-label">
                    {{ __('lang_v1.is_quotation') }}
                    <span class="checkbox-hint">{{ __('lang_v1.is_quotation_hint') }}</span>
                </span>
            </label>
        @endif
    </x-panel>

    <x-panel :title="__('lang_v1.pricing_and_terms')" icon="tag" class="self-start">
        <div class="grid gap-4">
            <div class="field">
                <label for="selling_price_group_id" class="label">{{ __('lang_v1.price_group') }}</label>
                <select id="selling_price_group_id" name="selling_price_group_id" class="select">
                    @foreach ($priceGroups as $id => $name)
                        <option value="{{ $id }}"
                            @selected(old('selling_price_group_id', $document->selling_price_group_id ?? '') == $id)>
                            {{ $name }}
                        </option>
                    @endforeach
                </select>
                {{-- Chosen before the products are picked, because it decides the
                     price each one arrives at. --}}
                <p class="hint">{{ __('lang_v1.price_group_hint') }}</p>
            </div>

            <div class="field">
                <label for="customer_group_id" class="label">{{ __('lang_v1.customer_group') }}</label>
                <select id="customer_group_id" name="customer_group_id" class="select">
                    @foreach ($customerGroups as $id => $name)
                        <option value="{{ $id }}"
                            @selected(old('customer_group_id', $document->customer_group_id ?? '') == $id)>
                            {{ $name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="commission_agent" class="label">{{ __('lang_v1.commission_agent') }}</label>
                <select id="commission_agent" name="commission_agent" class="select">
                    @foreach ($commissionAgents as $id => $name)
                        <option value="{{ $id }}"
                            @selected(old('commission_agent', $document->commission_agent ?? '') == $id)>
                            {{ $name }}
                        </option>
                    @endforeach
                </select>
            </div>

            {{-- When the money is due. Number and unit are one answer ("30 days"),
                 so they share a row and the unit carries no second label — a
                 repeated "Pay term" above an empty-headed select reads as two
                 separate questions. --}}
            <div class="grid grid-cols-2 gap-3 border-t border-slate-200 pt-4">
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
        </div>
    </x-panel>
</div>

@if ($canLinkOrders)
    {{-- Raising an invoice against orders the customer already placed. Picking
         one pulls in only what is still outstanding on it, so part-shipping an
         order twice cannot double-count. --}}
    <x-panel :title="__('lang_v1.link_sales_order')" icon="clipboard" class="mt-6">
        <div class="flex flex-wrap items-end gap-3">
            <div class="field min-w-64 flex-1">
                <label for="sales-order-picker" class="label">{{ __('lang_v1.sales_orders') }}</label>
                <select id="sales-order-picker" class="select" disabled>
                    <option value="">{{ __('lang_v1.select_customer_first') }}</option>
                </select>
            </div>

            <button type="button" id="import-order-lines" class="btn-secondary" disabled>
                <x-nav-icon name="download" :size="4"/>
                {{ __('lang_v1.import') }}
            </button>
        </div>

        <p class="hint">{{ __('lang_v1.link_sales_order_hint') }}</p>

        {{-- Populated as orders are imported; the service reads it to move each
             order's fulfilment status. --}}
        <div id="linked-orders" class="mt-3 flex flex-wrap gap-2"></div>
    </x-panel>
@endif

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
                    <th class="th-numeric w-32">{{ __('lang_v1.unit_price') }}</th>
                    <th class="w-48">{{ __('lang_v1.notes') }}</th>
                    <th class="th-numeric w-32">{{ __('lang_v1.subtotal') }}</th>
                    <th class="w-12"><span class="sr-only">{{ __('lang_v1.actions') }}</span></th>
                </tr>
            </thead>

            <tbody id="lines-body">
                @if ($isEdit)
                    @foreach ($document->sell_lines as $index => $line)
                        @include('sell._line', ['index' => $index, 'line' => $line])
                    @endforeach
                @endif
            </tbody>

            <tfoot>
                <tr>
                    <td colspan="{{ $spanBeforeTotal }}">{{ __('lang_v1.subtotal') }}</td>
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

{{-- Charges, and what they add up to --}}
<div class="mt-6 grid gap-6 lg:grid-cols-3">

    <x-panel :title="__('lang_v1.charges_and_discount')" icon="percent" class="lg:col-span-2">
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

            <div class="field">
                <label for="round_off_amount" class="label">{{ __('lang_v1.round_off') }}</label>
                <input id="round_off_amount" name="round_off_amount" class="input-numeric" inputmode="decimal"
                       value="{{ old('round_off_amount', $document->round_off_amount ?? 0) }}">
            </div>

            <div class="field">
                <label for="additional_notes" class="label">{{ __('lang_v1.notes') }}</label>
                <textarea id="additional_notes" name="additional_notes" rows="2"
                          class="textarea">{{ old('additional_notes', $document->additional_notes ?? '') }}</textarea>
            </div>

            <div class="field sm:col-span-2">
                <label for="staff_note" class="label">{{ __('lang_v1.staff_note') }}</label>
                <textarea id="staff_note" name="staff_note" rows="2"
                          class="textarea">{{ old('staff_note', $document->staff_note ?? '') }}</textarea>
                <p class="hint">{{ __('lang_v1.staff_note_hint') }}</p>
            </div>
        </div>
    </x-panel>

    {{-- The invoice as it stands. Recomputed on every keystroke because a
         discount typed with no visible effect is a discount nobody trusts. --}}
    <x-panel :title="__('lang_v1.total')" icon="calculator" class="self-start">
        <dl class="dl">
            <div class="dl-row">
                <dt class="dl-key">{{ __('lang_v1.subtotal') }}</dt>
                <dd class="dl-value" id="summary-subtotal">0</dd>
            </div>
            <div class="dl-row">
                <dt class="dl-key">{{ __('lang_v1.discount') }}</dt>
                <dd class="dl-value" id="summary-discount">0</dd>
            </div>
            <div class="dl-row">
                <dt class="dl-key">{{ __('lang_v1.order_tax') }}</dt>
                <dd class="dl-value" id="summary-tax">0</dd>
            </div>
            <div class="dl-row">
                <dt class="dl-key">{{ __('lang_v1.shipping_charges') }}</dt>
                <dd class="dl-value" id="summary-shipping">0</dd>
            </div>
            <div class="dl-row">
                <dt class="dl-key">{{ __('lang_v1.round_off') }}</dt>
                <dd class="dl-value" id="summary-round">0</dd>
            </div>
            <div class="dl-total">
                <dt class="dl-key">{{ __('lang_v1.total') }}</dt>
                <dd class="dl-total-value" id="summary-total">0</dd>
            </div>
        </dl>
    </x-panel>
</div>

@if ($showShipping || ! $isEdit)
    <div class="mt-6 grid gap-6 lg:grid-cols-2">

        @if ($showShipping)
            <x-panel :title="__('lang_v1.shipping')" icon="truck">
                <div class="form-grid">
                    <div class="field">
                        <label for="shipping_status" class="label">{{ __('lang_v1.shipping_status') }}</label>
                        <select id="shipping_status" name="shipping_status" class="select">
                            @foreach ($shippingStatuses as $value => $name)
                                <option value="{{ $value }}"
                                    @selected(old('shipping_status', $document->shipping_status ?? '') === $value)>
                                    {{ $name }}
                                </option>
                            @endforeach
                        </select>
                        {{-- Left empty for a counter sale: see shipment.index, which
                             lists only the documents that have one. --}}
                        <p class="hint">{{ __('lang_v1.shipping_status_hint') }}</p>
                    </div>

                    <div class="field">
                        <label for="delivery_date" class="label">{{ __('lang_v1.delivery_date') }}</label>
                        <input type="date" id="delivery_date" name="delivery_date" class="input"
                               value="{{ old('delivery_date', $document?->delivery_date?->toDateString() ?? '') }}">
                    </div>

                    <div class="field">
                        <label for="delivery_person" class="label">{{ __('lang_v1.delivery_person') }}</label>
                        <select id="delivery_person" name="delivery_person" class="select">
                            @foreach ($deliveryPeople as $id => $name)
                                <option value="{{ $id }}"
                                    @selected(old('delivery_person', $document->delivery_person ?? '') == $id)>
                                    {{ $name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="field">
                        <label for="delivered_to" class="label">{{ __('lang_v1.delivered_to') }}</label>
                        <input id="delivered_to" name="delivered_to" class="input"
                               value="{{ old('delivered_to', $document->delivered_to ?? '') }}">
                    </div>

                    <div class="field sm:col-span-2">
                        <label for="shipping_details" class="label">{{ __('lang_v1.shipping_details') }}</label>
                        <input id="shipping_details" name="shipping_details" class="input"
                               value="{{ old('shipping_details', $document->shipping_details ?? '') }}">
                    </div>

                    <div class="field sm:col-span-2">
                        <label for="shipping_address" class="label">{{ __('lang_v1.shipping_address') }}</label>
                        <textarea id="shipping_address" name="shipping_address" rows="2"
                                  class="textarea">{{ old('shipping_address', $document->shipping_address ?? '') }}</textarea>
                    </div>
                </div>
            </x-panel>
        @endif

        @unless ($isEdit)
            {{-- Later payments are added from the document screen, so the edit form
                 never rewrites existing payment rows. --}}
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
@endif

{{-- The row the editor clones. Inert markup: nothing inside a <template> is
     submitted, so the '__i__' placeholder cannot reach the server. --}}
<template id="line-template">
    @include('sell._line', ['index' => '__i__', 'line' => null])
</template>

@push('scripts')
<script>
(function () {
    const body = document.getElementById('lines-body');
    const empty = document.getElementById('lines-empty');
    const search = document.getElementById('product-search');
    const totalCell = document.getElementById('lines-total');
    const template = document.getElementById('line-template');
    const priceGroup = document.getElementById('selling_price_group_id');

    // tax_id -> percentage, so the order tax can be shown before saving.
    const TAX_RATES = @json($taxAmounts);

    let index = body.querySelectorAll('tr').length;
    let picked = null;

    const num = (el) => parseFloat(el?.value) || 0;
    const money = (value) => value.toFixed(2);

    /* Deliberately the same arithmetic, in the same order, as
       SellService::recalculateTotals(). If one changes, so must the other. */
    const recalc = function () {
        let subtotal = 0;

        body.querySelectorAll('tr').forEach(function (row) {
            const line = num(row.querySelector('[data-qty]')) * num(row.querySelector('[data-price]'));

            row.querySelector('[data-subtotal]').textContent = money(line);
            subtotal += line;
        });

        const discountType = document.getElementById('discount_type').value;
        const discountInput = num(document.getElementById('discount_amount'));
        const discount = discountType === 'percentage'
            ? subtotal * discountInput / 100
            : discountInput;

        const afterDiscount = Math.max(0, subtotal - discount);
        const rate = parseFloat(TAX_RATES[document.getElementById('tax_id').value]) || 0;
        const tax = afterDiscount * rate / 100;
        const shipping = num(document.getElementById('shipping_charges'));
        const round = num(document.getElementById('round_off_amount'));

        totalCell.textContent = money(subtotal);
        document.getElementById('summary-subtotal').textContent = money(subtotal);
        document.getElementById('summary-discount').textContent = money(discount);
        document.getElementById('summary-tax').textContent = money(tax);
        document.getElementById('summary-shipping').textContent = money(shipping);
        document.getElementById('summary-round').textContent = money(round);
        document.getElementById('summary-total').textContent =
            money(afterDiscount + tax + shipping + round);

        empty.classList.toggle('hidden', body.querySelectorAll('tr').length > 0);
    };

    const addRow = function (product, options) {
        const i = index++;
        const row = template.content.cloneNode(true);

        // The template's names carry a placeholder index; every clone claims its own.
        row.querySelectorAll('[name]').forEach(function (field) {
            field.name = field.name.replace('__i__', i);
        });

        row.querySelector('[data-variation]').value = product.variation_id;
        row.querySelector('[data-name]').textContent = product.text;
        row.querySelector('[data-sku]').textContent = product.sku ?? '';
        row.querySelector('[data-qty]').value = options?.quantity ?? 1;
        row.querySelector('[data-price]').value = product.selling_price ?? 0;

        // Set only when the line fulfils a sales order line, so the order's
        // outstanding quantity follows the invoice.
        if (options?.soLineId) {
            row.querySelector('[data-so-line]').value = options.soLineId;
        }

        body.appendChild(row);
        recalc();
    };

    /* --- Product lookup --------------------------------------------------
       Same endpoint as the POS. The price group is sent with the term, so the
       row arrives at the price this customer is owed rather than at list. */
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

            if (priceGroup?.value) {
                params.set('price_group_id', priceGroup.value);
            }

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

    ['discount_type', 'discount_amount', 'tax_id', 'shipping_charges', 'round_off_amount']
        .forEach(function (id) {
            document.getElementById(id).addEventListener('input', recalc);
            document.getElementById(id).addEventListener('change', recalc);
        });

@if ($canLinkOrders)
    /* --- Sales orders ----------------------------------------------------
       Choosing a customer lists their open orders; importing one pulls in only
       the quantities still outstanding on it. */
    const picker = document.getElementById('sales-order-picker');
    const importBtn = document.getElementById('import-order-lines');
    const linked = document.getElementById('linked-orders');
    const imported = new Set();

    const ORDERS_URL = '{{ route('sells.customerOrders', ['contact' => '__ID__']) }}';
    const LINES_URL = '{{ route('sells.orderLines', ['order' => '__ID__']) }}';

    const resetPicker = function (label) {
        picker.innerHTML = '';
        picker.appendChild(new Option(label, ''));
        picker.disabled = true;
        importBtn.disabled = true;
    };

    document.getElementById('contact_id').addEventListener('change', async function (event) {
        const contactId = event.target.value;

        if (!contactId) {
            resetPicker(@json(__('lang_v1.select_customer_first')));
            return;
        }

        resetPicker(@json(__('lang_v1.loading')));

        const response = await fetch(ORDERS_URL.replace('__ID__', contactId), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });

        if (!response.ok) return;

        const orders = (await response.json()).filter((order) => !imported.has(String(order.id)));

        if (orders.length === 0) {
            resetPicker(@json(__('lang_v1.no_records_found')));
            return;
        }

        picker.innerHTML = '';
        picker.appendChild(new Option(@json(__('lang_v1.select')), ''));
        orders.forEach((order) => picker.appendChild(new Option(order.text, order.id)));
        picker.disabled = false;
        importBtn.disabled = false;
    });

    importBtn.addEventListener('click', async function () {
        const orderId = picker.value;
        if (!orderId || imported.has(orderId)) return;

        const response = await fetch(LINES_URL.replace('__ID__', orderId), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });

        if (!response.ok) return;

        const lines = await response.json();

        lines.forEach(function (line) {
            addRow(line, { quantity: line.quantity, soLineId: line.so_line_id });
        });

        imported.add(orderId);

        // The service reads these to move each order's fulfilment status.
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'sales_order_ids[]';
        hidden.value = orderId;
        linked.appendChild(hidden);

        const chip = document.createElement('span');
        chip.className = 'chip chip-active';
        chip.textContent = picker.options[picker.selectedIndex].text;
        linked.appendChild(chip);

        picker.remove(picker.selectedIndex);
        importBtn.disabled = picker.options.length < 2;
    });
@endif

    recalc();
})();
</script>
@endpush
