<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\BusinessLocation;
use App\Models\Contact;
use App\Models\ExpenseCategory;
use App\Models\TaxRate;
use App\Models\Transaction;
use App\Models\TransactionPayment;
use App\Models\User;
use App\Services\ExpenseService;
use App\Services\FormattingService;
use App\Services\PaymentService;
use App\Support\TenantRules;
use App\Support\TransactionTypes;
use Illuminate\Http\Request;

/**
 * Expenses and expense refunds.
 *
 * An expense is a `transactions` row, so this controller looks like
 * {@see PurchaseController} more than it looks like a CRUD screen: the same
 * location scoping, the same derived payment status, the same optional payment
 * on create. What differs is that there are no lines — one amount, one category,
 * one optional tax — and that a row can be a template for future occurrences.
 *
 * Permissions split three ways, and the third is the interesting one:
 * `all_expense.access` sees the business, `view_own_expense` sees only the rows a
 * user created or was named on. That is a filter, not a gate, so it is applied to
 * the query rather than checked at the door.
 */
class ExpenseController extends Controller
{
    public function __construct(
        private ExpenseService $expenses,
        private PaymentService $payments,
        private FormattingService $format,
    ) {}

    /* ================================================================
     | Listing
     ================================================================ */

    public function index(Request $request)
    {
        $this->permit('all_expense.access', 'view_own_expense');

        $query = $this->listQuery($request);

        $records = $query->clone()
            ->with(['expense_category', 'expense_sub_category', 'location', 'contact', 'transaction_for'])
            ->latest('transaction_date')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('expense.index', [
            'records' => $records,
            'totals' => $this->listTotals($query),
            'locations' => BusinessLocation::forDropdown(true),
            'categories' => ['' => __('lang_v1.all')] + ExpenseCategory::forDropdownWithSubs(),
            'users' => ['' => __('lang_v1.all')] + User::forDropdown(),
            'statuses' => ['' => __('lang_v1.all')] + collect(TransactionTypes::paymentStatuses())
                ->map(fn ($key) => __($key))->all(),
        ]);
    }

    /* ================================================================
     | Create / update / delete
     ================================================================ */

    public function create()
    {
        $this->permit('expense.add');

        return view('expense.create', $this->formData());
    }

    public function store(Request $request)
    {
        $this->permit('expense.add');

        $validated = $this->validateExpense($request);

        try {
            $expense = $this->expenses->create(
                $validated + ['created_by' => auth()->id()],
                $this->paymentLinesFrom($request, $validated)
            );

            $output = $this->ok(__('lang_v1.added_successfully'));
        } catch (\Throwable $e) {
            return back()->withInput()->with('status', $this->failed($e));
        }

        return $request->has('save_and_add_another')
            ? redirect()->route('expenses.create')->with('status', $output)
            : $this->backToIndex('expenses.index', $output);
    }

    public function show(int $id)
    {
        $this->permit('all_expense.access', 'view_own_expense');

        $expense = $this->findExpense($id, [
            'expense_category', 'expense_sub_category', 'location', 'contact',
            'transaction_for', 'tax', 'created_user', 'payment_lines.payment_account',
            'payment_lines.created_user',
        ]);

        return view('expense.show', [
            'expense' => $expense,
            'paid' => $this->payments->amountPaid($expense),
            'due' => $this->payments->amountDue($expense),
            'occurrences' => $expense->is_recurring
                ? Transaction::where('recur_parent_id', $expense->id)
                    ->orderBy('transaction_date')
                    ->get(['id', 'ref_no', 'transaction_date', 'final_total', 'payment_status'])
                : collect(),
        ]);
    }

    public function edit(int $id)
    {
        $this->permit('expense.edit');

        $expense = $this->findExpense($id);

        return view('expense.edit', $this->formData($expense));
    }

    public function update(Request $request, int $id)
    {
        $this->permit('expense.edit');

        $expense = $this->findExpense($id);

        $validated = $this->validateExpense($request);

        try {
            $this->expenses->update($expense, $validated);

            $output = $this->ok(__('lang_v1.updated_successfully'));
        } catch (\Throwable $e) {
            return back()->withInput()->with('status', $this->failed($e));
        }

        return $this->backToIndex('expenses.index', $output);
    }

