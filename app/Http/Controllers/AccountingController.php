<?php

namespace App\Http\Controllers;

use App\Models\BusinessLocation;
use App\Models\User;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\CostCenter;
use App\Modules\Accounting\Models\Transfer;
use App\Services\AccountingService;
use App\Services\ReportService;
use App\Support\Tenancy;
use App\Support\TenantRules;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The accounting module: chart of accounts, journal, transfers, cost centres and
 * the trial balance.
 *
 * Thin, like every controller here — the arithmetic and every refusal live in
 * {@see AccountingService}, because the invariant this module exists to hold
 * (debits equal credits) has to hold for a scheduled command and a test as much as
 * for a form.
 *
 * Three shape decisions worth stating:
 *
 * **The journal lists documents, not lines.** `journal_entries` has no header
 * table — a document is the set of rows sharing a `transaction_number` — so a
 * naive index would show a two-line document as two rows and a total of twice its
 * value. The listing therefore groups by `transaction_number`, and the drill-down
 * at {@see showJournal()} is where the individual lines live.
 *
 * **There is no journal `edit`.** A posted document is corrected by reversing it
 * and posting a new one, which is what {@see reverse()} does. This is not a
 * missing feature: an editable ledger cannot be audited, and every accounting
 * package worth the name refuses it for the same reason.
 *
 * **Transfers post a real journal document** rather than living only in the
 * `transfers` table. The table names them so the transfer screen can list them;
 * the ledger is where they take effect. Recorded in NOTES §18.
 */
class AccountingController extends Controller
{
    public function __construct(
        protected AccountingService $accounting,
        protected ReportService $reports,
    ) {}

    /* ================================================================
     | Dashboard
     ================================================================ */

    public function dashboard(Request $request)
    {
        $this->gate('accounting.view', 'accounting.journal_entries.create');

        $range = $this->reports->dateRange($request);

        return view('accounting.dashboard', [
            'range' => $range,
            'totals' => $this->accounting->dashboard($range),
            'recent' => $this->documentList($request)->limit(10)->get(),
            'canPost' => $this->allows('accounting.journal_entries.create'),
        ]);
    }

    /* ================================================================
     | Chart of accounts
     ================================================================ */

    public function accounts(Request $request)
    {
        $this->gate('accounting.view', 'accounting.chart_of_accounts.create');

        $records = ChartOfAccount::forBusiness()
            ->with('parent')
            ->when($request->filled('account_type'),
                fn ($q) => $q->where('account_type', $request->string('account_type')))
            ->when($request->filled('search'), fn ($q) => $q->where(
                fn ($inner) => $inner->where('name', 'like', '%'.$request->string('search').'%')
                    ->orWhere('gl_code', 'like', '%'.$request->string('search').'%')
            ))
            ->when($request->input('state') === 'inactive', fn ($q) => $q->where('active', 0))
            ->when($request->input('state') === 'active', fn ($q) => $q->where('active', 1))
            ->orderBy('account_type')
            ->orderBy('gl_code')
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        /*
         * Balances are computed per row rather than in the query, and the
         * pagination above is what makes that acceptable: fifty accounts is fifty
         * pairs of sums, not a chart-wide N+1. The alternative — one grouped query
         * joined back onto the page — was written and thrown away, because it
         * duplicated the sign rule that {@see ChartOfAccount::getCurrentBalanceAttribute()}
         * already owns, and two copies of a sign rule is how a screen comes to
         * disagree with its own totals.
         */
        return view('accounting.accounts.index', [
            'records' => $records,
            'totals' => $this->accountTotals(),
            'types' => ['' => __('lang_v1.all')] + ChartOfAccount::accountTypes(),
            'states' => $this->stateOptions(true),
        ]);
    }

    public function createAccount()
    {
        $this->permit('accounting.chart_of_accounts.create');

        return view('accounting.accounts.create', $this->accountFormData());
    }

    public function storeAccount(Request $request)
    {
        $this->permit('accounting.chart_of_accounts.create');

        $validated = $request->validate($this->accountRules());

        try {
            $account = $this->accounting->createAccount($validated + [
                'allow_manual' => $request->boolean('allow_manual'),
                'active' => $request->boolean('active'),
            ]);
            $output = $this->ok(__('lang_v1.added_successfully'));
        } catch (\Throwable $e) {
            return back()->withInput()->with('status', $this->failed($e));
        }

        return redirect()->route('accounting.accounts.show', $account->id)->with('status', $output);
    }

