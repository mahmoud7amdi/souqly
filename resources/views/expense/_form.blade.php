{{--
    Add / edit an expense.

    Four groups, in the order a person fills them: what it was, how much, who owes
    it, and — only if asked for — how often it repeats. The payment group appears
    on create only, because on edit the payments already exist and belong to the
    payments screen; editing an amount here and a payment there would let the two
    disagree silently.

    Expects: expense, locations, categories, subCategories, users, contacts,
    taxes, taxAmounts, accounts, methods, intervalTypes.
--}}
@php
    $isEdit = ! empty($expense);

    $isRefund = (bool) old('is_refund', $isEdit
        ? $expense->type === \App\Support\TransactionTypes::EXPENSE_REFUND
        : false);

    $isRecurring = (bool) old('is_recurring', $expense->is_recurring ?? false);

    $parentId = old('expense_category_id', $expense->expense_category_id ?? '');
    $subId = old('expense_sub_category_id', $expense->expense_sub_category_id ?? '');

    $net = old('total_before_tax', $isEdit
        ? number_format((float) $expense->total_before_tax, 2, '.', '')
        : '');
@endphp

<form method="POST"
      action="{{ $isEdit ? route('expenses.update', $expense->id) : route('expenses.store') }}"
      data-expense-form>
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="grid gap-6 self-start lg:col-span-2">

            {{-- ============ What it was ============ --}}
            <x-panel :title="__('lang_v1.expense_details')" icon="receipt">
                <div class="form-grid">
                    <div class="field">
                        <label for="location_id" class="label label-required">
                            {{ __('lang_v1.business_location') }}
                        </label>
                        <select id="location_id" name="location_id" class="select" required>
                            @foreach ($locations as $id => $name)
                                <option value="{{ $id }}"
                                    @selected(old('location_id', $expense->location_id ?? null) == $id)>{{ $name }}</option>
                            @endforeach
                        </select>
                        @error('location_id')<p class="field-error">{{ $message }}</p>@enderror
                    </div>

                    <div class="field">
                        <label for="transaction_date" class="label label-required">
                            {{ __('lang_v1.date') }}
                        </label>
                        <input type="date" id="transaction_date" name="transaction_date"
                               value="{{ old('transaction_date', $isEdit
                                    ? $expense->transaction_date?->format('Y-m-d')
                                    : now()->format('Y-m-d')) }}"
                               class="input @error('transaction_date') input-invalid @enderror" required>
                        @error('transaction_date')<p class="field-error">{{ $message }}</p>@enderror
                    </div>

                    <div class="field">
                        <label for="expense_category_id" class="label">
                            {{ __('lang_v1.expense_category') }}
                        </label>
                        <select id="expense_category_id" name="expense_category_id" class="select"
                                data-category>
                            @foreach ($categories as $id => $name)
                                <option value="{{ $id }}" @selected($parentId == $id)>{{ $name }}</option>
                            @endforeach
                        </select>
                        @error('expense_category_id')<p class="field-error">{{ $message }}</p>@enderror
                    </div>

                    {{-- Filled from the parent's children rather than listing every
                         sub-category in the business: a sub-category that is not a
                         child of the chosen parent is discarded server-side, so
                         offering it would be offering a choice that gets thrown
                         away. --}}
                    <div class="field">
                        <label for="expense_sub_category_id" class="label">
                            {{ __('lang_v1.sub_category') }}
                        </label>
                        <select id="expense_sub_category_id" name="expense_sub_category_id"
                                class="select" data-sub-category data-selected="{{ $subId }}">
                            <option value="">{{ __('lang_v1.none') }}</option>
                        </select>
                        <p class="hint" data-sub-empty hidden>{{ __('lang_v1.category_has_no_subs') }}</p>
                    </div>

                    <div class="field">
                        <label for="ref_no" class="label">{{ __('lang_v1.reference_no') }}</label>
                        <input id="ref_no" name="ref_no" dir="ltr"
                               value="{{ old('ref_no', $expense->ref_no ?? '') }}" class="input"
                               placeholder="{{ __('lang_v1.leave_blank_to_generate') }}">
                        @error('ref_no')<p class="field-error">{{ $message }}</p>@enderror
                    </div>

                    <div class="field">
                        <label for="expense_for" class="label">{{ __('lang_v1.expense_for') }}</label>
                        <select id="expense_for" name="expense_for" class="select">
                            @foreach ($users as $id => $name)
                                <option value="{{ $id }}"
                                    @selected(old('expense_for', $expense->expense_for ?? null) == $id)>{{ $name }}</option>
                            @endforeach
                        </select>
                        <p class="hint">{{ __('lang_v1.expense_for_hint') }}</p>
                    </div>

                    <div class="field">
                        <label for="contact_id" class="label">{{ __('lang_v1.supplier') }}</label>
                        <select id="contact_id" name="contact_id" class="select">
                            @foreach ($contacts as $id => $name)
                                <option value="{{ $id }}"
                                    @selected(old('contact_id', $expense->contact_id ?? null) == $id)>{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="field sm:col-span-2">
                        <label for="additional_notes" class="label">{{ __('lang_v1.note') }}</label>
                        <textarea id="additional_notes" name="additional_notes" rows="2" class="textarea"
                                  maxlength="1000">{{ old('additional_notes', $expense->additional_notes ?? '') }}</textarea>
                        @error('additional_notes')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                </div>

                {{-- A refund is the same record pointed the other way, so it is a
                     property of this expense rather than a separate screen — but it
                     changes the sign of the money, so it is set apart from the
                     fields above rather than sitting in the grid with them. --}}
                <div class="surface-quiet mt-6">
                    <div class="checkbox-row">
                        <input type="hidden" name="is_refund" value="0">
                        <input type="checkbox" id="is_refund" name="is_refund" value="1"
                               class="checkbox" @checked($isRefund)>
                        <div>
                            <label for="is_refund" class="checkbox-label">
                                {{ __('lang_v1.this_is_a_refund') }}
                            </label>
                            <p class="checkbox-hint">{{ __('lang_v1.refund_reduces_net_expense') }}</p>
                        </div>
                    </div>
                </div>
            </x-panel>

            {{-- ============ How often it repeats ============
                 Off by default and collapsed, because the overwhelming majority of
                 expenses happen once. Toggling it open is the user saying "this is
                 a subscription", and only then do the four fields matter. --}}
            <x-panel :title="__('lang_v1.recurring')" icon="refresh"
                     :subtitle="__('lang_v1.generate_future_occurrences')">
                <div class="checkbox-row">
                    <input type="hidden" name="is_recurring" value="0">
                    <input type="checkbox" id="is_recurring" name="is_recurring" value="1"
                           class="checkbox" data-recurring-toggle @checked($isRecurring)>
                    <div>
                        <label for="is_recurring" class="checkbox-label">
                            {{ __('lang_v1.repeat_this_expense') }}
                        </label>
                        <p class="checkbox-hint">{{ __('lang_v1.recurring_expense_hint') }}</p>
                    </div>
                </div>

                <div class="mt-5" data-recurring-fields @if (! $isRecurring) hidden @endif>
                    <noscript>
                        <style>[data-recurring-fields] { display: block !important; }</style>
                    </noscript>

                    <div class="form-grid-3">
                        <div class="field">
                            <label for="recur_interval" class="label">{{ __('lang_v1.every') }}</label>
                            <input type="number" min="1" max="365" id="recur_interval" name="recur_interval"
                                   value="{{ old('recur_interval', $expense->recur_interval ?? 1) }}"
                                   class="input-numeric">
                            @error('recur_interval')<p class="field-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="field">
                            <label for="recur_interval_type" class="label">{{ __('lang_v1.interval') }}</label>
                            <select id="recur_interval_type" name="recur_interval_type" class="select">
                                @foreach ($intervalTypes as $value => $label)
                                    <option value="{{ $value }}"
                                        @selected(old('recur_interval_type', $expense->recur_interval_type ?? 'months') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="field">
                            <label for="recur_repetitions" class="label">
                                {{ __('lang_v1.repetitions') }}
                            </label>
                            <input type="number" min="1" max="1000" id="recur_repetitions"
                                   name="recur_repetitions"
                                   value="{{ old('recur_repetitions', $expense->recur_repetitions ?? '') }}"
                                   class="input-numeric">
                            <p class="hint">{{ __('lang_v1.blank_repeats_forever') }}</p>
                        </div>

                        <div class="field sm:col-span-2">
                            <label for="subscription_no" class="label">
                                {{ __('lang_v1.subscription_no') }}
                            </label>
                            <input id="subscription_no" name="subscription_no" dir="ltr"
                                   value="{{ old('subscription_no', $expense->subscription_no ?? '') }}"
                                   class="input">
                        </div>
                    </div>
                </div>
            </x-panel>

            {{-- ============ Pay it now ============
                 Create only. On edit the payments are already rows of their own, and
                 the payments screen is where they are changed — a second amount
                 field here would let the expense and its payments drift apart. --}}
            @if (! $isEdit)
                <x-panel :title="__('lang_v1.payment')" icon="cash"
                         :subtitle="__('lang_v1.optional_leave_blank_if_unpaid')">
                    <div class="form-grid">
                        <div class="field">
                            <label for="payment_amount" class="label">{{ __('lang_v1.amount') }}</label>
                            <input type="number" step="0.01" min="0" id="payment_amount"
                                   name="payment_amount" value="{{ old('payment_amount') }}"
                                   class="input-numeric" data-payment-amount>
                            {{-- Zero is "not paid yet", not "paid nothing": the
                                 controller writes no payment row at all below 0.01,
                                 so the expense stays due rather than looking
                                 settled for nothing. --}}
                            <p class="hint">{{ __('lang_v1.zero_leaves_it_due') }}</p>
                        </div>

                        <div class="field">
                            <label for="payment_paid_on" class="label">{{ __('lang_v1.paid_on') }}</label>
                            <input type="date" id="payment_paid_on" name="payment_paid_on"
                                   value="{{ old('payment_paid_on') }}" class="input">
                            <p class="hint">{{ __('lang_v1.defaults_to_expense_date') }}</p>
                        </div>

                        <div class="field">
                            <label for="payment_method" class="label">
                                {{ __('lang_v1.payment_method') }}
                            </label>
                            <select id="payment_method" name="payment_method" class="select">
                                @foreach ($methods as $value => $label)
                                    <option value="{{ $value }}"
                                        @selected(old('payment_method', 'cash') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="field">
                            <label for="payment_account_id" class="label">{{ __('lang_v1.account') }}</label>
                            <select id="payment_account_id" name="payment_account_id" class="select">
                                @foreach ($accounts as $id => $label)
                                    <option value="{{ $id }}"
                                        @selected(old('payment_account_id') == $id)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="field sm:col-span-2">
                            <label for="payment_note" class="label">{{ __('lang_v1.payment_note') }}</label>
                            <input id="payment_note" name="payment_note" maxlength="255"
                                   value="{{ old('payment_note') }}" class="input">
                        </div>
                    </div>
                </x-panel>
            @endif
        </div>

        {{-- ============ The amount ============
             In the rail, not in the grid with the other fields, because it is the
             one number on the screen and the tax below it is derived from it. --}}
        <x-panel :title="__('lang_v1.amount')" icon="calculator" class="self-start">
            <div class="field">
                <label for="total_before_tax" class="label label-required">
                    {{ __('lang_v1.net_amount') }}
                </label>
                <input type="number" step="0.01" min="0" id="total_before_tax" name="total_before_tax"
                       value="{{ $net }}"
                       class="input-numeric input-lg @error('total_before_tax') input-invalid @enderror"
                       required autofocus data-net>
                @error('total_before_tax')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field mt-4">
                <label for="tax_id" class="label">{{ __('lang_v1.tax') }}</label>
                <select id="tax_id" name="tax_id" class="select" data-tax
                        data-amounts="{{ json_encode($taxAmounts) }}">
                    @foreach ($taxes as $id => $name)
                        <option value="{{ $id }}"
                            @selected(old('tax_id', $expense->tax_id ?? null) == $id)>{{ $name }}</option>
                    @endforeach
                </select>
                @error('tax_id')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            {{-- The server recomputes all three on save; this is a preview so the
                 person typing an amount can see the total they are committing to
                 before they commit to it. --}}
            <dl class="dl mt-5">
                <div class="dl-row">
                    <dt class="dl-key">{{ __('lang_v1.net_amount') }}</dt>
                    <dd class="dl-value" data-preview-net>@format_currency($net === '' ? 0 : (float) $net)</dd>
                </div>
                <div class="dl-row">
                    <dt class="dl-key">{{ __('lang_v1.tax') }}</dt>
                    <dd class="dl-value" data-preview-tax>
                        @format_currency($isEdit ? $expense->tax_amount : 0)
                    </dd>
                </div>
                <div class="dl-total">
                    <dt class="font-semibold text-slate-900">{{ __('lang_v1.total') }}</dt>
                    <dd class="dl-total-value" data-preview-total>
                        @format_currency($isEdit
                            ? $expense->final_total
                            : ($net === '' ? 0 : (float) $net))
                    </dd>
                </div>
            </dl>
        </x-panel>
    </div>

    <div class="form-actions">
        <span class="form-actions-spacer">{{ __('lang_v1.ref_no_generated_if_blank') }}</span>

        <a href="{{ route('expenses.index') }}" class="btn-secondary">{{ __('lang_v1.cancel') }}</a>

        @if (! $isEdit)
            <button type="submit" name="save_and_add_another" value="1" class="btn-secondary">
                {{ __('lang_v1.save_and_add_another') }}
            </button>
        @endif

        <button type="submit" class="btn-primary">
            <x-nav-icon name="save" :size="4"/>
            {{ $isEdit ? __('lang_v1.update') : __('lang_v1.save') }}
        </button>
    </div>
</form>

@push('scripts')
<script>
    /* Three behaviours: narrow the sub-category list to the chosen parent, keep the
       net/tax/total preview honest, and reveal the recurring fields only when
       someone asks for them. */
    (function () {
        const form = document.querySelector('[data-expense-form]');
        if (! form) return;

        /* --- sub-categories ------------------------------------------------ */
        const SUBS = @json($subCategories);

        const parent = form.querySelector('[data-category]');
        const sub = form.querySelector('[data-sub-category]');
        const subEmpty = form.querySelector('[data-sub-empty]');

        function fillSubs(keepSelection) {
            if (! parent || ! sub) return;

            const wanted = keepSelection ? sub.dataset.selected : '';
            const options = SUBS[parent.value] || {};
            const keys = Object.keys(options);

            sub.innerHTML = '';
            sub.appendChild(new Option(@json(__('lang_v1.none')), ''));

            keys.forEach(function (id) {
                sub.appendChild(new Option(options[id], id, false, String(id) === String(wanted)));
            });

            /* Disabled rather than hidden: a control that vanishes makes people
               think the form changed shape, and the label still explains what the
               field is for. */
            sub.disabled = keys.length === 0;
            if (subEmpty) subEmpty.hidden = keys.length > 0 || ! parent.value;
        }

        if (parent && sub) {
            fillSubs(true);
            parent.addEventListener('change', function () { fillSubs(false); });
        }

        /* --- amount preview ------------------------------------------------ */
        const net = form.querySelector('[data-net]');
        const tax = form.querySelector('[data-tax]');
        const outNet = form.querySelector('[data-preview-net]');
        const outTax = form.querySelector('[data-preview-tax]');
        const outTotal = form.querySelector('[data-preview-total]');

        if (net && tax && outNet && outTax && outTotal) {
            const RATES = JSON.parse(tax.dataset.amounts || '{}');
            const fmt = new Intl.NumberFormat(document.documentElement.lang || 'en', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });

            function preview() {
                const base = parseFloat(net.value) || 0;
                const rate = parseFloat(RATES[tax.value]) || 0;
                const amount = base * rate / 100;

                outNet.textContent = fmt.format(base);
                outTax.textContent = fmt.format(amount);
                outTotal.textContent = fmt.format(base + amount);
            }

            net.addEventListener('input', preview);
            tax.addEventListener('change', preview);
            preview();
        }

        /* --- recurring ----------------------------------------------------- */
        const recurring = form.querySelector('[data-recurring-toggle]');
        const recurringFields = form.querySelector('[data-recurring-fields]');

        if (recurring && recurringFields) {
            recurring.addEventListener('change', function () {
                recurringFields.hidden = ! recurring.checked;
            });
        }
    })();
</script>
@endpush
