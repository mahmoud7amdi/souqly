{{--
    Settle a contact's outstanding balance, without leaving their page.

    A dialog rather than a link to `payments.create?contact_id=`, because taking
    a payment off a customer is the one thing a clerk opens this screen to *do*,
    and that screen already exists for the case this one deliberately does not
    cover: card and cheque detail. So the split is by how much the payment needs
    saying, not by which record it belongs to —

        here                → amount, method, date, account, note
        payments.create     → the same, plus the sixteen card / cheque / transfer
                              fields, laid out as one group per method

    and the dialog carries a link to it rather than reproducing those groups in a
    box 512px wide. What posts goes to `payments.store` unchanged: that endpoint
    already settles a contact across their open documents, oldest first, and
    already redirects back here afterwards (see TransactionPaymentController::
    returnTo()). There is no second route and no new controller action.

    Rendered only when $canSettle and $settleDue > 0 — the caller decides, so an
    empty dialog is never pushed onto a page that cannot use it.

    Expects: contact, settleDue, settleDueType, methods, accounts.
--}}
<div id="settle-modal" class="modal-backdrop hidden" role="dialog" aria-modal="true"
     aria-labelledby="settle-modal-title">
    <div class="modal-panel">
        <form method="POST" action="{{ route('payments.store') }}">
            @csrf

            {{-- The target and the side of the ledger. Both are fixed by the page
                 rather than chosen in the dialog: this is *this* contact's debt,
                 and which side it sits on follows from what they are. --}}
            <input type="hidden" name="contact_id" value="{{ $contact->id }}">
            <input type="hidden" name="due_type" value="{{ $settleDueType }}">

            {{-- `payments.store` requires a date. The dialog does not ask for one
                 — a payment taken at the counter is dated today, and a clerk
                 backdating one is doing something deliberate enough to want the
                 full screen. --}}
            <input type="hidden" name="paid_on" value="{{ now()->format('Y-m-d') }}">

            <div class="card-header">
                <h3 class="card-title" id="settle-modal-title">{{ __('lang_v1.settle_due') }}</h3>
                <button type="button" class="btn-icon" data-close-settle
                        title="{{ __('lang_v1.close') }}" aria-label="{{ __('lang_v1.close') }}">
                    <x-nav-icon name="x" :size="4"/>
                </button>
            </div>

            <div class="card-body">
                {{-- What is owed, stated before anything is typed. It is also the
                     figure the amount field is seeded with, so the common case —
                     a customer clearing their account — is one click. --}}
                <div class="mb-5 flex items-baseline justify-between gap-3 rounded-lg
                            border border-rose-200 bg-rose-50 px-3 py-2">
                    <span class="text-sm font-semibold text-rose-900">
                        {{ $settleDueType === 'purchase'
                            ? __('lang_v1.purchase_payment_dues')
                            : __('lang_v1.sales_payment_dues') }}
                    </span>
                    <span class="font-mono text-lg font-bold tabular-nums text-rose-800 force-ltr">
                        @format_currency($settleDue)
                    </span>
                </div>

                <div class="field">
                    <label for="settle-amount" class="label label-required">{{ __('lang_v1.amount') }}</label>
                    <input type="number" step="0.01" min="0.01" name="amount" id="settle-amount"
                           class="input-numeric" required autocomplete="off"
                           value="{{ number_format($settleDue, 2, '.', '') }}">
                    {{-- Overpaying is allowed here and is not a mistake:
                         payContactDue() allocates down the open documents and
                         turns whatever is left into advance balance. Saying so
                         up front is what stops a clerk rounding the figure down
                         to avoid an error they would not have got. --}}
                    <p class="hint">{{ __('lang_v1.settle_due_hint') }}</p>
                </div>

                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div class="field">
                        <label for="settle-method" class="label">{{ __('lang_v1.payment_method') }}</label>
                        <select name="method" id="settle-method" class="select" required>
                            @foreach ($methods as $key => $label)
                                <option value="{{ $key }}" @selected($key === 'cash')>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="field">
                        <label for="settle-account" class="label">{{ __('lang_v1.payment_account') }}</label>
                        <select name="account_id" id="settle-account" class="select">
                            @foreach ($accounts as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="field mt-4">
                    <label for="settle-note" class="label">{{ __('lang_v1.notes') }}</label>
                    <input type="text" name="note" id="settle-note" class="input" maxlength="255">
                </div>

                <a href="{{ route('payments.create', ['contact_id' => $contact->id, 'due_type' => $settleDueType]) }}"
                   class="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-emerald-700 hover:underline">
                    <x-nav-icon name="edit" :size="4"/>
                    {{ __('lang_v1.more_payment_options') }}
                </a>
            </div>

            <div class="card-actions">
                <button type="button" class="btn-secondary" data-close-settle>
                    {{ __('lang_v1.cancel') }}
                </button>
                <button type="submit" class="btn-accent">
                    <x-nav-icon name="cash" :size="4"/>
                    {{ __('lang_v1.record_payment') }}
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    'use strict';

    const modal = document.getElementById('settle-modal');
    const amount = document.getElementById('settle-amount');

    const open = function () {
        modal.classList.remove('hidden');
        amount.focus();
        amount.select();
    };

    const close = function () {
        modal.classList.add('hidden');
    };

    document.querySelectorAll('[data-open-settle]').forEach(function (button) {
        button.addEventListener('click', open);
    });

    modal.querySelectorAll('[data-close-settle]').forEach(function (button) {
        button.addEventListener('click', close);
    });

    // The backdrop closes; a click landing on the panel does not close through it.
    modal.addEventListener('click', function (event) {
        if (event.target === modal) close();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.classList.contains('hidden')) close();
    });
})();
</script>