    /**
     * One account and its ledger.
     *
     * The point of the screen: an account is only meaningful as the movements that
     * made its balance, so the entries are the body and the balance is the header.
     */
    public function showAccount(Request $request, int $id)
    {
        $this->gate('accounting.view', 'accounting.chart_of_accounts.create');

        $account = $this->findAccount($id, ['parent', 'currency']);
        $range = $this->reports->dateRange($request);

        return view('accounting.accounts.show', [
            'record' => $account,
            'range' => $range,
            'entries' => $this->accounting->journalQuery()
                ->where('chart_of_account_id', $account->id)
                ->betweenDates($range['start'], $range['end'])
                ->with(['cost_center', 'business_location'])
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->paginate(50)
                ->withQueryString(),
            'children' => $account->children()->orderBy('gl_code')->get(),
            'canEdit' => $this->allows('accounting.chart_of_accounts.create'),
        ]);
    }

    public function editAccount(int $id)
    {
        $this->permit('accounting.chart_of_accounts.create');

        return view('accounting.accounts.edit', $this->accountFormData($id) + [
            'record' => $this->findAccount($id),
        ]);
    }

    public function updateAccount(Request $request, int $id)
    {
        $this->permit('accounting.chart_of_accounts.create');

        $account = $this->findAccount($id);

        $validated = $request->validate($this->accountRules($account->id));

        try {
            $this->accounting->updateAccount($account, $validated + [
                'allow_manual' => $request->boolean('allow_manual'),
                'active' => $request->boolean('active'),
            ]);
            $output = $this->ok(__('lang_v1.updated_successfully'));
        } catch (\Throwable $e) {
            return back()->withInput()->with('status', $this->failed($e));
        }

        return redirect()->route('accounting.accounts.show', $account->id)->with('status', $output);
    }

    public function destroyAccount(Request $request, int $id)
    {
        $this->permit('accounting.chart_of_accounts.create');

        try {
            $this->accounting->deleteAccount($this->findAccount($id));
            $output = $this->ok(__('lang_v1.deleted_successfully'));
        } catch (\Throwable $e) {
            $output = $this->failed($e);
        }

        return $request->ajax()
            ? response()->json($output)
            : $this->backToIndex('accounting.accounts.index', $output);
    }

    /* ================================================================
     | Journal
     ================================================================ */

    public function journal(Request $request)
    {
        $this->gate('accounting.view', 'accounting.journal_entries.create');

        return view('accounting.journal.index', [
            'records' => $this->documentList($request)->paginate(25)->withQueryString(),
            'totals' => $this->journalTotals($request),
            'range' => $this->reports->dateRange($request),
            'accounts' => ['' => __('lang_v1.all')] + ChartOfAccount::forDropdown(),
            'costCenters' => ['' => __('lang_v1.all')] + CostCenter::forDropdown(),
            'canPost' => $this->allows('accounting.journal_entries.create'),
        ]);
    }

    public function createJournal()
    {
        $this->permit('accounting.journal_entries.create');

        return view('accounting.journal.create', $this->journalFormData());
    }

