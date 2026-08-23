<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Transaction;
use App\Models\TransactionPayment;
use App\Services\FormattingService;
use App\Services\PaymentService;
use App\Support\TransactionTypes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Payments against documents, and settlement of a contact's whole balance.
 *
 * Every write goes through {@see PaymentService} inside a `DB::transaction`, and
 * not because a transaction is generally good practice: the service asserts it,
 * because one payment touches the payment row, the document's derived
 * `payment_status`, possibly a contact's advance balance, and — through the
 * account listeners — a mirrored row on a payment account. A half-applied
 * payment is money that exists in one place and not another.
 *
 * Permissions are per document type, not per screen. The catalogue splits them
 * (`sell.payments` / `purchase.payments`, `edit_sell_payment` /
 * `edit_purchase_payment`), so a bookkeeper who may settle supplier bills but not
 * touch customer invoices is a real configuration, and this controller has to
 * honour it on every row rather than at the door.
 */
class TransactionPaymentController extends Controller
{
    public function __construct(
        private PaymentService $payments,
        private FormattingService $format,
    ) {}

    /* ================================================================
     | Listing
     ================================================================ */

    public function index(Request $request)
    {
        $this->permit(
            'sell.payments', 'purchase.payments',
            'all_expense.access', 'view_own_expense'
        );

        $query = $this->listQuery($request);

        $records = $query->clone()
            ->with(['transaction.contact', 'contact', 'payment_account', 'created_user'])
            ->latest('paid_on')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('payment.index', [
            'records' => $records,
            'totals' => $this->listTotals($query),
            'methods' => ['' => __('lang_v1.all_methods')] + $this->methodOptions(),
            'accounts' => ['' => __('lang_v1.all_accounts')] + Account::forDropdown(false),
            'contacts' => ['' => __('lang_v1.all')] + Contact::allForDropdown(),
            /*
             * `payment_type` is which way the money moved, not whether it was
             * reversed: credit is a receipt from a customer, debit is money paid
             * out to a supplier (see PaymentService::paymentDirection()). A
             * reversal is `is_return`, and it keeps its parent's direction — so
             * labelling debit as "payment return" here would have filtered every
             * supplier payment in the business under the wrong name.
             */
            'directions' => [
                '' => __('lang_v1.all'),
                'credit' => __('lang_v1.received'),
                'debit' => __('lang_v1.paid_out'),
            ],
        ]);
    }

    /* ================================================================
     | Adding a payment
     ================================================================ */

    /**
     * The add-payment form, in one of two modes.
     *
     * `?transaction_id=` pays one document — the road in from an invoice screen.
     * `?contact_id=` settles a contact's balance across their open documents.
     * They share a screen because they share every field but the target, and
     * splitting them would duplicate the card/cheque detail panels.
     */
    public function create(Request $request)
    {
        $document = $this->documentFromRequest($request);
        $contact = $this->contactFromRequest($request);

        if ($document) {
            $this->permitFor($document, 'create');
        } else {
            $this->permit('sell.payments', 'purchase.payments');
        }

        return view('payment.create', $this->formData($document, $contact, $request));
    }

