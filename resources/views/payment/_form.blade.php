{{--
    Add / edit a payment — the one form behind `payments.create` and
    `payments.edit`.

    It has two modes, because the controller has two: a payment against ONE
    document (`?transaction_id=`, the road in from an invoice) and a settlement of
    a contact's WHOLE balance (`?contact_id=`). They share every field but the
    target, so splitting them into two screens would mean maintaining the card and
    cheque detail groups twice.

    Expects: document, contact, payment, due, paid, contactDue, advanceBalance,
    accounts, contacts, methods, dueTypes, defaultDueType, returnUrl.
--}}
@php
    $isEdit = ! empty($payment);

    /* No document means the amount is settling a balance, which is also the case
       in which the controller requires a due_type — one flag, both consequences. */
    $isSettlement = empty($document);

    $currentMethod = old('method', $payment->method ?? 'cash');

    /* The amount that is actually owed, whichever mode we are in. It seeds the
       field, and it is the ceiling the controller enforces on a single document —
       so offering it as the default is not a convenience, it is the answer. */
    $owed = $isSettlement ? (float) ($contactDue ?? 0) : (float) ($due ?? 0);

    $defaultAmount = old('amount', $isEdit
        ? number_format((float) $payment->amount, 2, '.', '')
        : ($owed > 0 ? number_format($owed, 2, '.', '') : ''));

    $defaultPaidOn = old('paid_on', $isEdit
        ? $payment->paid_on?->format('Y-m-d')
        : now()->format('Y-m-d'));

    /* Which extra fields a method needs. Rendered as one group per method and
       narrowed to the chosen one by script, so the normal cash payment is four
       fields rather than sixteen. */
    $methodPanels = [
        'card' => 'card',
        'cheque' => 'cheque',
        'bank_transfer' => 'bank_transfer',
        'other' => 'transaction_no',
        'custom_pay_1' => 'transaction_no',
        'custom_pay_2' => 'transaction_no',
        'custom_pay_3' => 'transaction_no',
        'custom_pay_4' => 'transaction_no',
        'custom_pay_5' => 'transaction_no',
        'custom_pay_6' => 'transaction_no',
        'custom_pay_7' => 'transaction_no',
    ];
@endphp