    public function storeJournal(Request $request)
    {
        $this->permit('accounting.journal_entries.create');

        $validated = $request->validate([
            'date' => 'required|date',
            'name' => 'nullable|string|max:255',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
            'location_id' => ['nullable', 'integer', TenantRules::location()],
            /*
             * Validated but not rendered.
             *
             * `journal_entries.contact_id` is real and {@see AccountingService::postJournal()}
             * accepts it, so a programmatic caller — an auto-posting listener that
             * ties a receivable line to the customer it belongs to — can set it. The
             * manual form deliberately does not offer the field: there is no bounded
             * contact dropdown in this codebase (the POS uses an AJAX search,
             * because a tenant can hold thousands), and a manual entry that needs to
             * name a customer is a receivable adjustment that belongs on the
             * contact's own ledger screen where the contact is already known. Adding
             * a search field here would mean new JavaScript, which the design
             * directive rules out. Recorded in NOTES §18.
             *
             * The rule stays because the day the field *is* rendered, the tenancy
             * clause must already be here — §12.6 exists because fourteen call sites
             * each had to remember it.
             */
            'contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')
                ->where('business_id', Tenancy::id())->whereNull('deleted_at')],
            'lines' => 'required|array|min:2',
            'lines.*.chart_of_account_id' => ['nullable', 'integer', TenantRules::chartOfAccount()],
            'lines.*.cost_center_id' => ['nullable', 'integer', TenantRules::costCenter()],
            'lines.*.debit' => 'nullable|string|max:32',
            'lines.*.credit' => 'nullable|string|max:32',
            'lines.*.notes' => 'nullable|string|max:255',
        ]);

        try {
            $number = $this->accounting->postJournal($validated);
            $output = $this->ok(__('accounting.posted_successfully', ['number' => $number]));
        } catch (\Throwable $e) {
            return back()->withInput()->with('status', $this->failed($e));
        }

        return redirect()->route('accounting.journal.show', $number)->with('status', $output);
    }

    /**
     * One document, as its lines.
     *
     * Keyed by `transaction_number`, not by row id — see the class docblock. The
     * number is what the ledger, the reversal and the transfer all address a
     * document by, so a URL built on a line id would be the only place in the
     * module that disagreed.
     */
    public function showJournal(string $number)
    {
        $this->gate('accounting.view', 'accounting.journal_entries.create');

        $lines = $this->accounting->documentQuery($number)
            ->with(['chart_of_account', 'cost_center', 'business_location', 'created_by', 'contact'])
            ->orderByDesc('debit')
            ->orderBy('id')
            ->get();

        abort_if($lines->isEmpty(), 404);

        return view('accounting.journal.show', [
            'number' => $number,
            'lines' => $lines,
            'debit' => round((float) $lines->sum('debit'), 4),
            'credit' => round((float) $lines->sum('credit'), 4),
            'reversed' => (bool) $lines->first()->reversed,
            // Offered only where it leads somewhere: the service refuses a second
            // reversal and refuses an irreversible document, and a button whose
            // only outcome is an error message is a lie about what is possible.
            'canReverse' => $this->allows('accounting.journal_entries.reverse')
                && ! $lines->contains(fn ($line) => (bool) $line->reversed)
                && ! $lines->contains(fn ($line) => ! (bool) $line->reversible),
        ]);
    }

    public function reverse(string $number)
    {
        $this->permit('accounting.journal_entries.reverse');

        try {
            $reversal = $this->accounting->reverse($number);
            $output = $this->ok(__('accounting.reversed_successfully', ['number' => $reversal]));
        } catch (\Throwable $e) {
            return back()->with('status', $this->failed($e));
        }

        return redirect()->route('accounting.journal.show', $reversal)->with('status', $output);
    }

    /* ================================================================
     | Transfers
     ================================================================ */

    public function transfers(Request $request)
    {
        $this->gate('accounting.view', 'accounting.transfers.create');

        $records = Transfer::forBusiness()
            ->with(['transfer_from', 'transfer_to', 'transfer_by'])
            ->when($request->filled('search'), fn ($q) => $q->where(
                'journal_transaction_number', 'like', '%'.$request->string('search').'%'
            ))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('accounting.transfers.index', [
            'records' => $records,
            'total' => round((float) Transfer::forBusiness()->sum('amount'), 4),
            'canTransfer' => $this->allows('accounting.transfers.create'),
        ]);
    }

    public function createTransfer()
    {
        $this->permit('accounting.transfers.create');

        return view('accounting.transfers.create', [
            'accounts' => ChartOfAccount::forDropdown(),
            'locations' => BusinessLocation::forDropdown(),
        ]);
    }

    public function storeTransfer(Request $request)
    {
        $this->permit('accounting.transfers.create');

        $validated = $request->validate([
            'transfer_from_id' => ['required', 'integer', TenantRules::chartOfAccount()],
            'transfer_to_id' => ['required', 'integer', 'different:transfer_from_id',
                TenantRules::chartOfAccount()],
            'amount' => 'required|string|max:32',
            'date' => 'required|date',
            'notes' => 'nullable|string|max:1000',
            'location_id' => ['nullable', 'integer', TenantRules::location()],
        ]);

        try {
            $transfer = $this->accounting->transfer($validated);
            $output = $this->ok(__('accounting.transferred_successfully'));
        } catch (\Throwable $e) {
            return back()->withInput()->with('status', $this->failed($e));
        }

        return redirect()->route('accounting.journal.show', $transfer->journal_transaction_number)
            ->with('status', $output);
    }

    /* ================================================================
     | Cost centres
     ================================================================ */

    public function costCenters(Request $request)
    {
        $this->gate('accounting.view', 'accounting.cost_centers.create',
            'accounting.cost_centers.edit');

        $records = CostCenter::forBusiness()
            ->with(['parent', 'manager', 'location'])
            ->withCount('journalEntries')
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('search'), fn ($q) => $q->where(
                fn ($inner) => $inner->where('name', 'like', '%'.$request->string('search').'%')
                    ->orWhere('code', 'like', '%'.$request->string('search').'%')
            ))
            ->orderBy('sort_order')
            ->orderBy('code')
            ->paginate(25)
            ->withQueryString();

        return view('accounting.cost-centers.index', [
            'records' => $records,
            'totals' => [
                'total' => CostCenter::forBusiness()->count(),
                'active' => CostCenter::forBusiness()->active()->count(),
                'budget' => round((float) CostCenter::forBusiness()->active()->sum('budget_amount'), 4),
            ],
            'types' => ['' => __('lang_v1.all')] + $this->costCenterTypes(),
            'canAdd' => $this->allows('accounting.cost_centers.create'),
            'canEdit' => $this->allows('accounting.cost_centers.edit'),
        ]);
    }

    public function createCostCenter()
    {
        $this->permit('accounting.cost_centers.create');

        return view('accounting.cost-centers.create', $this->costCenterFormData());
    }

    public function storeCostCenter(Request $request)
    {
        $this->permit('accounting.cost_centers.create');

        $validated = $request->validate($this->costCenterRules());

        try {
            $this->accounting->createCostCenter($validated + [
                'is_active' => $request->boolean('is_active'),
            ]);
            $output = $this->ok(__('lang_v1.added_successfully'));
        } catch (\Throwable $e) {
            return back()->withInput()->with('status', $this->failed($e));
        }

        return $this->backToIndex('accounting.cost-centers.index', $output);
    }

    public function editCostCenter(int $id)
    {
        $this->permit('accounting.cost_centers.edit');

        return view('accounting.cost-centers.edit', $this->costCenterFormData($id) + [
            'record' => $this->findCostCenter($id),
        ]);
    }

    public function updateCostCenter(Request $request, int $id)
    {
        $this->permit('accounting.cost_centers.edit');

        $centre = $this->findCostCenter($id);

        $validated = $request->validate($this->costCenterRules($centre->id));

        try {
            $this->accounting->updateCostCenter($centre, $validated + [
                'is_active' => $request->boolean('is_active'),
            ]);
            $output = $this->ok(__('lang_v1.updated_successfully'));
        } catch (\Throwable $e) {
            return back()->withInput()->with('status', $this->failed($e));
        }

        return $this->backToIndex('accounting.cost-centers.index', $output);
    }

    public function destroyCostCenter(Request $request, int $id)
    {
        $this->permit('accounting.cost_centers.edit');

        try {
            $this->accounting->deleteCostCenter($this->findCostCenter($id));
            $output = $this->ok(__('lang_v1.deleted_successfully'));
        } catch (\Throwable $e) {
            $output = $this->failed($e);
        }

        return $request->ajax()
            ? response()->json($output)
            : $this->backToIndex('accounting.cost-centers.index', $output);
    }

    /* ================================================================
     | Trial balance
     ================================================================ */

    public function trialBalance(Request $request)
    {
        $this->gate('accounting.view', 'accounting.journal_entries.create');

        $range = $this->reports->dateRange($request);
        $costCenterId = $request->filled('cost_center_id')
            ? $request->integer('cost_center_id')
            : null;

        return view('accounting.trial-balance', [
            'range' => $range,
            'report' => $this->accounting->trialBalance($range, $costCenterId),
            'costCenters' => ['' => __('lang_v1.all')] + CostCenter::forDropdown(),
            'accountCount' => ChartOfAccount::forBusiness()->count(),
        ]);
    }

    /* ================================================================
     | Internals
     ================================================================ */

    /**
     * Every accounting screen needs the module *and* a permission, and the module
     * always comes first.
     *
     * Ordered deliberately: a tenant that has not bought the accounting module
     * should be told the module is off, not that they lack a permission they were
     * never offered. `permit()` alone would produce the second message.
     */
    protected function gate(string ...$permissions): void
    {
        $this->requireModule('accounting');
        $this->permit(...$permissions);
    }

    /**
     * The journal listing: one row per document.
     *
     * Grouped in SQL rather than in PHP, because the alternative is loading every
     * line of every document in the window to fold them — which is the whole
     * ledger. `MIN(id)` gives the listing a stable sort key and a link target;
     * `SUM(debit)` is the document's value, and taking the debit side rather than
     * both is not arbitrary — the two sides are equal by construction, so summing
     * both would state every document at twice its worth.
     *
     * The total is aliased `document_total` and **not** `amount`, which is the
     * obvious name and would have been silently wrong: `JournalEntry` defines
     * `getAmountAttribute()`, and an Eloquent accessor takes precedence over a
     * selected column of the same name. `$record->amount` would therefore have run
     * the accessor, read `debit`/`credit` — neither of which this grouped select
     * returns — and shown every document as zero, on a screen with no error on it.
     */
    protected function documentList(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $range = $this->reports->dateRange($request);

        return $this->accounting->journalQuery()
            ->betweenDates($range['start'], $range['end'])
            ->when($request->filled('chart_of_account_id'),
                fn ($q) => $q->where('chart_of_account_id', $request->integer('chart_of_account_id')))
            ->when($request->filled('cost_center_id'),
                fn ($q) => $q->where('cost_center_id', $request->integer('cost_center_id')))
            ->when($request->input('state') === 'reversed', fn ($q) => $q->where('reversed', 1))
            ->when($request->input('state') === 'live', fn ($q) => $q->where('reversed', 0))
            ->when($request->filled('search'), fn ($q) => $q->where(
                fn ($inner) => $inner->where('transaction_number', 'like', '%'.$request->string('search').'%')
                    ->orWhere('name', 'like', '%'.$request->string('search').'%')
                    ->orWhere('reference', 'like', '%'.$request->string('search').'%')
            ))
            ->selectRaw(
                'MIN(id) AS id,
                 transaction_number,
                 MIN(date) AS date,
                 MIN(name) AS name,
                 MIN(reference) AS reference,
                 MIN(transaction_sub_type) AS transaction_sub_type,
                 MAX(reversed) AS reversed,
                 COUNT(*) AS line_count,
                 SUM(debit) AS document_total'
            )
            ->groupBy('transaction_number')
            ->orderByDesc('date')
            ->orderByDesc('id');
    }

    /**
     * @return array<string, float|int>
     */
    protected function journalTotals(Request $request): array
    {
        $range = $this->reports->dateRange($request);

        $lines = $this->accounting->journalQuery()
            ->notReversed()
            ->betweenDates($range['start'], $range['end']);

        return [
            'documents' => $this->accounting->journalQuery()
                ->betweenDates($range['start'], $range['end'])
                ->distinct()
                ->count('transaction_number'),
            'debit' => round((float) $lines->clone()->sum('debit'), 4),
            'credit' => round((float) $lines->clone()->sum('credit'), 4),
        ];
    }

    /**
     * @return array<string, float|int>
     */
    protected function accountTotals(): array
    {
        $query = ChartOfAccount::forBusiness();

        return [
            'total' => $query->clone()->count(),
            'active' => $query->clone()->active()->count(),
            'manual' => $query->clone()->where('allow_manual', 1)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function accountFormData(?int $excludeId = null): array
    {
        /*
         * An account cannot be offered itself as a parent. Its descendants are not
         * excluded here — the service refuses a cycle with a message that says so,
         * and building the descendant set to prune the dropdown would mean walking
         * the tree on every form render to prevent a mistake the save already
         * catches.
         */
        $parents = ChartOfAccount::forBusiness()
            ->active()
            ->when($excludeId, fn ($q) => $q->whereKeyNot($excludeId))
            ->orderBy('gl_code')
            ->get()
            ->mapWithKeys(fn ($a) => [$a->id => ($a->gl_code ? $a->gl_code.' - ' : '').$a->name])
            ->all();

        return [
            'types' => ChartOfAccount::accountTypes(),
            'parents' => ['' => __('lang_v1.none')] + $parents,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function journalFormData(): array
    {
        return [
            'accounts' => ChartOfAccount::forDropdown(),
            'costCenters' => ['' => __('lang_v1.none')] + CostCenter::forDropdown(),
            'locations' => ['' => __('lang_v1.none')] + BusinessLocation::forDropdown(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function costCenterFormData(?int $excludeId = null): array
    {
        $parents = CostCenter::forBusiness()
            ->active()
            ->when($excludeId, fn ($q) => $q->whereKeyNot($excludeId))
            ->orderBy('sort_order')
            ->get()
            ->mapWithKeys(fn ($c) => [$c->id => $c->code.' - '.$c->name])
            ->all();

        return [
            'types' => $this->costCenterTypes(),
            'parents' => ['' => __('lang_v1.none')] + $parents,
            'managers' => ['' => __('lang_v1.none')] + User::forDropdown(),
            'locations' => ['' => __('lang_v1.none')] + BusinessLocation::forDropdown(),
            'periods' => $this->budgetPeriods(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function accountRules(?int $ignoreId = null): array
    {
        return [
            'name' => 'required|string|max:255',
            /*
             * GL codes are unique per tenant when present, and nullable because a
             * tenant that does not use them should not be forced to invent them.
             * `ignore()` is what lets an edit save an account without changing its
             * own code — without it the rule would find the row it is validating
             * and refuse.
             */
            'gl_code' => ['nullable', 'integer', 'min:0',
                Rule::unique('chart_of_accounts', 'gl_code')
                    ->where('business_id', Tenancy::id())
                    ->ignore($ignoreId)],
            'account_type' => ['required', Rule::in(array_keys(ChartOfAccount::accountTypes()))],
            'parent_id' => ['nullable', 'integer', TenantRules::chartOfAccount()],
            'opening_balance' => 'nullable|string|max:32',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function costCenterRules(?int $ignoreId = null): array
    {
        return [
            // Unique per tenant and required, unlike a GL code: the code is what a
            // cost centre is addressed by on every report, and the schema already
            // declares `unique(['business_id', 'code'])` — so without the rule the
            // duplicate surfaces as a driver exception rather than a field error.
            'code' => ['required', 'string', 'max:255',
                Rule::unique('cost_centers', 'code')
                    ->where('business_id', Tenancy::id())
                    ->ignore($ignoreId)],
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'type' => ['required', Rule::in(array_keys($this->costCenterTypes()))],
            'parent_id' => ['nullable', 'integer', TenantRules::costCenter()],
            'manager_id' => ['nullable', 'integer', TenantRules::user()],
            'location_id' => ['nullable', 'integer', TenantRules::location()],
            'budget_amount' => 'nullable|string|max:32',
            'budget_period' => ['required', Rule::in(array_keys($this->budgetPeriods()))],
            'sort_order' => 'nullable|integer|min:0|max:9999',
        ];
    }

    protected function findAccount(int $id, array $with = []): ChartOfAccount
    {
        return ChartOfAccount::with($with)->forBusiness()->findOrFail($id);
    }

    protected function findCostCenter(int $id, array $with = []): CostCenter
    {
        return CostCenter::with($with)->forBusiness()->findOrFail($id);
    }

    /**
     * @return array<string, string>
     */
    protected function costCenterTypes(): array
    {
        return [
            'cost' => __('accounting.type_cost'),
            'profit' => __('accounting.type_profit'),
            'investment' => __('accounting.type_investment'),
            'support' => __('accounting.type_support'),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function budgetPeriods(): array
    {
        return [
            'monthly' => __('accounting.monthly'),
            'quarterly' => __('accounting.quarterly'),
            'yearly' => __('accounting.yearly'),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function stateOptions(bool $addAll = false): array
    {
        $options = [
            'active' => __('lang_v1.active'),
            'inactive' => __('lang_v1.inactive'),
        ];

        return $addAll ? ['' => __('lang_v1.all')] + $options : $options;
    }
}