    public function store(Request $request)
    {
        $document = $this->documentFromRequest($request);
        $contact = $this->contactFromRequest($request);

        if (empty($document) && empty($contact)) {
            return back()->withInput()->with('status', $this->failed(
                null, __('lang_v1.something_went_wrong')
            ));
        }

        if ($document) {
            $this->permitFor($document, 'create');
        } else {
            $this->permit('sell.payments', 'purchase.payments');
        }

        $validated = $this->validatePayment($request, $document !== null);

        try {
            $output = $document
                ? $this->storeForDocument($document, $validated)
                : $this->storeForContact($contact, $validated);
        } catch (\Throwable $e) {
            return back()->withInput()->with('status', $this->failed($e));
        }

        return redirect()->to($this->returnTo($document, $contact))->with('status', $output);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function storeForDocument(Transaction $document, array $validated): array
    {
        $due = $this->payments->amountDue($document);
        $amount = $this->format->numUf($validated['amount']);

        /*
         * Overpayment is refused rather than rolled into advance balance. On a
         * single document the amount is nearly always a typo — a customer who
         * really is paying ahead is doing it against their balance, which is the
         * other mode of this screen.
         */
        if ($amount > $due + 0.0001 && $validated['method'] !== 'advance') {
            return $this->failed(null, __('lang_v1.payment_exceeds_due'));
        }

        DB::transaction(function () use ($document, $validated, $amount) {
            if ($validated['method'] === 'advance') {
                $contact = $document->contact;

                if (empty($contact)) {
                    throw new \RuntimeException(__('lang_v1.something_went_wrong'));
                }

                $this->payments->useAdvanceBalance($contact, $document, $amount);

                return;
            }

            $this->payments->addPayment($document, $validated + ['created_by' => auth()->id()]);
        });

        return $this->ok(__('lang_v1.payment_recorded'));
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function storeForContact(Contact $contact, array $validated): array
    {
        $result = DB::transaction(fn () => $this->payments->payContactDue(
            $contact,
            $validated + ['created_by' => auth()->id()]
        ));

        $message = __('lang_v1.payment_recorded');

        if (! empty($result['children'])) {
            $message .= ' — '.__('lang_v1.documents_settled', ['count' => count($result['children'])]);
        }

        if ($result['unallocated'] > 0.0001) {
            $message .= ' — '.__('lang_v1.unallocated_to_advance', [
                'amount' => $this->format->currencyF($result['unallocated']),
            ]);
        }

        return $this->ok($message);
    }

    /* ================================================================
     | Reading, editing, deleting one payment
     ================================================================ */

    public function show(int $id)
    {
        $payment = $this->findPayment($id, [
            'transaction.contact', 'transaction.location', 'contact',
            'payment_account', 'created_user', 'child_payments.transaction',
        ]);

        $this->permitFor($payment->transaction, 'view', $payment);

        return view('payment.show', [
            'payment' => $payment,
            'canUpdate' => $this->allowsFor($payment->transaction, 'update', $payment),
            'canDelete' => $this->allowsFor($payment->transaction, 'delete', $payment),
        ]);
    }

    public function edit(int $id)
    {
        $payment = $this->findPayment($id, ['transaction.contact', 'contact']);

        $this->permitFor($payment->transaction, 'update', $payment);

        /*
         * An advance payment is the contact's balance being spent, and a child
         * row is one slice of a settlement its parent decided. Editing either in
         * isolation would leave the balance or the split wrong, so both are sent
         * to the thing that owns them.
         */
        if ($payment->method === 'advance' || ! empty($payment->parent_id)) {
            return redirect()->route('payments.show', $payment->id)->with('status', [
                'success' => 0,
                'msg' => __('lang_v1.payment_line_locked'),
            ]);
        }

        return view('payment.edit', $this->formData(
            $payment->transaction,
            $payment->contact,
            request(),
            $payment
        ));
    }

    public function update(Request $request, int $id)
    {
        $payment = $this->findPayment($id, ['transaction']);

        $this->permitFor($payment->transaction, 'update', $payment);

        if ($payment->method === 'advance' || ! empty($payment->parent_id)) {
            return back()->with('status', $this->failed(null, __('lang_v1.payment_line_locked')));
        }

        $validated = $this->validatePayment($request, $payment->transaction !== null);

        // What is available is the document's due plus what this payment already
        // covers — otherwise raising a payment by a penny would look like an
        // overpayment of the amount it itself contributed.
        if ($payment->transaction) {
            $headroom = $this->payments->amountDue($payment->transaction) + (float) $payment->amount;

            if ($this->format->numUf($validated['amount']) > $headroom + 0.0001) {
                return back()->withInput()->with('status', $this->failed(
                    null, __('lang_v1.payment_exceeds_due')
                ));
            }
        }

        try {
            DB::transaction(fn () => $this->payments->updatePayment($payment, $validated));

            $output = $this->ok(__('lang_v1.updated_successfully'));
        } catch (\Throwable $e) {
            return back()->withInput()->with('status', $this->failed($e));
        }

        return redirect()->route('payments.show', $payment->id)->with('status', $output);
    }

    public function destroy(Request $request, int $id)
    {
        $payment = $this->findPayment($id, ['transaction', 'child_payments']);

        $this->permitFor($payment->transaction, 'delete', $payment);

        try {
            DB::transaction(fn () => $this->payments->deletePayment($payment));

            $output = $this->ok(__('lang_v1.deleted_successfully'));
        } catch (\Throwable $e) {
            $output = $this->failed($e);
        }

        return $request->ajax()
            ? response()->json($output)
            : $this->backToIndex('payments.index', $output);
    }

    /* ================================================================
     | Internals — permissions
     ================================================================ */

    /**
     * The abilities that govern payments on a document type.
     *
     * A payment with no document is a contact settlement; its direction says
     * which side of the ledger it belongs to, so a supplier-only bookkeeper is
     * not handed customer receipts.
     *
     * @return array<int, string>
     */
    protected function abilitiesFor(?Transaction $document, string $action, ?TransactionPayment $payment = null): array
    {
        $type = $document->type ?? null;

        if ($type === null) {
            $isSellSide = ($payment->payment_type ?? 'credit') === 'credit';

            return match ($action) {
                'update' => $isSellSide ? ['edit_sell_payment'] : ['edit_purchase_payment'],
                'delete' => $isSellSide ? ['delete_sell_payment'] : ['delete_purchase_payment'],
                default => $isSellSide ? ['sell.payments'] : ['purchase.payments'],
            };
        }

        return match ($type) {
            TransactionTypes::PURCHASE, TransactionTypes::PURCHASE_RETURN => match ($action) {
                'update' => ['edit_purchase_payment'],
                'delete' => ['delete_purchase_payment'],
                default => ['purchase.payments'],
            },
            TransactionTypes::EXPENSE, TransactionTypes::EXPENSE_REFUND => match ($action) {
                'create' => ['expense.add'],
                'update' => ['expense.edit'],
                'delete' => ['expense.delete'],
                default => ['all_expense.access', 'view_own_expense'],
            },
            default => match ($action) {
                // `edit_pos_payment` exists so a counter sale's payment can be
                // corrected by someone who may not touch back-office invoices.
                'update' => ['edit_sell_payment', 'edit_pos_payment'],
                'delete' => ['delete_sell_payment'],
                default => ['sell.payments'],
            },
        };
    }

    protected function permitFor(?Transaction $document, string $action, ?TransactionPayment $payment = null): void
    {
        $this->permit(...$this->abilitiesFor($document, $action, $payment));
    }

    protected function allowsFor(?Transaction $document, string $action, ?TransactionPayment $payment = null): bool
    {
        return $this->allows(...$this->abilitiesFor($document, $action, $payment));
    }

    /* ================================================================
     | Internals — queries
     ================================================================ */

    /**
     * The listing query.
     *
     * `parent_id` is null-filtered: a contact settlement writes one parent row
     * plus a child per document it covered, and listing both would count the
     * same money twice and read as duplicates.
     */
    protected function listQuery(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        return TransactionPayment::query()
            ->whereNull('parent_id')
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search').'%';
                $query->where(fn ($q) => $q->where('payment_ref_no', 'like', $term)
                    ->orWhere('transaction_no', 'like', $term)
                    ->orWhere('cheque_number', 'like', $term));
            })
            ->when($request->filled('method'),
                fn ($q) => $q->where('method', $request->string('method')))
            ->when($request->filled('account_id'),
                fn ($q) => $q->where('account_id', $request->integer('account_id')))
            ->when($request->filled('contact_id'),
                fn ($q) => $q->where('payment_for', $request->integer('contact_id')))
            ->when($request->filled('direction'),
                fn ($q) => $q->where('payment_type', $request->string('direction')))
            ->when($request->filled('start_date'),
                fn ($q) => $q->where('paid_on', '>=',
                    $this->format->ufDate($request->input('start_date')).' 00:00:00'))
            ->when($request->filled('end_date'),
                fn ($q) => $q->where('paid_on', '<=',
                    $this->format->ufDate($request->input('end_date')).' 23:59:59'));
    }

