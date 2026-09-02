<?php

namespace App\Http\Controllers;

use App\Events\ContactCreatedOrModified;
use App\Models\Account;
use App\Models\Contact;
use App\Models\CustomerGroup;
use App\Models\Transaction;
use App\Models\TransactionPayment;
use App\Services\FormattingService;
use App\Services\PaymentService;
use App\Services\ReferenceService;
use App\Support\TransactionTypes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Customers and suppliers, their ledger and their opening balance.
 */
class ContactController extends Controller
{
    /**
     * Import column order.
     *
     * @var array<int, string>
     */
    private const IMPORT_COLUMNS = [
        'type', 'name', 'supplier_business_name', 'mobile', 'email',
        'tax_number', 'city', 'state', 'country', 'landmark', 'zip_code',
        'credit_limit', 'pay_term_number', 'pay_term_type', 'opening_balance',
    ];

    public function __construct(
        private FormattingService $format,
        private ReferenceService $references,
        private PaymentService $payments,
    ) {}

    public function index(Request $request)
    {
        $this->permitAnyContactView();

        $type = $request->input('type', 'all');

        $contacts = Contact::with('customer_group')
            ->onlyOwnContact()
            ->select('contacts.*')
            ->when($type === 'customer', fn ($q) => $q->onlyCustomers())
            ->when($type === 'supplier', fn ($q) => $q->onlySuppliers())
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search').'%';
                $query->where(fn ($q) => $q->where('name', 'like', $term)
                    ->orWhere('supplier_business_name', 'like', $term)
                    ->orWhere('mobile', 'like', $term)
                    ->orWhere('contact_id', 'like', $term));
            })
            ->when($request->filled('contact_status'),
                fn ($q) => $q->where('contact_status', $request->string('contact_status')))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        // Net due per contact, computed in two aggregate queries rather than
        // per-row (which is what made the original screen slow).
        $dues = $this->netDuesFor($contacts->pluck('id')->all());

        return view('contact.index', [
            'contacts' => $contacts,
            'dues' => $dues,
            'type' => $type,
        ]);
    }

    public function create(Request $request)
    {
        $this->permit('customer.create', 'supplier.create');

        return view('contact.create', $this->formData() + [
            'defaultType' => $request->input('type', 'customer'),
            'suggestedContactId' => $this->references->generate('contact'),
        ]);
    }

    public function store(Request $request)
    {
        $this->permit('customer.create', 'supplier.create');

        $validated = $this->validateContact($request);

        try {
            $contact = DB::transaction(function () use ($validated, $request) {
                $contact = Contact::create($this->contactAttributes($validated, $request));

                $openingBalance = $this->format->numUf($request->input('opening_balance', 0));

                if ($openingBalance != 0.0) {
                    $this->recordOpeningBalance($contact, $openingBalance);
                }

                event(new ContactCreatedOrModified($contact));

                return $contact;
            });

            $output = $this->ok(__('lang_v1.added_successfully'));
        } catch (\Throwable $e) {
            return back()->withInput()->with('status', $this->failed($e));
        }

        return redirect()->route('contacts.show', $contact->id)->with('status', $output);
    }

    public function show(int $id)
    {
        $this->permitAnyContactView();

        $contact = Contact::with('customer_group')->onlyOwnContact()
            ->select('contacts.*')->findOrFail($id);

        $payments = $this->payments;

        /*
         * The settlement dialog's own figure, and not the `net_due` stat beside
         * it. Those two answer different questions and only coincide for a
         * contact who is purely a customer or purely a supplier: `net_due` nets
         * both sides of the relationship, while a settlement is allocated down
         * one side. Seeding the dialog from the netted number would offer a
         * `both` contact an amount that runs off the end of their open sell
         * documents and silently becomes advance balance.
         */
        $dueType = $payments->defaultDueTypeFor($contact);

        return view('contact.show', [
            'contact' => $contact,
            'summary' => $this->summaryFor($contact),
            'recentTransactions' => $this->recentMovementsFor($contact),

            /*
             * The dialog is rendered only when there is something to settle and
             * somebody allowed to settle it, so both halves of that condition are
             * decided here rather than in the view — the permission pair is the
             * same one TransactionPaymentController::store() enforces on the
             * request the dialog posts, and a button that leads to a 403 is worse
             * than no button.
             */
            'settleDue' => $payments->contactDue($contact, $dueType),
            'settleDueType' => $dueType,
            'canSettle' => $this->allows('sell.payments', 'purchase.payments'),
            'accounts' => Account::forDropdown(),
            /*
             * `advance` is excluded: spending a contact's stored credit needs a
             * document to spend it against, and this dialog has none — it is
             * settling documents, not choosing one. PaymentService would reject
             * it, so offering it would only produce a validation error.
             */
            'methods' => collect(TransactionTypes::paymentMethods())
                ->map(fn ($key) => __($key))->except('advance')->all(),
        ]);
    }

    /**
     * The contact's latest movements — documents *and* payments in one list.
     *
     * Documents alone leave the most common question about a prepaying contact
     * unanswerable from this screen: the advance-balance stat says what is left,
     * but nothing says where the rest of it went. A credit sale drawn against
     * stored credit shows up as a `paid` invoice with no payment beside it, which
     * reads as an invoice somebody forgot to collect on.
     *
     * Both halves are normalised into one row shape here rather than in the view,
     * which has one table and should not have to know it is looking at two tables.
     *
     * The running-balance statement is a different screen with a different job:
     * {@see ledgerEntries()} deliberately drops advance spends, because there it
     * would count the same cash twice. Here nothing is being summed, so the spend
     * is exactly what the reader came for.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function recentMovementsFor(Contact $contact, int $limit = 15): array
    {
        $documents = Transaction::where('contact_id', $contact->id)
            ->whereIn('type', [
                TransactionTypes::SELL, TransactionTypes::PURCHASE,
                TransactionTypes::SELL_RETURN, TransactionTypes::PURCHASE_RETURN,
                TransactionTypes::OPENING_BALANCE,
            ])
            ->latest('transaction_date')
            ->limit($limit)
            ->get()
            ->map(fn ($transaction) => [
                'date' => $transaction->transaction_date,
                'reference' => $transaction->invoice_no ?: $transaction->ref_no,
                'label' => __('lang_v1.'.$transaction->type),
                'status' => $transaction->payment_status,
                'method' => null,
                'total' => (float) $transaction->final_total,
            ]);

        $methods = TransactionTypes::paymentMethods();

        $payments = TransactionPayment::where('payment_for', $contact->id)
            // Child rows of a bulk settlement would list the same money twice.
            ->whereNull('parent_id')
            ->latest('paid_on')
            ->limit($limit)
            ->get()
            ->map(fn ($payment) => [
                'date' => $payment->paid_on,
                'reference' => $payment->payment_ref_no,
                'label' => __($payment->is_return ? 'lang_v1.payment_return' : 'lang_v1.payment'),
                'status' => null,
                'method' => __($methods[$payment->method] ?? 'lang_v1.other'),
                'total' => (float) $payment->amount,
            ]);

        /*
         * Each side is capped before the merge and the merged list again after it:
         * fifteen of each is the smallest window guaranteed to contain the fifteen
         * most recent overall, and taking fewer from either side could drop a
         * payment that belongs on screen in favour of an older document.
         */
        return $documents->concat($payments)
            ->sortByDesc('date')
            ->take($limit)
            ->values()
            ->all();
    }

    public function edit(int $id)
    {
        $this->permit('customer.update', 'supplier.update');

        $contact = Contact::findOrFail($id);

        return view('contact.edit', $this->formData() + ['contact' => $contact]);
    }

    public function update(Request $request, int $id)
    {
        $this->permit('customer.update', 'supplier.update');

        $contact = Contact::findOrFail($id);
        $validated = $this->validateContact($request, $contact);

        try {
            DB::transaction(function () use ($contact, $validated, $request) {
                $contact->update($this->contactAttributes($validated, $request, $contact));
                event(new ContactCreatedOrModified($contact));
            });

            $output = $this->ok(__('lang_v1.updated_successfully'));
        } catch (\Throwable $e) {
            return back()->withInput()->with('status', $this->failed($e));
        }

        return $this->backToIndex('contacts.index', $output);
    }

    public function destroy(Request $request, int $id)
    {
        $this->permit('customer.delete', 'supplier.delete');

        try {
            $contact = Contact::findOrFail($id);

            if ($contact->is_default) {
                $output = ['success' => 0, 'msg' => __('lang_v1.cannot_delete_default_customer')];
            } elseif (Transaction::where('contact_id', $contact->id)->exists()) {
                $output = ['success' => 0, 'msg' => __('lang_v1.cannot_delete_in_use', [
                    'name' => __('lang_v1.transactions'),
                ])];
            } else {
                DB::transaction(fn () => $contact->delete());
                $output = $this->ok(__('lang_v1.deleted_successfully'));
            }
        } catch (\Throwable $e) {
            $output = $this->failed($e);
        }

        return $request->ajax()
            ? response()->json($output)
            : $this->backToIndex('contacts.index', $output);
    }

    /**
     * Toggle active / inactive without deleting history.
     */
    public function updateStatus(int $id)
    {
        $this->permit('customer.update', 'supplier.update');

        $contact = Contact::findOrFail($id);
        $contact->contact_status = $contact->contact_status === 'active' ? 'inactive' : 'active';
        $contact->save();

        return $this->backToIndex('contacts.index', $this->ok(__('lang_v1.updated_successfully')));
    }

    /* ================================================================
     | Ledger
     ================================================================ */

    /**
     * Running-balance statement for one contact.
     */
    public function ledger(Request $request, int $id)
    {
        $this->permitAnyContactView();

        $contact = Contact::findOrFail($id);

        $start = $this->format->ufDate($request->input('start_date'))
            ?? now()->startOfYear()->toDateString();
        $end = $this->format->ufDate($request->input('end_date'))
            ?? now()->toDateString();

        return view('contact.ledger', [
            'contact' => $contact,
            'start' => $start,
            'end' => $end,
            'openingBalance' => $this->ledgerOpeningBalance($contact, $start),
            'entries' => $this->ledgerEntries($contact, $start, $end),
        ]);
    }

    /**
     * Balance carried into the statement window.
     */
    protected function ledgerOpeningBalance(Contact $contact, string $start): float
    {
        $invoiced = (float) Transaction::where('contact_id', $contact->id)
            ->whereIn('type', $this->ledgerDebitTypes())
            ->where('transaction_date', '<', $start)
            ->sum('final_total');

        $credited = (float) Transaction::where('contact_id', $contact->id)
            ->whereIn('type', $this->ledgerCreditTypes())
            ->where('transaction_date', '<', $start)
            ->sum('final_total');

        $paid = (float) TransactionPayment::where('payment_for', $contact->id)
            ->where('is_return', 0)
            ->whereDate('paid_on', '<', $start)
            ->sum('amount');

        return round($invoiced - $credited - $paid, 4);
    }

    /**
     * Chronological debit/credit rows with a running balance.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function ledgerEntries(Contact $contact, string $start, string $end): array
    {
        $documents = Transaction::where('contact_id', $contact->id)
            ->whereIn('type', array_merge($this->ledgerDebitTypes(), $this->ledgerCreditTypes()))
            ->whereBetween('transaction_date', [$start.' 00:00:00', $end.' 23:59:59'])
            ->get()
            ->map(fn ($transaction) => [
                'date' => $transaction->transaction_date,
                'reference' => $transaction->invoice_no ?: $transaction->ref_no,
                'type' => $transaction->type,
                'debit' => in_array($transaction->type, $this->ledgerDebitTypes(), true)
                    ? (float) $transaction->final_total : 0.0,
                'credit' => in_array($transaction->type, $this->ledgerCreditTypes(), true)
                    ? (float) $transaction->final_total : 0.0,
            ]);

        $payments = TransactionPayment::where('payment_for', $contact->id)
            ->whereBetween('paid_on', [$start.' 00:00:00', $end.' 23:59:59'])
            // Child rows of a bulk settlement would double-count the parent.
            ->whereNull('parent_id')
            /*
             * Spending advance balance moves no money — the cash was credited here
             * already, when the payment that created the balance was recorded. So a
             * statement that credited the spend as well would report the same note
             * twice and drift from the advance-balance stat by exactly the amount
             * spent: top up 500, take 300 of goods on credit, and the running
             * balance would close at -500 beside a stat saying 200 is left.
             *
             * Every other mirror of a payment already draws this line —
             * AddAccountTransaction, UpdateAccountTransaction and
             * CashRegisterService::isDrawerMovement() all skip the method for the
             * same reason.
             */
            ->where('method', '!=', 'advance')
            ->get()
            ->map(fn ($payment) => [
                'date' => $payment->paid_on,
                'reference' => $payment->payment_ref_no,
                'type' => $payment->is_return ? 'payment_return' : 'payment',
                'debit' => $payment->is_return ? (float) $payment->amount : 0.0,
                'credit' => $payment->is_return ? 0.0 : (float) $payment->amount,
            ]);

        $balance = $this->ledgerOpeningBalance($contact, $start);

        return $documents->concat($payments)
            ->sortBy('date')
            ->values()
            ->map(function ($entry) use (&$balance) {
                $balance = round($balance + $entry['debit'] - $entry['credit'], 4);
                $entry['balance'] = $balance;

                return $entry;
            })
            ->all();
    }

    /** Documents that increase what the contact owes us. */
    protected function ledgerDebitTypes(): array
    {
        return [TransactionTypes::SELL, TransactionTypes::OPENING_BALANCE];
    }

    /** Documents that decrease it. */
    protected function ledgerCreditTypes(): array
    {
        return [TransactionTypes::SELL_RETURN, TransactionTypes::LEDGER_DISCOUNT];
    }

    /* ================================================================
     | Opening balance
     ================================================================ */

    public function editOpeningBalance(int $id)
    {
        $this->permit('customer.update', 'supplier.update');

        $contact = Contact::findOrFail($id);

        $opening = Transaction::where('contact_id', $contact->id)
            ->where('type', TransactionTypes::OPENING_BALANCE)
            ->first();

        return view('contact.opening-balance', [
            'contact' => $contact,
            'amount' => (float) ($opening->final_total ?? 0),
        ]);
    }

    public function updateOpeningBalance(Request $request, int $id)
    {
        $this->permit('customer.update', 'supplier.update');

        $contact = Contact::findOrFail($id);
        $request->validate(['opening_balance' => 'required|numeric|min:0']);

        try {
            DB::transaction(function () use ($contact, $request) {
                $this->recordOpeningBalance(
                    $contact,
                    $this->format->numUf($request->input('opening_balance'))
                );
            });

            $output = $this->ok(__('lang_v1.updated_successfully'));
        } catch (\Throwable $e) {
            $output = $this->failed($e);
        }

        return redirect()->route('contacts.show', $contact->id)->with('status', $output);
    }

    /**
     * Create or adjust the opening-balance document.
     *
     * Refuses to reduce it below what has already been settled — that would
     * leave the payment attached to a smaller invoice than itself.
     */
    protected function recordOpeningBalance(Contact $contact, float $amount): void
    {
        $existing = Transaction::where('contact_id', $contact->id)
            ->where('type', TransactionTypes::OPENING_BALANCE)
            ->first();

        if (empty($existing)) {
            if ($amount <= 0) {
                return;
            }

            Transaction::create([
                'business_id' => $contact->business_id,
                'location_id' => \App\Models\BusinessLocation::query()->value('id'),
                'type' => TransactionTypes::OPENING_BALANCE,
                'status' => TransactionTypes::STATUS_FINAL,
                'payment_status' => TransactionTypes::DUE,
                'contact_id' => $contact->id,
                'transaction_date' => now(),
                'final_total' => $amount,
                'created_by' => auth()->id(),
            ]);

            return;
        }

        $paid = (float) $existing->payment_lines()->where('is_return', 0)->sum('amount');

        if ($amount < $paid) {
            throw new \RuntimeException(__('lang_v1.opening_balance_below_paid', [
                'paid' => $this->format->currencyF($paid),
            ]));
        }

        $existing->final_total = $amount;
        $existing->save();

        $this->payments->refreshPaymentStatus($existing);
    }

    /* ================================================================
     | Import / export
     ================================================================ */

    public function importForm()
    {
        $this->permit('customer.create', 'supplier.create');

        return view('import.contacts', ['columns' => static::IMPORT_COLUMNS]);
    }

    public function importTemplate()
    {
        $this->permit('customer.create', 'supplier.create');

        return Excel::download(
            new \App\Exports\ArrayExport([static::IMPORT_COLUMNS]),
            'contact-import-template.xlsx'
        );
    }

    public function import(Request $request)
    {
        $this->permit('customer.create', 'supplier.create');

        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv,txt|max:10240']);

        try {
            $rows = Excel::toArray(new \App\Imports\RawImport, $request->file('file'))[0] ?? [];
        } catch (\Throwable $e) {
            return back()->with('status', $this->failed($e, __('lang_v1.import_unreadable_file')));
        }

        array_shift($rows);
        $rows = array_values(array_filter($rows, fn ($row) => ! empty(array_filter($row))));

        if (empty($rows)) {
            return back()->with('status', ['success' => 0, 'msg' => __('lang_v1.import_file_empty')]);
        }

        $errors = [];
        $parsed = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $data = array_combine(
                static::IMPORT_COLUMNS,
                array_pad(array_slice($row, 0, count(static::IMPORT_COLUMNS)),
                    count(static::IMPORT_COLUMNS), null)
            );

            $type = strtolower(trim((string) $data['type']));

            if (! in_array($type, ['customer', 'supplier', 'both'], true)) {
                $errors[] = __('lang_v1.import_row_unknown', [
                    'row' => $line, 'field' => __('lang_v1.type'), 'value' => $data['type'],
                ]);

                continue;
            }

            if (empty(trim((string) $data['name']))) {
                $errors[] = __('lang_v1.import_row_missing', [
                    'row' => $line, 'field' => __('lang_v1.name'),
                ]);

                continue;
            }

            $parsed[] = ['data' => $data, 'type' => $type];
        }

        if (! empty($errors)) {
            return back()
                ->with('status', ['success' => 0, 'msg' => __('lang_v1.import_failed_row_errors', [
                    'count' => count($errors),
                ])])
                ->with('import_errors', $errors);
        }

        try {
            $imported = DB::transaction(function () use ($parsed) {
                $count = 0;

                foreach ($parsed as $row) {
                    $contact = Contact::create([
                        'type' => $row['type'],
                        'name' => trim((string) $row['data']['name']),
                        'first_name' => trim((string) $row['data']['name']),
                        'supplier_business_name' => $row['data']['supplier_business_name'] ?: null,
                        'mobile' => $row['data']['mobile'] ?: null,
                        'email' => $row['data']['email'] ?: null,
                        'tax_number' => $row['data']['tax_number'] ?: null,
                        'city' => $row['data']['city'] ?: null,
                        'state' => $row['data']['state'] ?: null,
                        'country' => $row['data']['country'] ?: null,
                        'landmark' => $row['data']['landmark'] ?: null,
                        'zip_code' => $row['data']['zip_code'] ?: null,
                        'credit_limit' => $this->format->numUf($row['data']['credit_limit'] ?? 0) ?: null,
                        'pay_term_number' => $row['data']['pay_term_number'] ?: null,
                        'pay_term_type' => in_array($row['data']['pay_term_type'], ['days', 'months'], true)
                            ? $row['data']['pay_term_type'] : null,
                        'contact_status' => 'active',
                        'contact_id' => $this->references->generate('contact'),
                        'created_by' => auth()->id(),
                    ]);

                    $opening = $this->format->numUf($row['data']['opening_balance'] ?? 0);

                    if ($opening > 0) {
                        $this->recordOpeningBalance($contact, $opening);
                    }

                    $count++;
                }

                return $count;
            });
        } catch (\Throwable $e) {
            return back()->with('status', $this->failed($e));
        }

        return redirect()->route('contacts.index')->with('status', $this->ok(
            __('lang_v1.import_succeeded_contacts', ['count' => $imported])
        ));
    }

    /* ================================================================
     | AJAX
     ================================================================ */

    /**
     * Contact search for the POS / purchase screens.
     */
    public function search(Request $request)
    {
        $type = $request->input('type', 'customer');
        $term = '%'.$request->input('term', '').'%';

        $contacts = Contact::active()
            ->onlyOwnContact()
            ->select('contacts.*')
            ->when($type === 'customer', fn ($q) => $q->onlyCustomers())
            ->when($type === 'supplier', fn ($q) => $q->onlySuppliers())
            ->where(fn ($q) => $q->where('name', 'like', $term)
                ->orWhere('supplier_business_name', 'like', $term)
                ->orWhere('mobile', 'like', $term))
            ->limit(25)
            ->get();

        return response()->json($contacts->map(fn ($contact) => [
            'id' => $contact->id,
            'text' => $contact->full_name_with_business,
            'mobile' => $contact->mobile,
            'balance' => (float) $contact->balance,
            'credit_limit' => $contact->credit_limit ? (float) $contact->credit_limit : null,
            'pay_term_number' => $contact->pay_term_number,
            'pay_term_type' => $contact->pay_term_type,
        ]));
    }

    /**
     * Net amount a contact owes (or is owed), for the POS header.
     */
    public function due(int $id)
    {
        $this->permitAnyContactView();

        $contact = Contact::findOrFail($id);

        return response()->json([
            'due' => $this->netDuesFor([$contact->id])[$contact->id] ?? 0,
            'advance_balance' => (float) $contact->balance,
        ]);
    }

    /* ================================================================
     | Internals
     ================================================================ */

    protected function permitAnyContactView(): void
    {
        $this->permit(
            'customer.view', 'supplier.view',
            'customer.view_own', 'supplier.view_own'
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function validateContact(Request $request, ?Contact $contact = null): array
    {
        return $request->validate([
            'type' => 'required|in:customer,supplier,both',
            'name' => 'required|string|max:255',
            'prefix' => 'nullable|string|max:255',
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'supplier_business_name' => 'nullable|string|max:255',
            'contact_id' => [
                'nullable', 'string', 'max:255',
                Rule::unique('contacts', 'contact_id')
                    ->where('business_id', \App\Support\Tenancy::id())
                    ->whereNull('deleted_at')
                    ->ignore($contact?->id),
            ],
            'mobile' => 'nullable|string|max:255',
            'landline' => 'nullable|string|max:255',
            'alternate_number' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'tax_number' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'landmark' => 'nullable|string|max:255',
            'zip_code' => 'nullable|string|max:255',
            'dob' => 'nullable|date',
            'customer_group_id' => 'nullable|integer|exists:customer_groups,id',
            'credit_limit' => 'nullable|numeric|min:0',
            'pay_term_number' => 'nullable|integer|min:0',
            'pay_term_type' => 'nullable|in:days,months',
            'shipping_address' => 'nullable|string|max:1000',
            'position' => 'nullable|string|max:255',
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function contactAttributes(array $validated, Request $request, ?Contact $contact = null): array
    {
        $attributes = $validated;

        $attributes['credit_limit'] = $this->format->numUf($validated['credit_limit'] ?? 0) ?: null;

        if (empty($attributes['first_name'])) {
            $attributes['first_name'] = $attributes['name'];
        }

        if (empty($contact)) {
            $attributes['created_by'] = auth()->id();
            $attributes['contact_status'] = 'active';
            $attributes['contact_id'] = $attributes['contact_id']
                ?: $this->references->generate('contact');
        }

        return $attributes;
    }

    /**
     * Net receivable per contact: invoiced − credited − paid.
     *
     * @param  array<int, int>  $contactIds
     * @return array<int, float>
     */
    protected function netDuesFor(array $contactIds): array
    {
        if (empty($contactIds)) {
            return [];
        }

        $invoiced = Transaction::whereIn('contact_id', $contactIds)
            ->whereIn('type', [
                TransactionTypes::SELL, TransactionTypes::PURCHASE,
                TransactionTypes::OPENING_BALANCE,
            ])
            ->whereIn('status', [TransactionTypes::STATUS_FINAL, TransactionTypes::STATUS_RECEIVED])
            ->selectRaw('contact_id, SUM(final_total) AS total')
            ->groupBy('contact_id')
            ->pluck('total', 'contact_id');

        $returned = Transaction::whereIn('contact_id', $contactIds)
            ->whereIn('type', [TransactionTypes::SELL_RETURN, TransactionTypes::PURCHASE_RETURN])
            ->selectRaw('contact_id, SUM(final_total) AS total')
            ->groupBy('contact_id')
            ->pluck('total', 'contact_id');

        $paid = TransactionPayment::whereIn('payment_for', $contactIds)
            ->whereNull('parent_id')
            ->selectRaw('payment_for, SUM(CASE WHEN is_return = 1 THEN -amount ELSE amount END) AS total')
            ->groupBy('payment_for')
            ->pluck('total', 'payment_for');

        $dues = [];

        foreach ($contactIds as $id) {
            $dues[$id] = round(
                (float) ($invoiced[$id] ?? 0)
                - (float) ($returned[$id] ?? 0)
                - (float) ($paid[$id] ?? 0),
                4
            );
        }

        return $dues;
    }

    /**
     * @return array<string, float|int>
     */
    protected function summaryFor(Contact $contact): array
    {
        $sales = Transaction::where('contact_id', $contact->id)
            ->where('type', TransactionTypes::SELL)
            ->where('status', TransactionTypes::STATUS_FINAL)
            ->selectRaw('COUNT(*) AS count, COALESCE(SUM(final_total), 0) AS total')
            ->first();

        $purchases = Transaction::where('contact_id', $contact->id)
            ->where('type', TransactionTypes::PURCHASE)
            ->selectRaw('COUNT(*) AS count, COALESCE(SUM(final_total), 0) AS total')
            ->first();

        return [
            'sales_count' => (int) $sales->count,
            'sales_total' => (float) $sales->total,
            'purchases_count' => (int) $purchases->count,
            'purchases_total' => (float) $purchases->total,
            'net_due' => $this->netDuesFor([$contact->id])[$contact->id] ?? 0,
            'advance_balance' => (float) $contact->balance,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formData(): array
    {
        return [
            'customerGroups' => CustomerGroup::forDropdown(),
            'types' => [
                'customer' => __('lang_v1.customer'),
                'supplier' => __('lang_v1.supplier'),
                'both' => __('lang_v1.both'),
            ],
        ];
    }
}