<form method="POST"
      action="{{ $isEdit ? route('payments.update', $payment->id) : route('payments.store') }}"
      data-payment-form>
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif

    {{-- The target travels in the body, not the query string: `store()` reads
         both from the request, so a POST carries it without the form having to
         reconstruct a URL. --}}
    @if ($document)
        <input type="hidden" name="transaction_id" value="{{ $document->id }}">
    @elseif ($contact && ! $isEdit)
        <input type="hidden" name="contact_id" value="{{ $contact->id }}">
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="grid gap-6 self-start lg:col-span-2">

            {{-- ============ Who is being paid ============
                 Only when nobody has been chosen yet. Reached from the payments
                 list, where the screen has no target at all and `store()` would
                 refuse the save. --}}
            @if ($isSettlement && ! $contact)
                <x-panel :title="__('lang_v1.who_is_this_for')" icon="users"
                         :subtitle="__('lang_v1.pick_a_contact_to_settle')">
                    <div class="form-grid">
                        <div class="field">
                            <label for="contact_picker" class="label label-required">
                                {{ __('lang_v1.contact') }}
                            </label>
                            {{-- A GET reload rather than a live lookup: choosing a
                                 contact has to bring their open balance and advance
                                 credit with it, and those are the figures the amount
                                 field is checked against. --}}
                            <select id="contact_picker" class="select" data-contact-picker
                                    data-base="{{ route('payments.create') }}">
                                @foreach ($contacts as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                            <p class="hint">{{ __('lang_v1.choosing_reloads_with_balance') }}</p>
                        </div>
                    </div>
                </x-panel>
            @endif

            {{-- ============ The payment itself ============ --}}
            <x-panel :title="__('lang_v1.payment_details')" icon="cash">

                {{-- Context as a tinted region rather than a second card: it is
                     what the figures below refer to, not a separate record. --}}
                <div class="surface-quiet mb-6">
                    <p class="section-label">{{ __('lang_v1.paying_for') }}</p>

                    @if ($document)
                        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <x-document-link :transaction="$document"/>
                            <span class="text-sm text-slate-500">
                                {{ __('lang_v1.'.$document->type) }}
                            </span>
                            @if ($contact)
                                <span class="text-slate-300">&middot;</span>
                                <span class="text-sm font-medium text-slate-700">
                                    {{ $contact->full_name_with_business }}
                                </span>
                            @endif
                        </div>

                        <dl class="dl mt-3">
                            <div class="dl-row">
                                <dt class="dl-key">{{ __('lang_v1.total') }}</dt>
                                <dd class="dl-value">@format_currency($document->final_total)</dd>
                            </div>
                            <div class="dl-row">
                                <dt class="dl-key">{{ __('lang_v1.paid') }}</dt>
                                <dd class="dl-value">@format_currency($paid ?? 0)</dd>
                            </div>
                            <div class="dl-row">
                                <dt class="dl-key">{{ __('lang_v1.due') }}</dt>
                                <dd @class(['dl-value', 'font-semibold text-rose-600' => $owed > 0])>
                                    @format_currency($owed)
                                </dd>
                            </div>
                        </dl>
                    @elseif ($contact)
                        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <span class="font-semibold text-slate-900">
                                {{ $contact->full_name_with_business }}
                            </span>
                            <span class="badge-muted">{{ __('lang_v1.'.$contact->type) }}</span>
                        </div>

                        {{-- Oldest-first allocation across every open document, with
                             any excess banked as advance credit. Said in words,
                             because it is the one thing about this mode a user
                             cannot see from the fields. --}}
                        <p class="hint">{{ __('lang_v1.settlement_allocates_oldest_first') }}</p>

                        <dl class="dl mt-3">
                            <div class="dl-row">
                                <dt class="dl-key">{{ __('lang_v1.open_balance') }}</dt>
                                <dd @class(['dl-value', 'font-semibold text-rose-600' => $owed > 0])>
                                    @format_currency($owed)
                                </dd>
                            </div>
                            @if ($advanceBalance > 0)
                                <div class="dl-row">
                                    <dt class="dl-key">{{ __('lang_v1.advance_balance') }}</dt>
                                    <dd class="dl-value text-emerald-700">
                                        @format_currency($advanceBalance)
                                    </dd>
                                </div>
                            @endif
                        </dl>
                    @else
                        <p class="text-sm text-slate-500">{{ __('lang_v1.no_target_chosen_yet') }}</p>
                    @endif
                </div>

                <div class="form-grid">
                    {{-- Which ledger a balance settlement belongs to. Required by
                         the controller whenever there is no document, because a
                         contact can be both a customer and a supplier. --}}
                    @if ($isSettlement)
                        <div class="field sm:col-span-2">
                            <label for="due_type" class="label label-required">
                                {{ __('lang_v1.settling') }}
                            </label>
                            <select id="due_type" name="due_type" class="select"
                                    @if (! $isEdit) data-due-type
                                        data-base="{{ route('payments.create') }}"
                                        data-contact="{{ $contact->id ?? '' }}" @endif>
                                @foreach ($dueTypes as $value => $label)
                                    <option value="{{ $value }}"
                                        @selected(old('due_type', $defaultDueType) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('due_type')<p class="field-error">{{ $message }}</p>@enderror
                        </div>
                    @endif

                    <div class="field">
                        <label for="amount" class="label label-required">{{ __('lang_v1.amount') }}</label>
                        <input type="number" step="0.01" min="0.01" id="amount" name="amount"
                               value="{{ $defaultAmount }}"
                               class="input-numeric @error('amount') input-invalid @enderror"
                               required autofocus>
                        @error('amount')
                            <p class="field-error">{{ $message }}</p>
                        @else
                            @if (! $isSettlement)
                                <p class="hint">{{ __('lang_v1.cannot_exceed_due') }}</p>
                            @endif
                        @enderror
                    </div>

                    <div class="field">
                        <label for="paid_on" class="label label-required">{{ __('lang_v1.paid_on') }}</label>
                        <input type="date" id="paid_on" name="paid_on" value="{{ $defaultPaidOn }}"
                               class="input @error('paid_on') input-invalid @enderror" required>
                        @error('paid_on')<p class="field-error">{{ $message }}</p>@enderror
                    </div>

                    <div class="field">
                        <label for="method" class="label label-required">
                            {{ __('lang_v1.payment_method') }}
                        </label>
                        <select id="method" name="method" class="select" data-method required>
                            @foreach ($methods as $value => $label)
                                <option value="{{ $value }}" @selected($currentMethod === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('method')<p class="field-error">{{ $message }}</p>@enderror
                    </div>

                    <div class="field">
                        <label for="account_id" class="label">{{ __('lang_v1.account') }}</label>
                        <select id="account_id" name="account_id" class="select">
                            @foreach ($accounts as $id => $label)
                                <option value="{{ $id }}"
                                    @selected(old('account_id', $payment->account_id ?? null) == $id)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="hint">{{ __('lang_v1.account_mirrors_the_payment') }}</p>
                    </div>
                </div>

                {{-- ============ Method-specific detail ============
                     One group per method, all present in the markup and narrowed
                     to the chosen one by script. Without JS every group shows —
                     more fields than needed, but nothing unreachable. --}}
                <div class="mt-6" data-method-groups>
                    <noscript>
                        <style>[data-method-panel] { display: block !important; }</style>
                    </noscript>

                    <div data-method-panel="card" hidden>
                        <p class="section-label">{{ __('lang_v1.card_details') }}</p>
                        <div class="form-grid-3">
                            <div class="field">
                                <label for="card_number" class="label">{{ __('lang_v1.card_number') }}</label>
                                <input id="card_number" name="card_number" dir="ltr"
                                       value="{{ old('card_number', $payment->card_number ?? '') }}" class="input">
                            </div>
                            <div class="field">
                                <label for="card_holder_name" class="label">{{ __('lang_v1.card_holder_name') }}</label>
                                <input id="card_holder_name" name="card_holder_name"
                                       value="{{ old('card_holder_name', $payment->card_holder_name ?? '') }}" class="input">
                            </div>
                            <div class="field">
                                <label for="card_transaction_number" class="label">
                                    {{ __('lang_v1.card_transaction_number') }}
                                </label>
                                <input id="card_transaction_number" name="card_transaction_number" dir="ltr"
                                       value="{{ old('card_transaction_number', $payment->card_transaction_number ?? '') }}"
                                       class="input">
                            </div>
                            <div class="field">
                                <label for="card_type" class="label">{{ __('lang_v1.card_type') }}</label>
                                <input id="card_type" name="card_type"
                                       value="{{ old('card_type', $payment->card_type ?? '') }}" class="input">
                            </div>
                            <div class="field">
                                <label for="card_month" class="label">{{ __('lang_v1.expiry_month') }}</label>
                                <input id="card_month" name="card_month" dir="ltr" inputmode="numeric" maxlength="2"
                                       value="{{ old('card_month', $payment->card_month ?? '') }}" class="input">
                            </div>
                            <div class="field">
                                <label for="card_year" class="label">{{ __('lang_v1.expiry_year') }}</label>
                                <input id="card_year" name="card_year" dir="ltr" inputmode="numeric" maxlength="4"
                                       value="{{ old('card_year', $payment->card_year ?? '') }}" class="input">
                            </div>
                        </div>
                    </div>

                    <div data-method-panel="cheque" hidden>
                        <p class="section-label">{{ __('lang_v1.cheque_details') }}</p>
                        <div class="form-grid">
                            <div class="field">
                                <label for="cheque_number" class="label">{{ __('lang_v1.cheque_number') }}</label>
                                <input id="cheque_number" name="cheque_number" dir="ltr"
                                       value="{{ old('cheque_number', $payment->cheque_number ?? '') }}" class="input">
                            </div>
                        </div>
                    </div>

                    <div data-method-panel="bank_transfer" hidden>
                        <p class="section-label">{{ __('lang_v1.bank_details') }}</p>
                        <div class="form-grid">
                            <div class="field">
                                <label for="bank_account_number" class="label">
                                    {{ __('lang_v1.bank_account_number') }}
                                </label>
                                <input id="bank_account_number" name="bank_account_number" dir="ltr"
                                       value="{{ old('bank_account_number', $payment->bank_account_number ?? '') }}"
                                       class="input">
                            </div>
                        </div>
                    </div>

                    <div data-method-panel="transaction_no" hidden>
                        <p class="section-label">{{ __('lang_v1.transaction_details') }}</p>
                        <div class="form-grid">
                            <div class="field">
                                <label for="transaction_no" class="label">{{ __('lang_v1.transaction_no') }}</label>
                                <input id="transaction_no" name="transaction_no" dir="ltr"
                                       value="{{ old('transaction_no', $payment->transaction_no ?? '') }}" class="input">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-6">
                    <div class="field">
                        <label for="note" class="label">{{ __('lang_v1.note') }}</label>
                        <textarea id="note" name="note" rows="2" class="textarea"
                                  maxlength="255">{{ old('note', $payment->note ?? '') }}</textarea>
                        @error('note')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                </div>
            </x-panel>
        </div>

        {{-- ============ What this payment will do ============
             A short aside, not a second copy of the form. On a settlement it is
             the only place the allocation rule is visible. --}}
        <x-panel :title="__('lang_v1.summary')" icon="receipt" class="self-start">
            <dl class="dl">
                <div class="dl-row">
                    <dt class="dl-key">{{ __('lang_v1.owed') }}</dt>
                    <dd class="dl-value">@format_currency($owed)</dd>
                </div>
                <div class="dl-total">
                    <dt class="font-semibold text-slate-900">{{ __('lang_v1.paying_now') }}</dt>
                    <dd class="dl-total-value" data-preview-amount>
                        @format_currency($defaultAmount === '' ? 0 : (float) $defaultAmount)
                    </dd>
                </div>
                <div class="dl-row">
                    <dt class="dl-key">{{ __('lang_v1.remaining') }}</dt>
                    <dd class="dl-value" data-preview-remaining>
                        @format_currency(max(0, $owed - ($defaultAmount === '' ? 0 : (float) $defaultAmount)))
                    </dd>
                </div>
            </dl>

            @if ($isSettlement)
                <p class="hint mt-4">{{ __('lang_v1.excess_becomes_advance') }}</p>
            @endif
        </x-panel>
    </div>

    <div class="form-actions">
        <span class="form-actions-spacer">
            @if ($isEdit)
                {{ __('lang_v1.editing_a_payment_recomputes_status') }}
            @endif
        </span>

        <a href="{{ $returnUrl }}" class="btn-secondary">{{ __('lang_v1.cancel') }}</a>

        <button type="submit" class="btn-accent">
            <x-nav-icon name="check"/>
            {{ $isEdit ? __('lang_v1.update') : __('lang_v1.record_payment') }}
        </button>
    </div>
</form>

@push('scripts')
<script>
    /* Three small behaviours, no framework:
       1. show only the detail group the chosen method needs;
       2. keep the "paying now / remaining" preview in step with the amount;
       3. reload the create screen when the target or the ledger side changes,
          because both change the figures the server computed. */
    (function () {
        const form = document.querySelector('[data-payment-form]');
        if (! form) return;

        const PANEL_FOR = @json($methodPanels);

        const methodSelect = form.querySelector('[data-method]');
        const panels = form.querySelectorAll('[data-method-panel]');

        function syncPanels() {
            const wanted = PANEL_FOR[methodSelect ? methodSelect.value : ''] || null;

            panels.forEach((panel) => {
                panel.hidden = panel.dataset.methodPanel !== wanted;
            });
        }

        if (methodSelect) {
            methodSelect.addEventListener('change', syncPanels);
            syncPanels();
        }

        /* The preview is decoration over a server-side truth, so it formats with
           the browser's locale rather than reimplementing the currency helper —
           and it is never what gets saved. */
        const amount = form.querySelector('#amount');
        const owed = {{ (float) $owed }};
        const paying = form.querySelector('[data-preview-amount]');
        const remaining = form.querySelector('[data-preview-remaining]');

        if (amount && paying && remaining) {
            const fmt = new Intl.NumberFormat(document.documentElement.lang || 'en', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });

            amount.addEventListener('input', function () {
                const value = parseFloat(amount.value) || 0;

                paying.textContent = fmt.format(value);
                remaining.textContent = fmt.format(Math.max(0, owed - value));
            });
        }

        const picker = form.querySelector('[data-contact-picker]');

        if (picker) {
            picker.addEventListener('change', function () {
                if (! picker.value) return;

                window.location = picker.dataset.base + '?contact_id=' + encodeURIComponent(picker.value);
            });
        }

        const dueType = form.querySelector('[data-due-type]');

        if (dueType && dueType.dataset.contact) {
            dueType.addEventListener('change', function () {
                window.location = dueType.dataset.base
                    + '?contact_id=' + encodeURIComponent(dueType.dataset.contact)
                    + '&due_type=' + encodeURIComponent(dueType.value);
            });
        }
    })();
</script>
@endpush