    /**
     * @return array<string, float>
     */
    protected function listTotals(\Illuminate\Database\Eloquent\Builder $query): array
    {
        $rows = $query->clone()
            ->selectRaw("payment_type, SUM(CASE WHEN is_return = 1 THEN -amount ELSE amount END) AS total")
            ->groupBy('payment_type')
            ->pluck('total', 'payment_type');

        return [
            'in' => round((float) ($rows['credit'] ?? 0), 4),
            'out' => round((float) ($rows['debit'] ?? 0), 4),
        ];
    }

    protected function findPayment(int $id, array $with = []): TransactionPayment
    {
        return TransactionPayment::with($with)->findOrFail($id);
    }

    protected function documentFromRequest(Request $request): ?Transaction
    {
        $id = $request->integer('transaction_id') ?: null;

        if (empty($id)) {
            return null;
        }

        return Transaction::with('contact')->permittedLocations()->findOrFail($id);
    }

    protected function contactFromRequest(Request $request): ?Contact
    {
        $id = $request->integer('contact_id') ?: null;

        return empty($id) ? null : Contact::findOrFail($id);
    }

    /* ================================================================
     | Internals — form
     ================================================================ */

    /**
     * @return array<string, mixed>
     */
    protected function validatePayment(Request $request, bool $hasDocument): array
    {
        $rules = [
            'amount' => 'required|numeric|gt:0',
            'method' => 'required|string|in:'.implode(',', array_keys(TransactionTypes::paymentMethods())),
            'paid_on' => 'required|date',
            'account_id' => 'nullable|integer|exists:accounts,id',
            'note' => 'nullable|string|max:255',
            'card_transaction_number' => 'nullable|string|max:191',
            'card_number' => 'nullable|string|max:191',
            'card_type' => 'nullable|string|max:191',
            'card_holder_name' => 'nullable|string|max:191',
            'card_month' => 'nullable|string|max:2',
            'card_year' => 'nullable|string|max:4',
            'card_security' => 'nullable|string|max:5',
            'cheque_number' => 'nullable|string|max:191',
            'bank_account_number' => 'nullable|string|max:191',
            'transaction_no' => 'nullable|string|max:191',
        ];

        if (! $hasDocument) {
            $rules['due_type'] = 'required|in:sell,purchase';
        }

        $validated = $request->validate($rules);

        $validated['paid_on'] = $this->format->ufDate($validated['paid_on'], true)
            ?? $validated['paid_on'];

        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    protected function formData(
        ?Transaction $document,
        ?Contact $contact,
        Request $request,
        ?TransactionPayment $payment = null
    ): array {
        $contact ??= $document?->contact;

        return [
            'document' => $document,
            'contact' => $contact,
            'payment' => $payment,
            'due' => $document ? $this->payments->amountDue($document) : null,
            'paid' => $document ? $this->payments->amountPaid($document) : null,
            'contactDue' => $contact ? $this->contactDueFor($contact, $request) : null,
            'advanceBalance' => $contact ? (float) $contact->balance : 0.0,
            'accounts' => Account::forDropdown(),
            /*
             * The screen is reachable with no target at all — from the payments
             * list, where nobody has picked anything yet. `store()` refuses a
             * payment with neither a document nor a contact, so the form has to
             * be able to name one; this is that list. Choosing from it reloads
             * the screen with `?contact_id=`, which is what fills in the dues
             * and advance-balance figures below.
             */
            'contacts' => ['' => __('lang_v1.select_contact')] + Contact::allForDropdown(),
            'methods' => $this->methodOptions($document, $contact),
            'dueTypes' => [
                'sell' => __('lang_v1.sales_payment_dues'),
                'purchase' => __('lang_v1.purchase_payment_dues'),
            ],
            'defaultDueType' => $request->string('due_type')->toString()
                ?: ($contact?->type === 'supplier' ? 'purchase' : 'sell'),
            'returnUrl' => $this->returnTo($document, $contact),
        ];
    }

    /**
     * Open-document total for a contact, on the side the form is settling.
     */
    protected function contactDueFor(Contact $contact, Request $request): float
    {
        $dueType = $request->string('due_type')->toString()
            ?: ($contact->type === 'supplier' ? 'purchase' : 'sell');

        $types = $dueType === 'sell'
            ? [TransactionTypes::SELL, TransactionTypes::OPENING_BALANCE]
            : [TransactionTypes::PURCHASE, TransactionTypes::OPENING_BALANCE];

        $documents = Transaction::where('contact_id', $contact->id)
            ->whereIn('type', $types)
            ->whereIn('payment_status', [TransactionTypes::DUE, TransactionTypes::PARTIAL])
            ->get(['id', 'final_total']);

        if ($documents->isEmpty()) {
            return 0.0;
        }

        $paid = (float) TransactionPayment::whereIn('transaction_id', $documents->pluck('id'))
            ->where('is_return', 0)
            ->sum('amount');

        return round((float) $documents->sum('final_total') - $paid, 4);
    }

    /**
     * Payment methods, minus the ones that cannot apply here.
     *
     * `advance` is offered only when there is a document to spend it on and a
     * contact who has a balance to spend — a method that fails validation the
     * moment it is picked does not belong in the list.
     *
     * @return array<string, string>
     */
    protected function methodOptions(?Transaction $document = null, ?Contact $contact = null): array
    {
        $methods = collect(TransactionTypes::paymentMethods())->map(fn ($key) => __($key));

        $advanceIsUsable = $document !== null
            && $contact !== null
            && (float) $contact->balance > 0.0001;

        return $advanceIsUsable
            ? $methods->all()
            : $methods->except('advance')->all();
    }

    /**
     * Where a save should land.
     *
     * Back to the document if the payment came from one — the person clicked
     * "add payment" on an invoice and wants to see the invoice settled, not a
     * list of every payment in the business.
     */
    protected function returnTo(?Transaction $document, ?Contact $contact): string
    {
        if ($document) {
            return match ($document->type) {
                TransactionTypes::PURCHASE => route('purchases.show', $document->id),
                TransactionTypes::EXPENSE, TransactionTypes::EXPENSE_REFUND => route('expenses.index'),
                TransactionTypes::SELL => route('sells.show', $document->id),
                default => route('payments.index'),
            };
        }

        return $contact && \Illuminate\Support\Facades\Route::has('contacts.show')
            ? route('contacts.show', $contact->id)
            : route('payments.index');
    }
}
