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

    {{-- The write-ahead identity of this sale. Empty on arrival and filled in by
         the script the instant the cashier finalises — before anything is sent —
         so the copy on the device and the row in the database are the same sale
         by identity. See the note on the matching rules in SellPosController. --}}
    <input type="hidden" name="offline_temp_id" id="offline_temp_id" value="">
    <input type="hidden" name="offline_device_id" id="offline_device_id" value="">

    {{-- ---------------------------------------------------------------
         The offline bar
         ---------------------------------------------------------------
         Hidden until it has something to say, and then it says the two things a
         cashier working through an outage actually needs: that the prices on
         screen are a snapshot rather than live, and which sales are still only on
         this machine. It is placed above the selector card rather than between
         the card and the shell, because `.pos-shell` measures its own top offset
         from the element before it — see fitShell(). --}}
    <div id="pos-offline" class="pos-offline hidden" role="status" aria-live="polite">
        <div class="pos-offline-head">
            <span class="pos-offline-icon"><x-nav-icon name="cloud-off" :size="5"/></span>

            <div class="min-w-0 flex-1">
                <p class="pos-offline-title" id="pos-offline-title"></p>
                <p class="pos-offline-note" id="pos-offline-note"></p>
            </div>

            <button type="button" id="pos-offline-retry" class="btn-secondary btn-sm">
                <x-nav-icon name="refresh" :size="4"/>
                <span class="hidden sm:inline">{{ __('lang_v1.sync_now') }}</span>
            </button>
        </div>

        {{-- The list is what turns a count into an answer. "3 pending" invites the
             question "which three?", and a shop that cannot answer it at the
             counter will start writing sales on paper as well, which is worse
             than either. --}}
        <ul id="pos-offline-list" class="pos-offline-list"></ul>
    </div>

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

        {{-- Credit the selected customer has already left with the shop.

             Here, on the register bar, and not only inside the tender dialog: a
             cashier decides whether to ask for money at all *before* opening
             that dialog, and the answer changes when the customer is already in
             funds. It is the same figure the dialog then spends, fetched by the
             same request.

             `shrink-0` and outside the select's own flex box so a long customer
             name cannot squeeze it to nothing, and `aria-live` because it
             appears without anything on screen having been clicked. Absent, not
             zeroed, when there is no credit — a chip reading 0.00 on every
             walk-in sale is noise the eye learns to skip past. --}}
        <span id="customer-credit" class="pos-credit hidden" role="status" aria-live="polite">
            <x-nav-icon name="coins" :size="4"/>
            <span>{{ __('lang_v1.customer_credit') }}</span>
            <span class="pos-credit-value force-ltr" id="customer-credit-value">0</span>
        </span>

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
             --------------------------------------------------------------- --}}
        {{-- No height utilities here: .pos-shell is pinned to the window and
             both zones stretch to it, so each scrolls inside itself. Neither
             ever moves. --}}
        <div class="pos-panel">
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
                <div id="product-grid"
                     @class(['product-grid', 'product-grid-media' => $hasProductImages])></div>

                {{-- A skeleton, not the word "loading". It answers two questions a
                     spinner cannot — how much is coming and where it will be — so
                     nothing on the busiest screen in the shop jumps when the
                     products land. Ten covers the first visible rows; the feed
                     returns 25, but a placeholder only has to reach as far as the
                     eye does before the fetch resolves.

                     aria-hidden because this is the absence of content, not
                     content: a screen reader announcing ten empty tiles would be
                     worse than announcing nothing. --}}
                <div id="product-loading" aria-hidden="true"
                     @class(['product-grid hidden', 'product-grid-media' => $hasProductImages])>
                    @for ($i = 0; $i < 10; $i++)
                        <div class="skeleton-tile">
                            <span class="skeleton skeleton-tile-thumb"></span>
                            <span class="skeleton skeleton-text"></span>
                            <span class="skeleton skeleton-text"></span>
                        </div>
                    @endfor
                </div>

                <div id="product-empty" class="hidden">
                    <x-empty-state icon="search" :title="__('lang_v1.no_products_found')"/>
                </div>
            </div>
        </div>

        {{-- ---------------------------------------------------------------
             Zone 2 — the cart
             --------------------------------------------------------------- --}}
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

            {{-- min-h-0: a flex child with no overflow of its own refuses to
                 shrink below its content, and on a short window this one would
                 shrink the footer instead — pushing the pay button out of sight
                 behind the cart's overflow-hidden. Better to clip the artwork. --}}
            <div id="cart-empty" class="grid min-h-0 flex-1 place-items-center px-4 py-10">
                <x-empty-state icon="cart" :title="__('lang_v1.cart_empty')"
                               :text="__('lang_v1.search_products')" compact/>
            </div>

            {{-- Discount, tax and note: real, and off screen until wanted.
                 Scrolls for the same reason: opened on a laptop it is taller
                 than the room the cart can spare. --}}
            <div id="cart-extras" class="hidden min-h-0 overflow-y-auto border-t border-slate-200 bg-slate-50/70 p-4">
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

                        {{--
                            Stored credit about to pay for what the tender did
                            not cover — the mirror of the block below, which
                            handles a tender that went the other way.

                            A statement and never a question, because there is
                            nothing to decide: money the customer already handed
                            over is what a prepayment is *for*, and asking
                            permission to spend it would be asking whether they
                            would rather owe the shop instead. What matters is
                            that it is said out loud before the sale is
                            finalised, since the cashier is about to take less
                            cash than the total and the customer's balance is
                            about to fall — neither of which is visible anywhere
                            else on this screen.
                        --}}
                        <div id="advance"
                             class="mt-3 hidden rounded-lg border border-brand-200 bg-brand-50 px-3 py-2">
                            <p class="text-sm font-semibold text-brand-900" id="advance-text"></p>
                            <p class="mt-0.5 text-xs text-brand-800" id="advance-note"></p>
                        </div>

                        {{--
                            What happens to the excess.

                            Hidden until there is an excess, and then it is not
                            always a question. When the customer already owes
                            money the terminal states what it is about to do and
                            asks nothing: taking an overpayment off a standing
                            debt is the only sensible reading of the gesture, and
                            a prompt there would be a prompt with one right
                            answer. When they owe nothing the two outcomes are
                            genuinely different — money out of the drawer now, or
                            a balance the shop carries — so the cashier chooses,
                            and `refund` is preselected because it is what
                            happens at a counter by default.
                        --}}
                        <div id="overpay" class="mt-3 hidden">
                            <input type="hidden" name="overpay_amount" id="overpay-amount" value="0">

                            {{-- Debt case: a statement, not a control. --}}
                            <div id="overpay-debt"
                                 class="hidden rounded-lg border border-sky-200 bg-sky-50 px-3 py-2">
                                <p class="text-sm font-semibold text-sky-900" id="overpay-debt-text"></p>
                                <p class="mt-0.5 text-xs text-sky-800" id="overpay-debt-note"></p>
                            </div>

                            {{-- No-debt case: the choice. Radios rather than a
                                 select — two options that change what the cashier
                                 does with their hands in the next second should
                                 both be readable without opening anything. --}}
                            <fieldset id="overpay-choice" class="hidden">
                                <legend class="label">{{ __('lang_v1.excess_amount') }}</legend>

                                <label class="pos-choice">
                                    <input type="radio" name="overpay_action" value="refund"
                                           class="radio" checked>
                                    <span>
                                        <span class="pos-choice-title">{{ __('lang_v1.refund_change_cash') }}</span>
                                        <span class="pos-choice-note">{{ __('lang_v1.refund_change_cash_note') }}</span>
                                    </span>
                                </label>

                                <label class="pos-choice" id="overpay-credit-option">
                                    <input type="radio" name="overpay_action" value="credit" class="radio">
                                    <span>
                                        <span class="pos-choice-title">{{ __('lang_v1.keep_as_customer_credit') }}</span>
                                        <span class="pos-choice-note">{{ __('lang_v1.keep_as_customer_credit_note') }}</span>
                                    </span>
                                </label>

                                {{-- Why the second option is missing, when it is.
                                     An option that silently disappears reads as a
                                     bug; one that explains itself reads as a rule. --}}
                                <p class="hint hidden" id="overpay-anonymous">
                                    {{ __('lang_v1.walk_in_cannot_hold_credit') }}
                                </p>
                            </fieldset>
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

    {{-- One tile, cloned per product.

         Both halves of the picture box ship in the template and each clone keeps
         exactly one: the <img> when the product has a photo, the icon when it
         does not. The loser is removed rather than hidden — a src-less <img> is
         drawn as a broken-image glyph by some browsers, and 25 of those is a
         worse screen than no pictures at all. Whether the box is drawn in the
         first place is the grid's decision, not the tile's: see
         `.product-grid-media` in app.css. --}}
    <template id="product-tile-template">
        <button type="button" class="product-tile" data-tile>
            <span class="thumb-tile" data-thumb>
                <img alt="" loading="lazy" decoding="async">
                <x-nav-icon name="box" :size="8"/>
            </span>
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

    /* --- Overpayment -----------------------------------------------------
       The elements and the one piece of state the tender dialog needs beyond the
       basket: what this customer already owes. Held here rather than read from
       the DOM because it is fetched per customer and the dialog consults it on
       every keystroke. */
    const customerSelect = document.getElementById('contact_id');
    const overpay = document.getElementById('overpay');
    const overpayAmount = document.getElementById('overpay-amount');
    const overpayDebt = document.getElementById('overpay-debt');
    const overpayChoice = document.getElementById('overpay-choice');
    const creditOption = document.getElementById('overpay-credit-option');
    const anonymousNote = document.getElementById('overpay-anonymous');

    /* --- Stored credit ---------------------------------------------------
       The other half of the same fetch: what the customer has already paid in
       advance, which the server spends automatically against whatever this sale
       is left owing. Two places show it — the chip on the register bar, which
       answers "should I even ask for money?", and the panel in the tender
       dialog, which answers "how much of this is coming out of their balance?" */
    const creditChip = document.getElementById('customer-credit');
    const creditValue = document.getElementById('customer-credit-value');
    const advance = document.getElementById('advance');

    /* Contacts that cannot hold credit — the shared walk-in row. Numbers, so a
       string option value compares correctly after the Number() below. */
    const SHARED_CUSTOMERS = @json($sharedCustomers);

    let customerDue = 0;
    let customerCredit = 0;

    /* --- Fitting the terminal to the window ------------------------------
       The shell is pinned to the viewport height so that the cart, the total
       and the pay button cannot be pushed below the fold by a long list of
       products (see .pos-shell). What sits above the shell is not a fixed
       height though: the selector bar wraps to two or three rows on a narrow
       window. So measure that gap and hand it to CSS rather than guess it.

       The observer watches the bar, not the shell — the bar's height does not
       depend on the value being set, so there is no feedback loop. */
    const shell = document.querySelector('.pos-shell');
    const selectorBar = shell?.previousElementSibling;
    let fitQueued = false;

    const fitShell = () => {
        fitQueued = false;
        if (!shell) return;

        /* offsetTop, not getBoundingClientRect(). The layout now animates the
           whole page content in on load (`.rise` in layouts/app.blade.php), so
           for the first 340 ms a rect-based measurement reads the shell 8px
           lower than it really is and the shell ends up 8px short of its room.
           An offsetTop walk is pure layout and ignores transforms entirely.

           16px is the bottom padding of <main>: leave it, so the shell stops
           short of the window edge instead of sitting flush against it. */
        let top = 0;
        for (let el = shell; el; el = el.offsetParent) {
            top += el.offsetTop;
        }

        shell.style.setProperty('--pos-offset', `${Math.round(top) + 16}px`);
    };

    const queueFit = () => {
        if (fitQueued) return;
        fitQueued = true;
        requestAnimationFrame(fitShell);
    };

    fitShell();
    window.addEventListener('resize', queueFit);

    if (selectorBar && 'ResizeObserver' in window) {
        new ResizeObserver(queueFit).observe(selectorBar);
    }

    // tax_id -> percentage. Same map, same purpose, as the sell form's.
    const TAX_RATES = @json($taxAmounts);
    const OUT_OF_STOCK = @json(__('lang_v1.out_of_stock'));

    /* --- Offline -----------------------------------------------------------
       `window.Souqly.offline` is the bridge from resources/js/offline.js, which
       this inline script cannot import. Every use of it is optional-chained: if
       the bundle failed to load, or the browser has no IndexedDB, the terminal
       must still take a sale over the network exactly as it did before this
       feature existed. An offline layer that can break the online till is worse
       than no offline layer.

       RESOLVED LATER, NOT HERE. This script is a classic <script> in the body, so
       it runs while the document is still parsing; `app.js` is a module, and a
       module is deferred by definition. Reading `window.Souqly` on this line
       would read it before it exists, and the whole offline layer would be
       silently inert. The assignment happens on DOMContentLoaded, by which point
       every module has been evaluated. */
    let OFFLINE = null;
    let OFFLINE_MODE = false;

    @php
        /* Assembled into a variable because Blade's `json` directive splits its
           argument on commas and reassigns the pieces to json_encode's `$flags`
           and `$depth` (CompilesJson.php:22). An eight-key array inline compiled
           to a call holding three of the keys and an unclosed `[`, which is a
           ParseError in the compiled view — a 500 on the one screen this whole
           item exists for, from a source line that reads as correct. Same reason
           as the island in layouts/app.blade.php; see NOTES §16.23. */
        $offlineText = [
            'offline' => __('lang_v1.offline'),
            'saved' => __('lang_v1.offline_sale_saved'),
            'pending' => __('lang_v1.offline_pending_title'),
            'note' => __('lang_v1.offline_pending_note'),
            'snapshot' => __('lang_v1.offline_snapshot_note'),
            'full' => __('lang_v1.offline_queue_full'),
            'unavailable' => __('lang_v1.offline_unavailable'),
            'sale' => __('lang_v1.offline_queued_sale'),
        ];
    @endphp
    const OFFLINE_TEXT = @json($offlineText);

    const offlineBar = document.getElementById('pos-offline');
    const offlineTitle = document.getElementById('pos-offline-title');
    const offlineNote = document.getElementById('pos-offline-note');
    const offlineList = document.getElementById('pos-offline-list');
    const tempIdField = document.getElementById('offline_temp_id');
    const deviceIdField = document.getElementById('offline_device_id');

    /* Whether the grid currently shows the local snapshot rather than a live
       answer, and the hook the product loader uses to say so. Declared here
       because loadProducts() is defined above the offline section and calls it;
       the no-op default is also the correct behaviour when offline mode is off. */
    let usingSnapshot = false;
    let syncOfflineBar = function () {};

    let index = 0;
    let total = 0;

    const num = (el) => parseFloat(el?.value) || 0;
    const money = (value) => value.toFixed(2);

    /* --- Change ----------------------------------------------------------
       Defined before recalc() because recalc() ends by calling it: what is owed
       back changes the moment the basket does. */
    const updateChange = function () {
        const paid = parseFloat(tendered.value) || 0;
        const excess = Math.max(0, paid - total);

        // Never post more than the sale is worth: the rest is change, not credit.
        paidField.value = money(Math.min(paid, total));
        document.getElementById('change-due').textContent = money(excess);

        updateOverpay(excess);

        /* The shortfall and the excess cannot both be positive, so the two panels
           are never on screen together — but each is told its own figure rather
           than one being derived from the other's absence. */
        updateAdvance(Math.max(0, total - paid));
    };

    /* --- What the stored credit will pay for -----------------------------
       The server spends credit against whatever the sale is still owed after the
       tender, up to the balance and no further — PaymentService::
       applyAdvanceBalance(). This states that outcome before the sale is
       finalised; it does not decide it, and it posts no field. Nothing here is
       sent to the server, which is why a stale balance can only mislead the
       cashier for a moment rather than mis-record the sale.

       The walk-in row is excluded for the same reason it cannot be credited: its
       balance is not attributable to the person at the counter, so the server
       refuses to spend it and the terminal must not promise otherwise. */
    const spendableCredit = function () {
        return SHARED_CUSTOMERS.includes(Number(customerSelect.value)) ? 0 : customerCredit;
    };

    /* --- The chip on the register bar ------------------------------------
       Absent rather than zeroed when there is nothing to spend, for the reason
       given at the markup: a chip reading 0.00 on every walk-in sale is noise the
       eye learns to skip, and this figure has to be noticed on the sales where it
       exists. Driven from the same fetch as the panel below and through the same
       spendableCredit(), so the two cannot disagree about whether the customer is
       in funds. */
    const showCredit = function () {
        const spendable = spendableCredit();

        creditChip.classList.toggle('hidden', spendable <= 0.0001);
        creditValue.textContent = money(spendable);
    };

    const updateAdvance = function (shortfall) {
        const spendable = spendableCredit();
        const applied = Math.min(spendable, shortfall);

        advance.classList.toggle('hidden', applied <= 0.0001);

        if (applied <= 0.0001) return;

        document.getElementById('advance-text').textContent =
            @json(__('lang_v1.advance_will_cover')).replace(':amount', money(applied));

        /* One or the other, never both: the balance is spent as far as the sale
           needs it, so a remainder still due means the credit is now empty, and
           credit left over means the sale is covered. */
        const stillDue = shortfall - applied;

        document.getElementById('advance-note').textContent = stillDue > 0.0001
            ? @json(__('lang_v1.advance_remainder_due')).replace(':amount', money(stillDue))
            : @json(__('lang_v1.advance_left_after')).replace(':amount', money(spendable - applied));
    };

    /* --- Where the excess goes -------------------------------------------
       Three outcomes, and only one of them is a question.

       With a standing debt the excess comes off it and the panel says so — the
       cashier is told, not asked, because an overpayment from someone who owes
       money has one sensible reading. `overpay_action` is still sent as `credit`:
       that is the flag that means "do not just hand it back", and the server's
       payContactDue() decides the split between debt and credit itself. Doing
       that arithmetic here as well would give the terminal a second opinion about
       what is owed, computed from a figure that was already stale when it
       arrived.

       With no debt the two outcomes really do differ and the cashier picks. And
       for the shared walk-in customer there is only one honest answer, so the
       credit option is removed rather than shown and refused. */
    const updateOverpay = function (excess) {
        const isAnonymous = SHARED_CUSTOMERS.includes(Number(customerSelect.value));
        const show = excess > 0.0001;

        overpay.classList.toggle('hidden', !show);
        overpayAmount.value = money(excess);

        if (!show) return;

        const hasDebt = customerDue > 0.0001 && !isAnonymous;

        overpayDebt.classList.toggle('hidden', !hasDebt);
        overpayChoice.classList.toggle('hidden', hasDebt);

        if (hasDebt) {
            const applied = Math.min(excess, customerDue);

            document.getElementById('overpay-debt-text').textContent =
                @json(__('lang_v1.overpay_will_reduce_due')).replace(':amount', money(applied));

            // Said only when there is a remainder, because otherwise it is not true.
            const left = excess - applied;
            document.getElementById('overpay-debt-note').textContent = left > 0.0001
                ? @json(__('lang_v1.overpay_remainder_to_credit')).replace(':amount', money(left))
                : '';

            // The debt path is a credit-side decision even when every penny of it
            // lands on an invoice — `refund` would put the money back in the
            // customer's hand instead.
            overpayChoice.querySelector('[value="credit"]').checked = true;

            return;
        }

        creditOption.classList.toggle('hidden', isAnonymous);
        anonymousNote.classList.toggle('hidden', !isAnonymous);

        if (isAnonymous) {
            overpayChoice.querySelector('[value="refund"]').checked = true;
        }
    };

    /* What this customer owes and what they have already paid in — both figures in
       one request, asked once per customer rather than per keystroke.

       Failure is silent and falls back to zero for both: the endpoint being
       unreachable must not stop a sale, and zero is the safe answer — it means the
       terminal offers the cashier the choice instead of asserting a debt it cannot
       confirm, and states no credit rather than one it cannot confirm either.

       The two failures differ in consequence, and only one of them is invisible.
       An unconfirmed debt records nothing, so the worst outcome is a settlement
       done later from the contact screen. An unconfirmed *credit* is still spent by
       the server — applyAdvanceBalance() reads the balance from the database and
       does not consult this — so the sale is recorded correctly and it is the
       cashier who was not told. The banner after the sale says what was actually
       spent, which is the reason that message exists. */
    const loadCustomerDue = async function () {
        customerDue = 0;
        customerCredit = 0;

        const id = Number(customerSelect.value);

        if (!id || SHARED_CUSTOMERS.includes(id)) {
            showCredit();
            updateChange();
            return;
        }

        try {
            const response = await fetch(`/contacts/${id}/due`, {
                headers: {'Accept': 'application/json'},
                cache: 'no-store',
                signal: AbortSignal.timeout?.(4000),
            });

            if (response.ok) {
                const data = await response.json();
                customerDue = parseFloat(data.due) || 0;
                customerCredit = parseFloat(data.advance_balance) || 0;
            }
        } catch (error) {
            // Left at zero deliberately — see above.
        }

        showCredit();
        updateChange();
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

    /* Whether the grid draws picture boxes at all. Re-decided per result set,
       not once per page: a catalogue can be mostly photographed and still have
       a search that returns only the three products nobody photographed, and
       three placeholder icons in a row is exactly the screen this avoids. The
       skeleton follows the same flag so it never changes shape underneath the
       results it is standing in for. */
    const setMediaMode = function (on) {
        grid.classList.toggle('product-grid-media', on);
        gridLoading.classList.toggle('product-grid-media', on);
    };

    const renderProducts = function (products) {
        grid.replaceChildren();
        setMediaMode(products.some(function (product) { return product.image_url; }));

        products.forEach(function (product) {
            const fragment = tileTemplate.content.cloneNode(true);
            const tile = fragment.querySelector('[data-tile]');
            const thumb = fragment.querySelector('[data-thumb]');
            const image = thumb.querySelector('img');

            /* `image_url` is null when there is no file, not a placeholder URL —
               ProductController::getProducts() gates it on Product::hasImage()
               precisely so this branch can exist. */
            if (product.image_url) {
                image.src = product.image_url;
                image.alt = product.text ?? '';
                thumb.querySelector('svg').remove();
            } else {
                image.remove();
            }

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

    /* Exactly one of skeleton, empty state and results is on screen at a time.
       Which one depends on whether the grid is already holding something: a
       skeleton is the right answer to an empty region and the wrong answer to a
       region that already holds valid, tappable results, so a re-search dims the
       existing grid rather than throwing it away. Hiding the empty state while
       the skeleton is up also keeps the panel from being left blank if the fetch
       fails — this runs in the `finally`, so the empty state comes back. */
    const setLoading = function (on) {
        const bare = grid.children.length === 0;

        gridLoading.classList.toggle('hidden', ! (on && bare));
        grid.classList.toggle('is-busy', on && ! bare);

        if (bare) {
            gridEmpty.classList.toggle('hidden', on);
        }
    };

    const loadProducts = async function (term) {
        const params = new URLSearchParams({ term: term ?? '', location_id: locationSelect.value });

        if (priceGroup.value) {
            params.set('price_group_id', priceGroup.value);
        }

        const request = ++inFlight;
        setLoading(true);

        /* The local snapshot, used when the server cannot answer. Returned
           through the same code path as a live result — same row shape, same
           renderProducts() — so nothing downstream has to know which one it got.

           `[]` is still the answer when there is no snapshot either. An empty
           grid with the designed empty state under it is honest; a grid of
           stale rows from a different location would not be. */
        const fallback = async function () {
            if (!OFFLINE_MODE) return [];

            try {
                return await OFFLINE.searchProducts(term ?? '', locationSelect.value);
            } catch (error) {
                return [];
            }
        };

        try {
            const response = await fetch('{{ route('products.list') }}?' + params, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });

            /* A 5xx or a captive portal's login page is not a live catalogue, so
               it degrades to the snapshot rather than to nothing. A 403 does the
               same, which is deliberate: whether the session expired or the
               uplink died, the till is on its own either way. */
            const results = response.ok ? await response.json() : await fallback();

            /* Gated on OFFLINE_MODE, not on the response alone: with offline
               selling switched off there is no snapshot to be showing, so a 500
               from the catalogue must leave the grid's own empty state to explain
               itself rather than put "cached prices" in front of a cashier who
               has none. */
            usingSnapshot = OFFLINE_MODE && ! response.ok;

            // A slower earlier request must not overwrite a later one's grid.
            if (request === inFlight) {
                renderProducts(results);
            }

            return results;
        } catch (error) {
            const results = await fallback();

            usingSnapshot = OFFLINE_MODE;

            if (request === inFlight) {
                renderProducts(results);
            }

            return results;
        } finally {
            if (request === inFlight) {
                setLoading(false);
                syncOfflineBar();
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

    /* The customer changes what the excess is *for*, not what anything costs, so
       this refetches the debt and leaves the grid alone. Read on load as well as
       on change: the terminal can arrive with a customer already selected, either
       from `defaultCustomer` or from a rejected sale being put back on screen. */
    customerSelect.addEventListener('change', loadCustomerDue);
    loadCustomerDue();

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

    /* --- Offline -----------------------------------------------------------
       Everything from here to the submit handler is the terminal's half of the
       offline layer. The device-side machinery — IndexedDB, the queue, the
       snapshot, the drain — lives in resources/js/offline.js; what is here is
       the part that has to know about *this* screen: which form to serialise,
       what to reset afterwards, and what to tell the cashier. */

    /* Is the server actually there, right now?

       `navigator.onLine` only knows whether a cable is plugged in, and the header
       badge's answer can be up to `ping_interval` seconds old — which on a till
       is long enough to take a sale into a dead uplink. So the sale asks for
       itself, immediately before posting. The timeout matters as much as the
       probe: without it a half-open connection would hang the sale indefinitely,
       and the cashier would have no idea whether to take the money. */
    const reachable = async function () {
        if (!navigator.onLine) return false;

        try {
            const response = await fetch('/api/ping', {
                cache: 'no-store',
                signal: AbortSignal.timeout?.(3000),
            });

            return response.ok;
        } catch (error) {
            return false;
        }
    };

    const resetTerminal = function () {
        cart.replaceChildren();
        tendered.value = '';
        document.getElementById('additional_notes').value = '';
        tempIdField.value = '';

        /* Back to cash-back explicitly. A radio is not cleared by emptying the
           basket, so without this a cashier who once chose to keep an excess as
           credit would keep doing it to every customer after — the panel is
           hidden between sales, so nobody would see the choice still sitting
           there. */
        overpayChoice.querySelector('[value="refund"]').checked = true;

        closePayment();
        recalc();

        /* The debt this terminal was holding is now wrong in both directions: the
           sale just added to it, or the excess just came off it. Refetching also
           calls updateChange(), which is what recalc() would have done. */
        loadCustomerDue();

        document.getElementById('finalize').disabled = false;
        search.focus();
    };

    /* --- The bar -----------------------------------------------------------

       Two independent facts share it, and they are ranked rather than merged:

         the queue — sales taken here that the server has not acknowledged. This
         is the one that goes in the title, because it is money.

         the snapshot — the grid is showing cached prices. Real, worth saying,
         but second: a stale price is a small error and the cashier can see the
         number they are charging.

       The third line is transient — what just happened — and it goes in the note
       rather than the title, so a "saved on this device" cannot paint over a
       standing count of three unsent sales.

       Both facts are read from state rather than passed in, so whichever of the
       two changes can call this without knowing about the other. */
    let notice = '';

    const renderOfflineBar = function (sales) {
        if (!offlineBar) return;

        if (sales) {
            offlineList.replaceChildren();

            sales.forEach(function (sale) {
                const value = (sale.lines ?? []).reduce(function (sum, line) {
                    return sum + (parseFloat(line.quantity) || 0) * (parseFloat(line.unit_price) || 0);
                }, 0);

                const when = new Date(sale.created_at);
                const item = document.createElement('li');

                const label = document.createElement('span');
                label.textContent = OFFLINE_TEXT.sale
                    .replace(':time', isNaN(when) ? '—' : when.toLocaleTimeString())
                    .replace(':count', String((sale.lines ?? []).length));

                const amount = document.createElement('span');
                amount.className = 'font-mono tabular-nums font-semibold force-ltr';
                amount.textContent = money(value);

                item.append(label, amount);
                offlineList.append(item);
            });
        }

        const queued = offlineList.children.length;

        if (queued === 0 && !usingSnapshot && notice === '') {
            offlineBar.classList.add('hidden');
            queueFit();

            return;
        }

        offlineBar.classList.remove('hidden');

        offlineTitle.textContent = queued > 0
            ? OFFLINE_TEXT.pending.replace(':count', String(queued))
            : OFFLINE_TEXT.offline;

        offlineNote.textContent = notice !== ''
            ? notice
            : (queued > 0 ? OFFLINE_TEXT.note : OFFLINE_TEXT.snapshot);

        // The bar changes the shell's top offset without changing the size of the
        // element the ResizeObserver watches, so ask for the refit explicitly.
        queueFit();
    };

    /* A one-line message that outlives a single render but not the next real
       change of state — which is why it is cleared by the queue announcement
       rather than by a timer. A cashier who looks up eight seconds later must
       still see what happened. */
    const flash = function (message) {
        notice = message;

        if (!offlineBar) return window.alert(message);

        renderOfflineBar(null);
    };

    syncOfflineBar = function () { renderOfflineBar(null); };

    /* Everything above is inert until this runs. Registered here rather than
       called: `app.js` publishes the bridge when its module is evaluated, which is
       after this script but before DOMContentLoaded.

       Ordering with app.js's own DOMContentLoaded work is not left to luck. This
       listener is registered during parsing and app.js's inside a module, so this
       one runs first — which is what it needs, because `initOffline()` announces
       the queue immediately and the listener below has to already exist to hear
       the first announcement. */
    document.addEventListener('DOMContentLoaded', function () {
        const bridge = window.Souqly?.offline ?? null;
        const settings = bridge?.settings?.() ?? {};

        OFFLINE = bridge;
        OFFLINE_MODE = !!(bridge && settings.enabled && settings.offline_mode);

        if (!OFFLINE_MODE) return;

        deviceIdField.value = OFFLINE.deviceId();

        document.addEventListener('souqly:queue', function (event) {
            notice = '';
            renderOfflineBar(event.detail?.sales ?? []);
        });

        document.getElementById('pos-offline-retry').addEventListener('click', function () {
            OFFLINE.sync();
            loadProducts(search.value.trim());
        });

        /* Keep the snapshot current while the shop is open, because the moment it
           is needed is the moment it cannot be fetched. On load and on a change of
           location or price group — the two selects that change what a product
           costs and what is on the shelf.

           Failures are swallowed on purpose: a terminal that cannot reach the
           server has nothing to gain from being told so here, and the connection
           badge is already saying it. */
        const refreshSnapshot = function () {
            OFFLINE.refreshSnapshot(locationSelect.value, priceGroup.value || null)
                .catch(function () { /* offline — the previous snapshot stands */ });
        };

        [locationSelect, priceGroup].forEach(function (select) {
            select.addEventListener('change', refreshSnapshot);
        });

        if (navigator.onLine) {
            refreshSnapshot();
        } else {
            /* The shop opened with the uplink already down. The first grid load
               ran during parsing, before the bridge existed, so it had no snapshot
               to fall back to and rendered nothing. Ask again now that it does —
               otherwise a till that was rebooted during an outage would show an
               empty catalogue for as long as the outage lasted, which is the exact
               scenario this whole layer is for. */
            loadProducts(search.value.trim());
        }

        /* The write-ahead copy of the sale that just posted successfully. Dropping
           it here is a courtesy — see offline.js's note on forget() — so a missing
           acknowledgement is not an error path. */
        const acknowledged = @json(session('offline_acknowledged'));

        if (acknowledged) OFFLINE.forget(acknowledged);
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

    /*
     * Finalising a sale.
     *
     * WRITE-AHEAD, THEN POST. In that order, and the order is the whole design.
     *
     * The obvious shape — try the network, queue if it fails — loses a sale in
     * the one case that matters most: the POST leaves the browser, the uplink
     * dies halfway, and the navigation lands on an error page with the basket
     * gone. A till on shop wifi does that. So the sale is written to the device
     * first, while nothing can interrupt it, and only then sent.
     *
     * Which leaves the copy to clean up, and that is what the temp id is for. It
     * rides along on the request, the server stores it, and the sale on the
     * device and the row in the database are afterwards the same sale by
     * identity. The redirect hands the id back and the copy is dropped; if that
     * acknowledgement never arrives, the ordinary queue drain sends it, the
     * server recognises the id and answers `duplicate`, and it is dropped then
     * instead. Two paths, one outcome, and the unique index behind both.
     *
     * With offline mode switched off none of this runs and the handler does what
     * it always did: disable the button and let the browser post.
     */
    form.addEventListener('submit', async function (event) {
        // A double tap on a touch screen is two sales otherwise.
        document.getElementById('finalize').disabled = true;

        if (!OFFLINE_MODE) return;

        event.preventDefault();

        const payload = OFFLINE.serialise(form);

        // Our own bookkeeping fields, not part of the sale. The temp id is
        // generated by queueSale() and the device id is stamped there too.
        delete payload.offline_temp_id;
        delete payload.offline_device_id;

        let queued = null;

        try {
            queued = await OFFLINE.queueSale(payload);

            /*
             * Both fields, so the row the server writes carries the same
             * provenance as the copy on the device. The temp id is what makes
             * them the same sale; the device id is what says which till took it —
             * the only per-terminal attribution the schema has, and it would
             * otherwise be recorded for sales that synced from a queue and left
             * blank for the ones taken at the counter.
             *
             * `resetTerminal()` clears the temp id and deliberately not this: a
             * stale temp id would make the next sale a duplicate, whereas the
             * device id is a property of the machine and true for every sale it
             * ever takes.
             */
            tempIdField.value = queued.temp_id;
            deviceIdField.value = queued.device_id;
        } catch (error) {
            /*
             * The device cannot hold the sale. If the server is reachable the sale
             * is still perfectly takeable — post it and lose only the safety net.
             * If it is not, this is the one case where the terminal has to refuse,
             * and it says which of the two reasons it is: a full queue is a shop
             * that has been offline too long and needs someone to look at it, and
             * that is a different message from a browser with no storage.
             */
            if (!(await reachable())) {
                flash(
                    error instanceof OFFLINE.QueueFullError
                        ? OFFLINE_TEXT.full.replace(':count', String(error.count ?? 0))
                        : OFFLINE_TEXT.unavailable
                );

                document.getElementById('finalize').disabled = false;

                return;
            }

            form.submit();

            return;
        }

        if (await reachable()) {
            // Native submit: bypasses this listener, so no recursion, and the
            // sale posts exactly as it did before any of this existed.
            form.submit();

            return;
        }

        resetTerminal();
        flash(OFFLINE_TEXT.saved);
    });

    /* --- Start ------------------------------------------------------------
       A rejected sale comes back through back()->withInput(), and a cashier
       should not have to rebuild a twenty-item basket because the server refused
       it. The label rides along in the field the validator drops, so putting a
       line back needs no second lookup. */
    /* `old('lines') ?? []`, not `old('lines', [])`. The second form has a comma
       in it, and the `json` directive hands everything after the first comma to
       json_encode as its `$flags` — so it compiled to
       `json_encode(old('lines', []), 512)`: valid PHP, but 512 is
       JSON_PARTIAL_OUTPUT_ON_ERROR rather than the HEX-escaping set the
       directive is supposed to apply. That matters here more than at the other
       two sites, because this one replays *user input* into a `<script>`. */
    Object.values(@json(old('lines') ?? [])).forEach(function (line) {
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