    public function destroy(Request $request, int $id)
    {
        $this->permit('expense.delete');

        try {
            $this->expenses->delete($this->findExpense($id, ['payment_lines']));

            $output = $this->ok(__('lang_v1.deleted_successfully'));
        } catch (\Throwable $e) {
            $output = $this->failed($e);
        }

        return $request->ajax()
            ? response()->json($output)
            : $this->backToIndex('expenses.index', $output);
    }

    /* ================================================================
     | Internals
     ================================================================ */

    protected function listQuery(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        return Transaction::ofType([TransactionTypes::EXPENSE, TransactionTypes::EXPENSE_REFUND])
            ->permittedLocations()
            ->when($this->viewOwnOnly(), fn ($q) => $q->where(
                fn ($inner) => $inner->where('transactions.created_by', auth()->id())
                    ->orWhere('transactions.expense_for', auth()->id())
            ))
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search').'%';
                $query->where(fn ($q) => $q->where('ref_no', 'like', $term)
                    ->orWhere('additional_notes', 'like', $term)
                    ->orWhere('subscription_no', 'like', $term));
            })
            ->when($request->filled('location_id'),
                fn ($q) => $q->forLocation($request->integer('location_id')))
            ->when($request->filled('expense_category_id'), fn ($q) => $q->where(
                fn ($inner) => $inner->where('expense_category_id', $request->integer('expense_category_id'))
                    ->orWhere('expense_sub_category_id', $request->integer('expense_category_id'))
            ))
            ->when($request->filled('expense_for'),
                fn ($q) => $q->where('expense_for', $request->integer('expense_for')))
            ->when($request->filled('payment_status'),
                fn ($q) => $q->where('payment_status', $request->string('payment_status')))
            ->when($request->filled('start_date'),
                fn ($q) => $q->where('transaction_date', '>=',
                    $this->format->ufDate($request->input('start_date')).' 00:00:00'))
            ->when($request->filled('end_date'),
                fn ($q) => $q->where('transaction_date', '<=',
                    $this->format->ufDate($request->input('end_date')).' 23:59:59'));
    }

    /**
     * Totals for the filter header.
     *
     * A refund is money coming back, so it is subtracted from the expense total
     * rather than shown as its own figure: the question a manager asks of this
     * screen is "what did this cost us", and a refund makes that number smaller.
     *
     * @return array<string, float>
     */
    protected function listTotals(\Illuminate\Database\Eloquent\Builder $query): array
    {
        $rows = $query->clone()
            ->selectRaw('transactions.type, SUM(final_total) AS total')
            ->groupBy('transactions.type')
            ->pluck('total', 'type');

        $expense = (float) ($rows[TransactionTypes::EXPENSE] ?? 0);
        $refund = (float) ($rows[TransactionTypes::EXPENSE_REFUND] ?? 0);

        $paid = (float) TransactionPayment::whereIn(
            'transaction_id', $query->clone()->select('transactions.id')
        )->where('is_return', 0)->sum('amount');

        return [
            'total' => round($expense - $refund, 4),
            'refund' => round($refund, 4),
            'paid' => round($paid, 4),
            'due' => round($expense - $refund - $paid, 4),
        ];
    }

    /**
     * True when the user may only see their own expenses.
     *
     * Checked as "cannot see all, but can see own" rather than "can see own",
     * because a user may legitimately hold both permissions and the wider one
     * has to win.
     */
    protected function viewOwnOnly(): bool
    {
        return ! $this->allows('all_expense.access') && $this->allows('view_own_expense');
    }

    protected function findExpense(int $id, array $with = []): Transaction
    {
        $expense = Transaction::with($with)
            ->ofType([TransactionTypes::EXPENSE, TransactionTypes::EXPENSE_REFUND])
            ->permittedLocations()
            ->findOrFail($id);

        if ($this->viewOwnOnly()
            && (int) $expense->created_by !== auth()->id()
            && (int) $expense->expense_for !== auth()->id()) {
            abort(403, __('lang_v1.unauthorized'));
        }

        return $expense;
    }

    /**
     * @return array<string, mixed>
     */
    protected function validateExpense(Request $request): array
    {
        $validated = $request->validate([
            'location_id' => ['required', 'integer', TenantRules::location()],
            'expense_category_id' => 'nullable|integer|exists:expense_categories,id',
            'expense_sub_category_id' => 'nullable|integer|exists:expense_categories,id',
            'expense_for' => 'nullable|integer|exists:users,id',
            'contact_id' => 'nullable|integer|exists:contacts,id',
            'ref_no' => 'nullable|string|max:191',
            'transaction_date' => 'required|date',
            'total_before_tax' => 'required|numeric|min:0',
            'tax_id' => 'nullable|integer|exists:tax_rates,id',
            'additional_notes' => 'nullable|string|max:1000',
            'is_refund' => 'nullable|boolean',
            'is_recurring' => 'nullable|boolean',
            'recur_interval' => 'nullable|integer|min:1|max:365',
            'recur_interval_type' => 'nullable|in:days,months,years',
            'recur_repetitions' => 'nullable|integer|min:1|max:1000',
            'subscription_no' => 'nullable|string|max:191',
        ]);

        // A sub-category that is not a child of the chosen parent is a stale
        // selection left behind by changing the parent, not a new intent.
        if (! empty($validated['expense_sub_category_id'])) {
            $isChild = ExpenseCategory::where('id', $validated['expense_sub_category_id'])
                ->where('parent_id', $validated['expense_category_id'] ?? 0)
                ->exists();

            if (! $isChild) {
                $validated['expense_sub_category_id'] = null;
            }
        }

        return $validated;
    }

    /**
     * The optional payment taken at the same time as the expense.
     *
     * Zero means "not paid yet", not "paid nothing": an unpaid expense is a due
     * one, and writing a zero payment row would make it look settled.
     *
     * @param  array<string, mixed>  $validated
     * @return array<int, array<string, mixed>>
     */
    protected function paymentLinesFrom(Request $request, array $validated): array
    {
        $amount = $this->format->numUf($request->input('payment_amount', 0));

        if ($amount <= 0) {
            return [];
        }

        $payment = $request->validate([
            'payment_method' => 'required_with:payment_amount|string|max:191',
            'payment_account_id' => 'nullable|integer|exists:accounts,id',
            'payment_note' => 'nullable|string|max:255',
            'payment_paid_on' => 'nullable|date',
        ]);

        return [[
            'amount' => $amount,
            'method' => $payment['payment_method'] ?? 'cash',
            'account_id' => $payment['payment_account_id'] ?? null,
            'note' => $payment['payment_note'] ?? null,
            'paid_on' => $this->format->ufDate(
                $payment['payment_paid_on'] ?? $validated['transaction_date'], true
            ),
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formData(?Transaction $expense = null): array
    {
        return [
            'expense' => $expense,
            'locations' => BusinessLocation::forDropdown(),
            'categories' => ['' => __('lang_v1.none')] + ExpenseCategory::forDropdown(),
            'subCategories' => ExpenseCategory::subCategoriesByParent(),
            'users' => ['' => __('lang_v1.none')] + User::forDropdown(),
            'contacts' => ['' => __('lang_v1.none')] + Contact::suppliersForDropdown(),
            'taxes' => ['' => __('lang_v1.none')] + TaxRate::forDropdown(),
            'taxAmounts' => TaxRate::amountsById(),
            'accounts' => Account::forDropdown(),
            'methods' => collect(TransactionTypes::paymentMethods())
                ->except('advance')
                ->map(fn ($key) => __($key))
                ->all(),
            'intervalTypes' => [
                'days' => __('lang_v1.days'),
                'months' => __('lang_v1.months'),
                'years' => __('lang_v1.years'),
            ],
        ];
    }
}
