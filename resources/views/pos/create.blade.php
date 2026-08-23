@extends('layouts.app')
@section('title', __('lang_v1.pos'))
@section('page_title', __('lang_v1.pos'))

{{-- The terminal is the one screen that wants the whole monitor: capping it at
     96rem would leave a product grid four tiles wide with empty space either
     side. See the note on layouts.app's content container. --}}
@section('full_bleed')
@endsection

@section('content')

{{--
    The POS terminal.

    Designed against a different brief from every other screen here. Elsewhere
    the reader is deciding something and the layout serves comprehension; here
    the operator already knows what they want and the layout serves speed, with
    a customer waiting. So:

    * Two zones and nothing else — products on one side, the cart on the other.
      Both are pinned on a large screen, so the thing you are reaching for never
      moves between one sale and the next.
    * White surfaces, slate text, one brand tint on the cart header. Nothing
      saturated, because this screen is looked at for eight hours.
    * Exactly one accent button, and it is the one that takes money.
    * Everything optional is behind a toggle. A cashier sees products, a cart,
      a total and a pay button; discount, tax, notes and payment detail appear
      only when asked for.
    * Every control is at least 36px tall and the product tiles are 152×96,
      which is a thumb rather than a mouse.
--}}

<form method="POST" action="{{ route('pos.store') }}" id="pos-form">
    @csrf

    {{-- Who and where. Three selects, no labels above them: the icon and the
         value say what each one is, and a label row here would push the product
         grid a further 20px down the screen on every sale of the day. --}}
    <div class="card mb-4 flex flex-wrap items-center gap-3 p-3">
        <div class="flex min-w-48 flex-1 items-center gap-2">
            <span class="text-slate-400"><x-nav-icon name="store" :size="5"/></span>
            <select id="location_id" name="location_id" class="select" required
                    aria-label="{{ __('lang_v1.location') }}">
                @foreach ($locations as $id => $name)
                    <option value="{{ $id }}" @selected(old('location_id') == $id)>{{ $name }}</option>
                @endforeach
            </select>
        </div>

        <div class="flex min-w-48 flex-1 items-center gap-2">
            <span class="text-slate-400"><x-nav-icon name="user" :size="5"/></span>
            <select id="contact_id" name="contact_id" class="select" required
                    aria-label="{{ __('lang_v1.customer') }}">
                @foreach ($customers as $id => $name)
                    <option value="{{ $id }}"
                            @selected(old('contact_id', $defaultCustomer) == $id)>{{ $name }}</option>
                @endforeach
            </select>
        </div>

        <div class="flex min-w-44 flex-1 items-center gap-2">
            <span class="text-slate-400"><x-nav-icon name="tag" :size="5"/></span>
            <select id="selling_price_group_id" name="selling_price_group_id" class="select"
                    aria-label="{{ __('lang_v1.price_group') }}">
                @foreach ($priceGroups as $id => $name)
                    <option value="{{ $id }}"
                            @selected(old('selling_price_group_id') == $id)>{{ $name }}</option>
                @endforeach
            </select>
        </div>

        {{-- The shortcuts, on screen, because a cashier only ever learns them
             from the screen. Hidden on small viewports, where there is no
             keyboard to press them on. --}}
        <div class="hidden items-center gap-3 ps-1 text-xs text-slate-500 xl:flex">
            <span class="flex items-center gap-1.5">
                <span class="kbd">F2</span>{{ __('lang_v1.search') }}
            </span>
            <span class="flex items-center gap-1.5">
                <span class="kbd">F4</span>{{ __('lang_v1.finalize_sale') }}
            </span>
        </div>
    </div>

    <div class="pos-shell">

        {{-- ---------------------------------------------------------------
             Zone 1 — choosing products
             --------------------------------------------------------------- }}
        {{-- Same top offset and same height cap as .pos-cart, so the two zones
             align and each scrolls inside itself. Neither ever moves. --}}
        <div class="pos-panel lg:sticky lg:top-20 lg:max-h-[calc(100vh-6.5rem)]">
            <div class="border-b border-slate-200 p-3">
                {{-- One field, as wide as the panel. It is both the search box and
                     the barcode target: a scanner types the SKU and presses Enter,
                     which is why an exact SKU match adds the item outright. --}}
                <div class="input-search-wrap">
                    <span class="input-search-icon"><x-nav-icon name="search" :size="5"/></span>
                    <input type="search" id="product-search" class="input-search input-lg"
                           placeholder="{{ __('lang_v1.search_products') }}"
                           autocomplete="off" autofocus
                           aria-label="{{ __('lang_v1.search_products') }}">
                </div>
            </div>

            <div class="flex-1 overflow-y-auto">
                <div class="product-grid" id="product-grid"></div>

                <p id="product-loading" class="hidden p-6 text-center text-sm text-slate-500">
                    {{ __('lang_v1.loading') }}
                </p>

                <div id="product-empty" class="hidden">
                    <x-empty-state icon="search" :title="__('lang_v1.no_products_found')"/>
                </div>
            </div>
        </div>

        {{-- ---------------------------------------------------------------
             Zone 2 — the cart
             --------------------------------------------------------------- }}
        <div class="pos-cart">
            {{-- The one tinted surface on the screen. It costs nothing to read and
                 it makes the two zones obvious from across the counter. --}}
            <div class="flex items-center gap-2 border-b border-slate-200 bg-brand-50/60 px-4 py-3">
                <h2 class="flex flex-1 items-center gap-2 text-sm font-bold text-brand-900">
                    <x-nav-icon name="cart" :size="5"/>
                    {{ __('lang_v1.items') }}
                    <span id="cart-count" class="badge-muted">0</span>
                </h2>

                <button type="button" id="toggle-extras" class="btn-icon"
                        title="{{ __('lang_v1.apply_discount') }}"
                        aria-label="{{ __('lang_v1.apply_discount') }}"
                        aria-expanded="false" aria-controls="cart-extras">
                    <x-nav-icon name="discount" :size="4"/>
                </button>

                <button type="button" id="clear-cart" class="btn-icon-danger"
                        title="{{ __('lang_v1.clear_cart') }}"
                        aria-label="{{ __('lang_v1.clear_cart') }}">
                    <x-nav-icon name="x-circle" :size="4"/>
                </button>
            </div>

            <div class="pos-cart-scroll" id="cart-rows"></div>

            <div id="cart-empty" class="grid flex-1 place-items-center px-4 py-10">
                <x-empty-state icon="cart" :title="__('lang_v1.cart_empty')"
                               :text="__('lang_v1.search_products')" compact/>
            </div>

            {{-- Discount, tax and note: real, and off screen until wanted. --}}
            <div id="cart-extras" class="hidden border-t border-slate-200 bg-slate-50/70 p-4">
                <div class="grid grid-cols-2 gap-3">
                    <div class="field">
                        <label for="discount_type" class="label">{{ __('lang_v1.discount_type') }}</label>
                        <select id="discount_type" name="discount_type" class="select">
                            <option value="fixed" @selected(old('discount_type') === 'fixed')>
                                {{ __('lang_v1.fixed') }}
                            </option>
                            <option value="percentage" @selected(old('discount_type') === 'percentage')>
                                {{ __('lang_v1.percentage') }}
                            </option>
                        </select>
                    </div>

                    <div class="field">
                        <label for="discount_amount" class="label">{{ __('lang_v1.discount') }}</label>
                        <input id="discount_amount" name="discount_amount" class="input-numeric"
                               inputmode="decimal" value="{{ old('discount_amount', 0) }}">
                    </div>

                    <div class="field col-span-2">
                        <label for="tax_id" class="label">{{ __('lang_v1.order_tax') }}</label>
                        <select id="tax_id" name="tax_id" class="select">
                            @foreach ($taxes as $id => $name)
                                <option value="{{ $id }}" @selected(old('tax_id') == $id)>{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            {{-- The breakdown appears only once there is something to break down:
                 a subtotal that equals the total is a row that says nothing. --}}
            <dl id="cart-breakdown" class="hidden border-t border-slate-200 px-4 py-2 text-sm">
                <div class="flex items-baseline justify-between gap-3 py-0.5">
                    <dt class="text-slate-500">{{ __('lang_v1.subtotal') }}</dt>
                    <dd class="font-mono tabular-nums text-slate-700 force-ltr" id="cart-subtotal">0</dd>
                </div>
                <div class="flex items-baseline justify-between gap-3 py-0.5" id="cart-discount-row">
                    <dt class="text-slate-500">{{ __('lang_v1.discount') }}</dt>
                    <dd class="font-mono tabular-nums text-slate-700 force-ltr" id="cart-discount">0</dd>
                </div>
                <div class="flex items-baseline justify-between gap-3 py-0.5" id="cart-tax-row">
                    <dt class="text-slate-500">{{ __('lang_v1.tax') }}</dt>
                    <dd class="font-mono tabular-nums text-slate-700 force-ltr" id="cart-tax">0</dd>
                </div>
            </dl>

            {{-- What the customer pays. The largest number on the screen, and the
                 only one that has to be readable from a standing distance. --}}
            <div class="pos-total">
                <span class="pos-total-label">{{ __('lang_v1.total') }}</span>
                <span class="pos-total-value" id="cart-total">0</span>
            </div>

            <div class="border-t border-slate-200 p-3">
                {{-- The single accent on the screen. Amber rather than the brand
                     teal so it is unmistakably the commit, and not red: red on the
                     button a cashier presses two hundred times a day reads as a
                     warning and stops meaning anything. --}}
                <button type="button" id="open-payment" class="btn-accent btn-lg btn-block" disabled>
                    <x-nav-icon name="cash" :size="5"/>
                    {{ __('lang_v1.finalize_sale') }}
                </button>
            </div>
        </div>
    </div>

    {{--
        Payment.

        A dialog rather than a fourth panel: tendering is the last two seconds of
        the sale, it needs the keypad and the change figure to be the only things
        on screen, and the rest of the terminal has nothing to say while it is
        open. It lives inside the form so its fields post with the sale.
    --}}
    <div id="payment-modal" class="modal-backdrop hidden">
        {{-- Wider than the default panel and capped in height: the keypad and the
             tendering fields sit side by side, and on a 768px-tall tablet held
             landscape the dialog has to scroll rather than lose its footer. --}}
        <div class="modal-panel max-h-[90vh] max-w-2xl overflow-y-auto">
            <div class="card-header">
                <h3 class="card-title">{{ __('lang_v1.payment') }}</h3>
                <button type="button" class="btn-icon" data-close-payment
                        title="{{ __('lang_v1.close') }}" aria-label="{{ __('lang_v1.close') }}">
                    <x-nav-icon name="x" :size="4"/>
                </button>
            </div>

            <div class="card-body">
                {{-- border-t-0 because the card header above already draws one, and
                     two rules 1px apart read as a rendering fault. --}}
                <div class="pos-total -mx-5 -mt-5 mb-5 border-t-0 border-b">
                    <span class="pos-total-label">{{ __('lang_v1.total') }}</span>
                    <span class="pos-total-value" id="pay-total">0</span>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <div class="field">
                            <label for="amount-tendered" class="label">{{ __('lang_v1.amount_paid') }}</label>
                            {{-- Display only. What posts is the hidden field below,
                                 capped at the total: handing over 500 for a 320 sale
                                 is 320 taken and 180 back, not a 180 credit. --}}
                            <input id="amount-tendered" class="input-amount" inputmode="decimal"
                                   value="" autocomplete="off">
                            <input type="hidden" name="payments[0][amount]" id="payment-amount" value="0">
                        </div>

                        <div class="mt-3 flex items-baseline justify-between gap-3 rounded-lg
                                    border border-emerald-200 bg-emerald-50 px-3 py-2">
                            <span class="text-sm font-semibold text-emerald-900">
                                {{ __('lang_v1.change_due') }}
                            </span>
                            <span class="font-mono text-lg font-bold tabular-nums text-emerald-800 force-ltr"
                                  id="change-due">0</span>
                        </div>

                        <div class="field mt-3">
                            <label for="payment-method" class="label">{{ __('lang_v1.payment_method') }}</label>
                            <select id="payment-method" name="payments[0][method]" class="select">
                                @foreach ($paymentMethods as $key => $label)
                                    <option value="{{ $key }}" @selected($key === 'cash')>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="field mt-3">
                            <label for="payment-account" class="label">{{ __('lang_v1.payment_account') }}</label>
                            <select id="payment-account" name="payments[0][account_id]" class="select">
                                @foreach ($accounts as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div>
                        {{-- Ten keys and a backspace, because a touch screen has no
                             number row and the on-screen keyboard covers the total. --}}
                        <div class="keypad" id="keypad">
                            @foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9'] as $digit)
                                <button type="button" class="keypad-key" data-key="{{ $digit }}">{{ $digit }}</button>
                            @endforeach
                            <button type="button" class="keypad-key" data-key=".">.</button>
                            <button type="button" class="keypad-key" data-key="0">0</button>
                            <button type="button" class="keypad-key" data-key="back"
                                    aria-label="{{ __('lang_v1.delete') }}">
                                <x-nav-icon name="backspace" :size="5"/>
                            </button>
                        </div>

                        <button type="button" id="exact-amount" class="btn-secondary btn-block mt-2">
                            <x-nav-icon name="check" :size="4"/>
                            {{ __('lang_v1.exact_amount') }}
                        </button>

                        <div class="field mt-3">
                            <label for="additional_notes" class="label">{{ __('lang_v1.notes') }}</label>
                            {{-- Here rather than beside the cart: it is the one field
                                 nobody fills in during the rush, so it belongs on the
                                 screen you are already stopped on. --}}
                            <textarea id="additional_notes" name="additional_notes" rows="2"
                                      class="textarea">{{ old('additional_notes') }}</textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card-actions">
                <button type="button" class="btn-secondary" data-close-payment>
                    {{ __('lang_v1.cancel') }}
                </button>
                <button type="submit" class="btn-accent btn-lg" id="finalize">
                    <x-nav-icon name="cash" :size="5"/>
                    {{ __('lang_v1.finalize_sale') }}
                </button>
            </div>
        </div>
    </div>

    {{-- Cloned per cart line. Inputs inside a <template> are inert, so the
         placeholder index cannot reach the server. --}}
    <template id="cart-row-template">
        <div class="cart-row" data-row>
            <input type="hidden" name="lines[__i__][variation_id]" data-variation>
            <input type="hidden" name="lines[__i__][unit_price]" data-price>
            {{-- Carried so a rejected sale can be put back on screen with its
                 labels intact. store() validates three keys per line and Laravel
                 returns only what it validated, so this one never reaches the
                 service — it exists purely to survive a round trip. --}}
            <input type="hidden" name="lines[__i__][name]" data-label>

            <div class="cart-row-name">
                <span class="block truncate text-sm font-semibold text-slate-900" data-name></span>

                <div class="mt-1.5 flex items-center gap-2">
                    <div class="stepper">
                        <button type="button" class="stepper-btn" data-step="-1"
                                aria-label="{{ __('lang_v1.decrease') }}">
                            <x-nav-icon name="minus" :size="4"/>
                        </button>
                        <input class="stepper-input" name="lines[__i__][quantity]" data-qty
                               inputmode="decimal" value="1"
                               aria-label="{{ __('lang_v1.quantity') }}">
                        <button type="button" class="stepper-btn" data-step="1"
                                aria-label="{{ __('lang_v1.increase') }}">
                            <x-nav-icon name="plus" :size="4"/>
                        </button>
                    </div>

                    {{-- quantity × unit price, so a figure typed into the stepper by
                         hand can be checked against the line total beside it. --}}
                    <span class="cell-meta force-ltr" data-meta></span>
                </div>
            </div>

            <span class="cart-row-total" data-total>0</span>

            <button type="button" class="btn-icon-danger shrink-0" data-remove
                    title="{{ __('lang_v1.remove') }}"
                    aria-label="{{ __('lang_v1.remove') }}">
                <x-nav-icon name="trash" :size="4"/>
            </button>
        </div>
    </template>

    {{-- One tile, cloned per product. --}}
    <template id="product-tile-template">
        <button type="button" class="product-tile" data-tile>
            <span class="product-tile-name" data-name></span>
            <span class="flex w-full items-baseline justify-between gap-2">
                <span class="product-tile-price" data-price></span>
                <span class="product-tile-stock" data-stock></span>
            </span>
        </button>
    </template>
</form>
@endsection

@push('scripts')
<script>
(function () {
    const form = document.getElementById('pos-form');
    const grid = document.getElementById('product-grid');
    const gridEmpty = document.getElementById('product-empty');
    const gridLoading = document.getElementById('product-loading');
    const tileTemplate = document.getElementById('product-tile-template');

    const cart = document.getElementById('cart-rows');
    const cartEmpty = document.getElementById('cart-empty');
    const rowTemplate = document.getElementById('cart-row-template');

    const search = document.getElementById('product-search');
    const locationSelect = document.getElementById('location_id');
    const priceGroup = document.getElementById('selling_price_group_id');

    const modal = document.getElementById('payment-modal');
    const tendered = document.getElementById('amount-tendered');
    const paidField = document.getElementById('payment-amount');

    // tax_id -> percentage. Same map, same purpose, as the sell form's.
    const TAX_RATES = @json($taxAmounts);
    const OUT_OF_STOCK = @json(__('lang_v1.out_of_stock'));

    let index = 0;
    let total = 0;

    const num = (el) => parseFloat(el?.value) || 0;
    const money = (value) => value.toFixed(2);

    /* --- Change ----------------------------------------------------------
       Defined before recalc() because recalc() ends by calling it: what is owed
       back changes the moment the basket does. */
    const updateChange = function () {
        const paid = parseFloat(tendered.value) || 0;

        // Never post more than the sale is worth: the rest is change, not credit.
        paidField.value = money(Math.min(paid, total));
        document.getElementById('change-due').textContent = money(Math.max(0, paid - total));
    };

    /* --- Totals ----------------------------------------------------------
       Deliberately the same arithmetic, in the same order, as
       SellService::recalculateTotals(). If one changes, so must the other.
       No line tax, shipping or round-off: the terminal does not offer them,
       so a sale made here has none. */
    const recalc = function () {
        const rows = cart.querySelectorAll('[data-row]');
        let subtotal = 0;
        let units = 0;

        rows.forEach(function (row) {
            const qty = num(row.querySelector('[data-qty]'));
            const price = num(row.querySelector('[data-price]'));
            const line = qty * price;

            row.querySelector('[data-total]').textContent = money(line);
            row.querySelector('[data-meta]').textContent = qty + ' × ' + money(price);

            subtotal += line;
            units += 1;
        });

        const discountInput = num(document.getElementById('discount_amount'));
        const discount = document.getElementById('discount_type').value === 'percentage'
            ? subtotal * discountInput / 100
            : discountInput;

        const afterDiscount = Math.max(0, subtotal - discount);
        const rate = parseFloat(TAX_RATES[document.getElementById('tax_id').value]) || 0;
        const tax = afterDiscount * rate / 100;

        total = afterDiscount + tax;

        document.getElementById('cart-count').textContent = units;
        document.getElementById('cart-subtotal').textContent = money(subtotal);
        document.getElementById('cart-discount').textContent = money(discount);
        document.getElementById('cart-tax').textContent = money(tax);
        document.getElementById('cart-total').textContent = money(total);
        document.getElementById('pay-total').textContent = money(total);

        // The breakdown earns its rows only when it says something the total
        // does not.
        document.getElementById('cart-breakdown').classList.toggle('hidden', discount === 0 && tax === 0);
        document.getElementById('cart-discount-row').classList.toggle('hidden', discount === 0);
        document.getElementById('cart-tax-row').classList.toggle('hidden', tax === 0);

        // One or the other, never both: the scroll area keeps a minimum height so
        // a short cart does not jump, and that height under an empty state reads
        // as a gap in the panel.
        cart.classList.toggle('hidden', units === 0);
        cartEmpty.classList.toggle('hidden', units > 0);
        document.getElementById('open-payment').disabled = units === 0;

        updateChange();
    };

    /* --- Cart ------------------------------------------------------------ */
    const addToCart = function (product, quantity) {
        const amount = parseFloat(quantity) || 1;
        const existing = cart.querySelector('[data-row][data-variation-id="' + product.variation_id + '"]');

        // A second scan of the same barcode is one more of that item, not a
        // second line saying the same thing.
        if (existing) {
            const qty = existing.querySelector('[data-qty]');
            qty.value = (parseFloat(qty.value) || 0) + amount;
            recalc();

            // The row is often already scrolled out of sight on a long cart, and
            // a count that changes off screen looks like nothing happened.
            existing.scrollIntoView({ block: 'nearest' });
            return;
        }

        const i = index++;
        const fragment = rowTemplate.content.cloneNode(true);

        fragment.querySelectorAll('[name]').forEach(function (field) {
            field.name = field.name.replace('__i__', i);
        });

        const row = fragment.querySelector('[data-row]');
        row.dataset.variationId = product.variation_id;
        row.querySelector('[data-variation]').value = product.variation_id;
        row.querySelector('[data-price]').value = product.selling_price ?? 0;
        row.querySelector('[data-label]').value = product.text ?? '';
        row.querySelector('[data-qty]').value = amount;
        row.querySelector('[data-name]').textContent = product.text ?? '';
        row.querySelector('[data-name]').title = product.text ?? '';

        cart.appendChild(fragment);
        recalc();

        // Newest line in view: on a long cart the row just added is the one the
        // operator wants to see.
        cart.scrollTop = cart.scrollHeight;
    };

    cart.addEventListener('click', function (event) {
        const remove = event.target.closest('[data-remove]');
        if (remove) {
            remove.closest('[data-row]').remove();
            recalc();
            return;
        }

        const step = event.target.closest('[data-step]');
        if (step) {
            const input = step.closest('[data-row]').querySelector('[data-qty]');
            const next = (parseFloat(input.value) || 0) + parseFloat(step.dataset.step);

            // Stepping below one removes the line, which is what pressing minus
            // on a single unit means.
            if (next <= 0) {
                step.closest('[data-row]').remove();
            } else {
                input.value = next;
            }

            recalc();
        }
    });

    cart.addEventListener('input', recalc);

    document.getElementById('clear-cart').addEventListener('click', function () {
        cart.replaceChildren();
        recalc();
        search.focus();
    });

    /* --- Extras drawer --------------------------------------------------- */
    const extras = document.getElementById('cart-extras');
    document.getElementById('toggle-extras').addEventListener('click', function () {
        const open = extras.classList.toggle('hidden') === false;
        this.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    ['discount_type', 'discount_amount', 'tax_id'].forEach(function (id) {
        document.getElementById(id).addEventListener('input', recalc);
    });

    /* --- Product grid ----------------------------------------------------
       Loaded from the same endpoint the sell and purchase forms use. An empty
       term returns the first page of sellable products, so the grid is already
       full when the terminal opens and the first sale of the day needs no
       typing. */
    const renderProducts = function (products) {
        grid.replaceChildren();

        products.forEach(function (product) {
            const fragment = tileTemplate.content.cloneNode(true);
            const tile = fragment.querySelector('[data-tile]');

            tile.dataset.product = JSON.stringify(product);
            fragment.querySelector('[data-name]').textContent = product.text;
            fragment.querySelector('[data-price]').textContent = money(parseFloat(product.selling_price) || 0);

            const stock = fragment.querySelector('[data-stock]');

            if (product.enable_stock && product.qty_available !== null) {
                const available = parseFloat(product.qty_available) || 0;
                stock.textContent = available > 0 ? available : OUT_OF_STOCK;

                /* Flagged, not blocked. Plenty of shops sell the last unit while
                   the count catches up, and refusing the sale at the tile would
                   make that a policy this screen invented. */
                if (available <= 0) {
                    tile.classList.add('product-tile-out');
                }
            }

            grid.appendChild(fragment);
        });

        // The grid keeps its padding even with no children, which would show as a
        // band of nothing above the empty state.
        grid.classList.toggle('hidden', products.length === 0);
        gridEmpty.classList.toggle('hidden', products.length > 0);
    };

    let inFlight = 0;
    const loadProducts = async function (term) {
        const params = new URLSearchParams({ term: term ?? '', location_id: locationSelect.value });

        if (priceGroup.value) {
            params.set('price_group_id', priceGroup.value);
        }

        const request = ++inFlight;
        gridLoading.classList.remove('hidden');

        try {
            const response = await fetch('{{ route('products.list') }}?' + params, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (!response.ok) return [];

            const results = await response.json();

            // A slower earlier request must not overwrite a later one's grid.
            if (request === inFlight) {
                renderProducts(results);
            }

            return results;
        } catch (error) {
            return [];
        } finally {
            if (request === inFlight) {
                gridLoading.classList.add('hidden');
            }
        }
    };

    grid.addEventListener('click', function (event) {
        const tile = event.target.closest('[data-tile]');
        if (tile) {
            addToCart(JSON.parse(tile.dataset.product));
        }
    });

    let timer = null;
    search.addEventListener('input', function () {
        clearTimeout(timer);
        const term = search.value.trim();

        timer = setTimeout(async function () {
            const results = await loadProducts(term);

            // A scanner types the whole SKU and presses Enter faster than a
            // person can; an exact match is not a search result, it is a choice
            // already made.
            if (term !== '' && results.length === 1 && results[0].sku === term) {
                addToCart(results[0]);
                search.value = '';
                loadProducts('');
            }
        }, 200);
    });

    search.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter') return;

        // Never submit the sale from the search box.
        event.preventDefault();

        const first = grid.querySelector('[data-tile]');
        if (first) {
            addToCart(JSON.parse(first.dataset.product));
            search.value = '';
            loadProducts('');
        }
    });

    [locationSelect, priceGroup].forEach(function (select) {
        // Both change what a product costs and what is on the shelf, so the grid
        // is refetched. Lines already in the cart keep the price they were added
        // at, which is the price that posts.
        select.addEventListener('change', function () {
            loadProducts(search.value.trim());
        });
    });

    /* --- Payment --------------------------------------------------------- */
    const openPayment = function () {
        if (cart.querySelectorAll('[data-row]').length === 0) return;

        modal.classList.remove('hidden');
        tendered.focus();
        tendered.select();
    };

    const closePayment = function () {
        modal.classList.add('hidden');
        search.focus();
    };

    document.getElementById('open-payment').addEventListener('click', openPayment);

    modal.querySelectorAll('[data-close-payment]').forEach(function (button) {
        button.addEventListener('click', closePayment);
    });

    // The backdrop closes; the panel does not close when clicked through.
    modal.addEventListener('click', function (event) {
        if (event.target === modal) closePayment();
    });

    tendered.addEventListener('input', updateChange);

    tendered.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            document.getElementById('finalize').click();
        }
    });

    document.getElementById('keypad').addEventListener('click', function (event) {
        const key = event.target.closest('[data-key]');
        if (!key) return;

        const value = key.dataset.key;

        if (value === 'back') {
            tendered.value = tendered.value.slice(0, -1);
        } else if (value === '.' && tendered.value.includes('.')) {
            return;
        } else {
            tendered.value += value;
        }

        updateChange();
    });

    document.getElementById('exact-amount').addEventListener('click', function () {
        tendered.value = money(total);
        updateChange();
    });

    /* --- Keyboard -------------------------------------------------------- */
    document.addEventListener('keydown', function (event) {
        if (event.key === 'F2') {
            event.preventDefault();
            search.focus();
            search.select();
            return;
        }

        if (event.key === 'F4') {
            event.preventDefault();
            openPayment();
            return;
        }

        if (event.key === 'Escape' && !modal.classList.contains('hidden')) {
            closePayment();
        }
    });

    /* --- Submit ---------------------------------------------------------- */

    /* Enter must never finalise a sale by accident. Implicit submission from the
       discount field or a quantity stepper would post the basket without anyone
       having seen the total, so Enter is inert on every input outside the payment
       dialog — where it is the confirm key and belongs. Buttons and the notes
       textarea are left alone: swallowing Enter there breaks keyboard use. */
    form.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter') return;
        if (event.target.tagName !== 'INPUT' && event.target.tagName !== 'SELECT') return;
        if (event.target.closest('#payment-modal')) return;

        event.preventDefault();
    });

    form.addEventListener('submit', function () {
        // A double tap on a touch screen is two sales otherwise.
        document.getElementById('finalize').disabled = true;
    });

    /* --- Start ------------------------------------------------------------
       A rejected sale comes back through back()->withInput(), and a cashier
       should not have to rebuild a twenty-item basket because the server refused
       it. The label rides along in the field the validator drops, so putting a
       line back needs no second lookup. */
    Object.values(@json(old('lines', []))).forEach(function (line) {
        addToCart({
            variation_id: line.variation_id,
            text: line.name,
            selling_price: line.unit_price,
        }, line.quantity);
    });

    recalc();
    loadProducts('');
})();
</script>
@endpush
