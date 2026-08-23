<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\AccountType;
use App\Services\AccountService;
use App\Services\FormattingService;
use Illuminate\Http\Request;

/**
 * Payment accounts — the tills, banks and wallets money passes through.
 *
 * Gated by the `account` module as well as by permission. The module is not core:
 * a shop that only ever takes cash has no use for account balances, and switching
 * the module off has to actually close the screen rather than merely hide the
 * sidebar link.
 *
 * The important rule here is one this controller mostly enforces by *not* offering
 * things: rows mirrored from a transaction payment are read-only. They are the
 * shadow of an invoice's payment, and the way to change one is to change the
 * payment. {@see AccountService::deleteTransaction()} refuses them; the views hide
 * the buttons; both are needed, because a hidden button is not a rule.
 */
class AccountController extends Controller
{
    public function __construct(
        private AccountService $accounts,
        private FormattingService $format,
    ) {}

    /* ================================================================
     | Accounts
     ================================================================ */

    public function index(Request $request)
    {
        $this->guard();

        $accounts = Account::with('account_type')
            ->when(! $request->boolean('show_closed'), fn ($q) => $q->notClosed())
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search').'%';
                $query->where(fn ($q) => $q->where('name', 'like', $term)
                    ->orWhere('account_number', 'like', $term));
            })
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        $balances = $this->accounts->balancesFor(
            collect($accounts->items())->pluck('id')->all()
        );

        return view('account.index', [
            'accounts' => $accounts,
            'balances' => $balances,
            'canSeeBalance' => $this->allows('view_account_balance'),
            // Only what is on this page, so the figure agrees with the column
            // above it instead of quietly counting rows the reader cannot see.
            'pageTotal' => round(array_sum($balances), 4),
        ]);
    }

    public function create()
    {
        $this->guard();

        return view('account.create', $this->formData());
    }

    public function store(Request $request)
    {
        $this->guard();

        $validated = $this->validateAccount($request);

        try {
            $account = $this->accounts->create($validated + ['created_by' => auth()->id()]);

            $output = $this->ok(__('lang_v1.added_successfully'));
        } catch (\Throwable $e) {
            return back()->withInput()->with('status', $this->failed($e));
        }

        return redirect()->route('accounts.show', $account->id)->with('status', $output);
    }

    public function show(Request $request, int $id)
    {
        $this->guard();

        $account = Account::with('account_type')->findOrFail($id);

        $entries = $this->entriesQuery($account, $request)
            ->with(['transaction.contact', 'transaction_payment', 'created_user', 'transfer_transaction.account'])
            ->latest('operation_date')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('account.show', [
            'account' => $account,
            'entries' => $entries,
            'totals' => $this->accounts->totalsFor($account),
            'canSeeBalance' => $this->allows('view_account_balance'),
            'canEditEntry' => $this->allows('edit_account_transaction'),
            'canDeleteEntry' => $this->allows('delete_account_transaction'),
            'transferTargets' => Account::notClosed()
                ->where('id', '!=', $account->id)
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all(),
            'subTypes' => [
                '' => __('lang_v1.all'),
                'opening_balance' => __('lang_v1.opening_balance'),
                'deposit' => __('lang_v1.deposit'),
                'fund_transfer' => __('lang_v1.fund_transfer'),
            ],
        ]);
    }

    public function edit(int $id)
    {
        $this->guard();

        return view('account.edit', $this->formData(Account::findOrFail($id)));
    }

    public function update(Request $request, int $id)
    {
        $this->guard();

        $account = Account::findOrFail($id);

        $validated = $this->validateAccount($request, $account);

        try {
            $this->accounts->update($account, $validated);

            $output = $this->ok(__('lang_v1.updated_successfully'));
        } catch (\Throwable $e) {
            return back()->withInput()->with('status', $this->failed($e));
        }

        return redirect()->route('accounts.show', $account->id)->with('status', $output);
    }

    /**
     * Close an account, or reopen it.
     *
     * There is no delete. An account that held money is part of the history of
     * every payment made through it, and the screen offers the honest operation
     * instead of a destructive one dressed up as tidying.
     */
    public function setClosed(Request $request, int $id)
    {
        $this->guard();

        $account = Account::findOrFail($id);

        try {
            $this->accounts->setClosed($account, $request->boolean('closed', true));

            $output = $this->ok(__('lang_v1.updated_successfully'));
        } catch (\Throwable $e) {
            $output = $this->failed($e);
        }

        return redirect()->route('accounts.show', $account->id)->with('status', $output);
    }

    /* ================================================================
     | Movements
     ================================================================ */

    public function deposit(Request $request, int $id)
    {
        return $this->movement($request, $id, 'deposit');
    }

    public function withdraw(Request $request, int $id)
    {
        return $this->movement($request, $id, 'withdraw');
    }

    public function transfer(Request $request, int $id)
    {
        $this->guard();

        $from = Account::findOrFail($id);

        $validated = $request->validate([
            // Same-account is refused by the service, which owns the rule and can
            // say why. A `different:` rule here would silently pass anyway, since
            // the source account is a route segment rather than a posted field.
            'to_account_id' => 'required|integer|exists:accounts,id',
            'amount' => 'required|numeric|gt:0',
            'operation_date' => 'required|date',
            'note' => 'nullable|string|max:255',
            // Accepted, though the service will generate one when it is blank.
            // The screen offers deposit, withdrawal and transfer as one set of
            // fields, so a reference typed there has to mean the same thing
            // whichever button is pressed — silently dropping it for one of the
            // three is the kind of detail that erodes trust in the whole form.
            'reff_no' => 'nullable|string|max:191',
        ]);

        try {
            $to = Account::findOrFail($validated['to_account_id']);

            $this->accounts->transfer($from, $to, $validated + ['created_by' => auth()->id()]);

            $output = $this->ok(__('lang_v1.transfer_recorded'));
        } catch (\Throwable $e) {
            return back()->withInput()->with('status', $this->failed($e));
        }

        return redirect()->route('accounts.show', $from->id)->with('status', $output);
    }

    public function destroyTransaction(Request $request, int $id, int $entryId)
    {
        $this->guard('delete_account_transaction');

        $account = Account::findOrFail($id);

        $entry = AccountTransaction::where('account_id', $account->id)->findOrFail($entryId);

        try {
            $this->accounts->deleteTransaction($entry);

            $output = $this->ok(__('lang_v1.deleted_successfully'));
        } catch (\Throwable $e) {
            $output = $this->failed($e);
        }

        return $request->ajax()
            ? response()->json($output)
            : redirect()->route('accounts.show', $account->id)->with('status', $output);
    }

    public function updateTransaction(Request $request, int $id, int $entryId)
    {
        $this->guard('edit_account_transaction');

        $account = Account::findOrFail($id);

        $entry = AccountTransaction::where('account_id', $account->id)->findOrFail($entryId);

        $validated = $request->validate([
            'amount' => 'required|numeric|gt:0',
            'operation_date' => 'required|date',
            'note' => 'nullable|string|max:255',
            'reff_no' => 'nullable|string|max:191',
        ]);

        try {
            $this->accounts->updateTransaction($entry, $validated);

            $output = $this->ok(__('lang_v1.updated_successfully'));
        } catch (\Throwable $e) {
            return back()->withInput()->with('status', $this->failed($e));
        }

        return redirect()->route('accounts.show', $account->id)->with('status', $output);
    }

    /* ================================================================
     | Internals
     ================================================================ */

    /**
     * Module first, then permission.
     *
     * In that order deliberately: a business without the module gets "not
     * enabled", not "forbidden", because the second reads as a mistake by the
     * person clicking rather than a setting they can change.
     */
    protected function guard(string ...$extra): void
    {
        $this->requireModule('account');
        $this->permit('account.access');

        if (! empty($extra)) {
            $this->permit(...$extra);
        }
    }

    protected function movement(Request $request, int $id, string $kind)
    {
        $this->guard();

        $account = Account::findOrFail($id);

        if ($account->is_closed) {
            return back()->with('status', $this->failed(null, __('lang_v1.account_is_closed')));
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|gt:0',
            'operation_date' => 'required|date',
            'note' => 'nullable|string|max:255',
            'reff_no' => 'nullable|string|max:191',
        ]);

        try {
            $validated += ['created_by' => auth()->id()];

            $kind === 'deposit'
                ? $this->accounts->deposit($account, $validated)
                : $this->accounts->withdraw($account, $validated);

            $output = $this->ok(__('lang_v1.added_successfully'));
        } catch (\Throwable $e) {
            return back()->withInput()->with('status', $this->failed($e));
        }

        return redirect()->route('accounts.show', $account->id)->with('status', $output);
    }

    protected function entriesQuery(Account $account, Request $request): \Illuminate\Database\Eloquent\Builder
    {
        return AccountTransaction::where('account_id', $account->id)
            ->when($request->filled('sub_type'),
                fn ($q) => $q->where('sub_type', $request->string('sub_type')))
            ->when($request->filled('type'),
                fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('start_date'),
                fn ($q) => $q->where('operation_date', '>=',
                    $this->format->ufDate($request->input('start_date')).' 00:00:00'))
            ->when($request->filled('end_date'),
                fn ($q) => $q->where('operation_date', '<=',
                    $this->format->ufDate($request->input('end_date')).' 23:59:59'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function validateAccount(Request $request, ?Account $account = null): array
    {
        $rules = [
            'name' => 'required|string|max:191',
            'account_number' => 'nullable|string|max:191',
            'account_type_id' => 'nullable|integer|exists:account_types,id',
            'account_type' => 'nullable|in:saving_current,capital',
            'note' => 'nullable|string|max:1000',
        ];

        // The opening balance is written once, as a movement. Changing it later
        // means adding a correcting movement, not editing history.
        if ($account === null) {
            $rules['opening_balance'] = 'nullable|numeric';
            $rules['opening_balance_date'] = 'nullable|date';
        }

        return $request->validate($rules);
    }

    /**
     * @return array<string, mixed>
     */
    protected function formData(?Account $account = null): array
    {
        return [
            'account' => $account,
            'accountTypes' => ['' => __('lang_v1.none')] + AccountType::forDropdown(),
            'kinds' => [
                'saving_current' => __('lang_v1.saving_current'),
                'capital' => __('lang_v1.capital'),
            ],
        ];
    }
}
