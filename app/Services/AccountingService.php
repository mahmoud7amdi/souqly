<?php

namespace App\Services;

use App\Models\BusinessLocation;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\CostCenter;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\Transfer;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Double-entry bookkeeping.
 *
 * Every figure and every refusal in the accounting module lives here. Four things
 * about the schema shape this class, and each of them is a decision the reader
 * should be able to find rather than reconstruct:
 *
 * **1. There is no journal *document* table.** A document is nothing but the set
 * of `journal_entries` rows that share a `transaction_number`. So "post" means:
 * check that the lines balance, take one reference number, insert N rows inside a
 * transaction. Nothing else guards the invariant — there is no header row holding
 * a total that the database could check the lines against — which is exactly why
 * {@see postJournal()} refuses an unbalanced set before it writes anything rather
 * than letting the ledger hold a document that does not add up.
 *
 * **2. `journal_entries` has no `business_id`.** Tenancy is established by
 * reaching through `chart_of_account_id` — see
 * {@see JournalEntry::scopeForBusiness()}. The account id on the way in is
 * therefore not merely a foreign key, it is *the* thing that decides which
 * tenant owns the posting, which is why every account id is validated through
 * {@see \App\Support\TenantRules::chartOfAccount()} and re-checked here.
 *
 * **3. `journal_entries.balance` is left null on purpose.** The column exists and
 * the source system used it as a stored running balance. A stored running balance
 * is wrong the instant a back-dated entry is inserted above it, and correcting it
 * means rewriting every later row of that account. Nothing in this codebase reads
 * the column; balances are summed on demand from `debit`/`credit`. Recorded in
 * NOTES §18.
 *
 * **4. Reversal excludes both sides.** `JournalEntry::notReversed()` filters
 * `reversed = 0`, and `ChartOfAccount::getCurrentBalanceAttribute()` honours it —
 * so in this schema `reversed = 1` means "excluded from the arithmetic". A
 * reversal therefore flags the original *and* flags the mirror it posts, and the
 * pair contributes nothing. The mirror is not an arithmetic device; it is the
 * evidence — it carries the date the reversal happened, who did it and why, none
 * of which a flag on the original could record, because there is no
 * `reversed_at`/`reversed_by` column. The amounts on it are true mirrors, so the
 * arithmetic also comes out right for anyone who later reads the pair without the
 * scope.
 */
class AccountingService
{
    public function __construct(
        protected ReferenceService $references,
        protected FormattingService $format,
    ) {}

    /* ================================================================
     | Chart of accounts
     ================================================================ */

    public function createAccount(array $data): ChartOfAccount
    {
        $this->assertOwnAccount($data['parent_id'] ?? null);

        return ChartOfAccount::create($this->accountAttributes($data) + [
            'business_id' => Tenancy::id(),
        ]);
    }

    public function updateAccount(ChartOfAccount $account, array $data): ChartOfAccount
    {
        $parentId = $data['parent_id'] ?? null;

        $this->assertOwnAccount($parentId);

        /*
         * An account cannot be its own parent, nor a descendant's child. The second
         * half is the one that matters: a cycle in the chart makes every tree walk
         * — the chart screen, any roll-up total — loop until it runs out of memory,
         * and nothing in the schema prevents it. Checked here rather than in a
         * validation rule because it needs the row being edited, which a rule
         * string cannot see.
         */
        if (! is_null($parentId) && $this->wouldCycle($account, (int) $parentId)) {
            throw new \InvalidArgumentException(__('accounting.parent_would_cycle'));
        }

        $account->update($this->accountAttributes($data));

        return $account->refresh();
    }

    public function deleteAccount(ChartOfAccount $account): void
    {
        if ($account->journal_entries()->exists()) {
            throw new \RuntimeException(__('accounting.account_has_entries'));
        }

        if ($account->children()->exists()) {
            throw new \RuntimeException(__('accounting.account_has_children'));
        }

        $account->delete();
    }

    /* ================================================================
     | Journal documents
     ================================================================ */

    /**
     * Post one balanced journal document and return its transaction number.
     *
     * @param  array{date?: string|null, name?: string|null, reference?: string|null,
     *               notes?: string|null, location_id?: int|null,
     *               cost_center_id?: int|null, contact_id?: int|null,
     *               lines: array<int, array<string, mixed>>}  $data
     */
    public function postJournal(array $data): string
    {
        $lines = $this->normaliseLines($data['lines'] ?? []);

        return DB::transaction(function () use ($data, $lines) {
            $number = $this->references->generate('journal_entry');
            $date = $data['date'] ?? now()->toDateString();

            foreach ($lines as $line) {
                JournalEntry::create($this->entryAttributes($data, $line, $number, $date) + [
                    'manual_entry' => 1,
                    'transaction_type' => 'journal_entry',
                ]);
            }

            return $number;
        });
    }

    /**
     * Reverse a posted document, and return the reversal's transaction number.
     *
     * Takes the number rather than a row because a document is a set of rows and
     * reversing one line of it is not a thing that can be meant.
     */
    public function reverse(string $transactionNumber): string
    {
        return DB::transaction(function () use ($transactionNumber) {
            /*
             * Locked before it is read. Two clerks pressing reverse on the same
             * document a second apart would otherwise both pass the
             * already-reversed check and post two mirrors, and the account would
             * end up short by the document's value with nothing on screen to
             * explain it.
             */
            $original = $this->documentQuery($transactionNumber)
                ->lockForUpdate()
                ->get();

            if ($original->isEmpty()) {
                throw new \RuntimeException(__('accounting.document_not_found'));
            }

            if ($original->contains(fn ($line) => (bool) $line->reversed)) {
                throw new \RuntimeException(__('accounting.already_reversed'));
            }

            if ($original->contains(fn ($line) => ! (bool) $line->reversible)) {
                throw new \RuntimeException(__('accounting.not_reversible'));
            }

            $number = $this->references->generate('journal_entry');

            foreach ($original as $line) {
                JournalEntry::create([
                    'transaction_number' => $number,
                    'chart_of_account_id' => $line->chart_of_account_id,
                    'cost_center_id' => $line->cost_center_id,
                    'location_id' => $line->location_id,
                    'currency_id' => $line->currency_id,
                    'contact_id' => $line->contact_id,
                    'created_by_id' => auth()->id(),
                    'transaction_type' => 'journal_entry',
                    'transaction_sub_type' => 'reversal',
                    'name' => $line->name,
                    // The original's number, so the pair is findable from either end.
                    'reference' => $transactionNumber,
                    'date' => now()->toDateString(),
                    'month' => now()->format('m'),
                    'year' => now()->format('Y'),
                    // Mirrored.
                    'debit' => $line->credit,
                    'credit' => $line->debit,
                    'manual_entry' => 1,
                    // Excluded from the arithmetic, like the original — see the
                    // class docblock, point 4.
                    'reversed' => 1,
                    // A reversal of a reversal would re-post the original with no
                    // way to tell the two apart.
                    'reversible' => 0,
                    'notes' => __('accounting.reversal_of', ['number' => $transactionNumber]),
                ]);
            }

            $this->documentQuery($transactionNumber)->update(['reversed' => 1]);

            return $number;
        });
    }

    /* ================================================================
     | Transfers
     ================================================================ */

    /**
     * Move money between two accounts.
     *
     * A transfer is a two-line journal document plus a row in `transfers` that
     * names it. The journal is what the ledger reads; the `transfers` row is what
     * the transfer screen lists, and it exists because "show me the transfers"
     * cannot otherwise be asked of a table whose documents are only distinguished
     * by which accounts they happen to touch.
     *
     * @param  array{transfer_from_id: int, transfer_to_id: int, amount: float|string,
     *               date?: string|null, notes?: string|null, location_id?: int|null}  $data
     */
    public function transfer(array $data): Transfer
    {
        $from = (int) $data['transfer_from_id'];
        $to = (int) $data['transfer_to_id'];

        if ($from === $to) {
            throw new \InvalidArgumentException(__('accounting.transfer_same_account'));
        }

        $amount = round($this->format->numUf($data['amount']), 4);

        if ($amount <= 0) {
            throw new \InvalidArgumentException(__('accounting.transfer_needs_amount'));
        }

        $this->assertOwnAccount($from);
        $this->assertOwnAccount($to);

        return DB::transaction(function () use ($data, $from, $to, $amount) {
            /*
             * Money leaving is a credit on the source and a debit on the
             * destination — the destination gains, and a gain on an asset account is
             * a debit. Getting this pair the wrong way round is the classic
             * bookkeeping inversion, and it still balances, which is why the test
             * asserts the sides and not merely the total.
             */
            $number = $this->postJournal([
                'date' => $data['date'] ?? now()->toDateString(),
                'name' => __('accounting.transfer'),
                'notes' => $data['notes'] ?? null,
                'location_id' => $data['location_id'] ?? null,
                'lines' => [
                    ['chart_of_account_id' => $to, 'debit' => $amount, 'credit' => 0],
                    ['chart_of_account_id' => $from, 'debit' => 0, 'credit' => $amount],
                ],
            ]);

            JournalEntry::where('transaction_number', $number)
                ->update(['transaction_sub_type' => 'transfer']);

            return Transfer::create([
                'journal_transaction_number' => $number,
                'transfer_from_id' => $from,
                'transfer_to_id' => $to,
                'transfer_by_id' => (int) auth()->id(),
                'amount' => $amount,
            ]);
        });
    }

    /* ================================================================
     | Cost centres
     ================================================================ */

    public function createCostCenter(array $data): CostCenter
    {
        return CostCenter::create($this->costCenterAttributes($data) + [
            'business_id' => Tenancy::id(),
        ]);
    }

    public function updateCostCenter(CostCenter $centre, array $data): CostCenter
    {
        $parentId = $data['parent_id'] ?? null;

        if (! is_null($parentId) && (int) $parentId === (int) $centre->id) {
            throw new \InvalidArgumentException(__('accounting.parent_would_cycle'));
        }

        $centre->update($this->costCenterAttributes($data));

        return $centre->refresh();
    }

    public function deleteCostCenter(CostCenter $centre): void
    {
        if ($centre->journalEntries()->exists()) {
            throw new \RuntimeException(__('accounting.cost_center_has_entries'));
        }

        if ($centre->children()->exists()) {
            throw new \RuntimeException(__('accounting.cost_center_has_children'));
        }

        $centre->delete();
    }

    /* ================================================================
     | Reporting
     ================================================================ */

    /**
     * The trial balance: every account's position at the start of the window, its
     * movement inside it, and where it ends.
     *
     * Five columns rather than the classic two, because the classic two cannot be
     * filtered by date without lying. `chart_of_accounts.opening_balance` is the
     * balance at *inception*, so folding it into a March-only report would state a
     * March opening that is short by every posting made in January and February.
     * The opening column here is therefore inception opening **plus** the net of
     * every live entry strictly before the window — which is the only reading under
     * which opening + movement = closing holds.
     *
     * The invariant worth testing is `debit total == credit total`. It holds
     * because every document {@see postJournal()} writes balances, and it is the
     * one figure that would break if that check were ever bypassed. Opening and
     * closing totals are *not* asserted to be zero: `opening_balance` is entered
     * per account with no balance check anywhere in the schema, so a chart can
     * legitimately open out of balance — and the caller is told so rather than
     * shown a total that quietly disagrees with itself.
     *
     * @return array{rows: \Illuminate\Support\Collection<int, array<string, mixed>>,
     *               totals: array<string, float>, balanced: bool, opening_balanced: bool}
     */
    public function trialBalance(array $range, ?int $costCenterId = null): array
    {
        $accounts = ChartOfAccount::forBusiness()->orderBy('gl_code')->orderBy('name')->get();

        $before = $this->movementMap(null, $range['start'], $costCenterId, true);
        $within = $this->movementMap($range['start'], $range['end'], $costCenterId);

        $rows = $accounts->map(function (ChartOfAccount $account) use ($before, $within) {
            $priorNet = ($before[$account->id]['debit'] ?? 0.0) - ($before[$account->id]['credit'] ?? 0.0);

            $debit = (float) ($within[$account->id]['debit'] ?? 0.0);
            $credit = (float) ($within[$account->id]['credit'] ?? 0.0);

            $opening = round($this->openingAsDebit($account) + $priorNet, 4);

            return [
                'account' => $account,
                'opening' => $opening,
                'debit' => round($debit, 4),
                'credit' => round($credit, 4),
                'closing' => round($opening + $debit - $credit, 4),
            ];
        })
            /*
             * An account with no opening balance and no movement in the window is
             * dropped. A chart of accounts is a permanent structure — most tenants
             * will carry a hundred nodes and touch fifteen — so keeping the empty
             * ones would bury the report in zero rows. The stat tile above the
             * table still counts every account, so the omission is visible rather
             * than silent.
             */
            ->reject(fn (array $row) => $row['opening'] == 0.0
                && $row['debit'] == 0.0
                && $row['credit'] == 0.0)
            ->values();

        $totals = [
            'opening' => round((float) $rows->sum('opening'), 4),
            'debit' => round((float) $rows->sum('debit'), 4),
            'credit' => round((float) $rows->sum('credit'), 4),
            'closing' => round((float) $rows->sum('closing'), 4),
        ];

        return [
            'rows' => $rows,
            'totals' => $totals,
            'balanced' => abs($totals['debit'] - $totals['credit']) < 0.0001,
            'opening_balanced' => abs($totals['opening']) < 0.0001,
        ];
    }

    /**
     * Dashboard figures: what the books are worth, and what the period did to them.
     *
     * @return array<string, float|int|bool>
     */
    public function dashboard(array $range): array
    {
        $balances = $this->balancesByType();
        $movement = $this->movementByType($range);

        $income = (float) ($movement['income'] ?? 0.0);
        $expense = (float) ($movement['expense'] ?? 0.0);

        return [
            'assets' => (float) ($balances['asset'] ?? 0.0),
            'liabilities' => (float) ($balances['liability'] ?? 0.0),
            'equity' => (float) ($balances['equity'] ?? 0.0),
            'income' => $income,
            'expense' => $expense,
            'net' => round($income - $expense, 4),
            'accounts' => ChartOfAccount::forBusiness()->count(),
            'documents' => $this->journalQuery()
                ->betweenDates($range['start'], $range['end'])
                ->distinct()
                ->count('transaction_number'),
            'balanced' => $this->trialBalance($range)['balanced'],
        ];
    }

    /* ================================================================
     | Queries the controller reuses
     ================================================================ */

    /**
     * Journal lines this tenant owns and this user's branches may see.
     *
     * The location carve-out matches {@see \App\Modules\AssetManagement\Models\Asset::scopePermitted()}:
     * a line tagged to a branch is branch information, and a line tagged to none is
     * head office and visible to everyone who may open the ledger at all. Without
     * the `orWhereNull`, a branch-restricted accountant would see a ledger missing
     * every head-office posting — which balances to something other than zero and
     * looks exactly like a bug in the books.
     */
    public function journalQuery(): Builder
    {
        $permitted = BusinessLocation::permittedLocations();

        return JournalEntry::forBusiness()
            ->when($permitted !== 'all', fn ($q) => $q->where(
                fn ($inner) => $inner->whereIn('location_id', (array) $permitted)
                    ->orWhereNull('location_id')
            ));
    }

    /**
     * Every line of one document, tenant-scoped.
     */
    public function documentQuery(string $transactionNumber): Builder
    {
        return JournalEntry::forBusiness()->where('transaction_number', $transactionNumber);
    }

    /* ================================================================
     | Internals
     ================================================================ */

    /**
     * Reject a set of lines that is not a document, and return it cleaned.
     *
     * Three refusals, in the order a wrong form fails them: a line has to name an
     * amount on exactly one side, there have to be at least two lines, and the
     * sides have to agree. The comparison tolerance is 0.0001 because that is the
     * scale `decimal(22,4)` stores — a stricter test would fail on values the
     * column cannot even hold, and a looser one would accept a document that is
     * visibly out by a piastre.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    protected function normaliseLines(array $lines): array
    {
        $clean = [];
        $debits = 0.0;
        $credits = 0.0;

        foreach ($lines as $line) {
            $accountId = (int) ($line['chart_of_account_id'] ?? 0);

            if ($accountId <= 0) {
                continue;
            }

            $debit = round($this->format->numUf($line['debit'] ?? 0), 4);
            $credit = round($this->format->numUf($line['credit'] ?? 0), 4);

            // A row the clerk added and left blank is not an error, it is a row
            // they did not use.
            if ($debit == 0.0 && $credit == 0.0) {
                continue;
            }

            if ($debit != 0.0 && $credit != 0.0) {
                throw new \InvalidArgumentException(__('accounting.line_needs_one_side'));
            }

            if ($debit < 0 || $credit < 0) {
                throw new \InvalidArgumentException(__('accounting.line_cannot_be_negative'));
            }

            $this->assertOwnAccount($accountId);

            $debits += $debit;
            $credits += $credit;

            $clean[] = [
                'chart_of_account_id' => $accountId,
                'debit' => $debit ?: null,
                'credit' => $credit ?: null,
                'cost_center_id' => ($line['cost_center_id'] ?? null) ?: null,
                'notes' => $line['notes'] ?? null,
            ];
        }

        if (count($clean) < 2) {
            throw new \InvalidArgumentException(__('accounting.needs_two_lines'));
        }

        if (abs($debits - $credits) > 0.0001) {
            throw new \InvalidArgumentException(__('accounting.unbalanced_document', [
                'debit' => $this->format->currencyF($debits),
                'credit' => $this->format->currencyF($credits),
                'difference' => $this->format->currencyF(abs($debits - $credits)),
            ]));
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    protected function entryAttributes(array $data, array $line, string $number, string $date): array
    {
        return [
            'transaction_number' => $number,
            'chart_of_account_id' => $line['chart_of_account_id'],
            'cost_center_id' => $line['cost_center_id'] ?? ($data['cost_center_id'] ?? null) ?: null,
            'location_id' => ($data['location_id'] ?? null) ?: null,
            'contact_id' => ($data['contact_id'] ?? null) ?: null,
            // Taken from the account rather than the tenant: a bank account held in
            // another currency is the reason the column is on `chart_of_accounts`.
            'currency_id' => ChartOfAccount::whereKey($line['chart_of_account_id'])->value('currency_id'),
            'created_by_id' => auth()->id(),
            'name' => $data['name'] ?? null,
            'reference' => $data['reference'] ?? null,
            'date' => $date,
            // `month`/`year` are strings in the schema with no consumer yet. Filled
            // as zero-padded month and four-digit year — a choice, recorded in
            // NOTES §18, because a half-populated column is worse than either.
            'month' => date('m', strtotime($date)),
            'year' => date('Y', strtotime($date)),
            'debit' => $line['debit'],
            'credit' => $line['credit'],
            'notes' => $line['notes'] ?? ($data['notes'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function accountAttributes(array $data): array
    {
        return [
            'name' => $data['name'],
            'gl_code' => ($data['gl_code'] ?? null) ?: null,
            'account_type' => $data['account_type'],
            'parent_id' => ($data['parent_id'] ?? null) ?: null,
            'account_subtype_id' => ($data['account_subtype_id'] ?? null) ?: null,
            'detail_type_id' => ($data['detail_type_id'] ?? null) ?: null,
            'opening_balance' => round($this->format->numUf($data['opening_balance'] ?? 0), 4),
            'allow_manual' => (int) (bool) ($data['allow_manual'] ?? false),
            'active' => (int) (bool) ($data['active'] ?? true),
            'notes' => $data['notes'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function costCenterAttributes(array $data): array
    {
        return [
            'code' => $data['code'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'type' => $data['type'] ?? 'cost',
            'parent_id' => ($data['parent_id'] ?? null) ?: null,
            'manager_id' => ($data['manager_id'] ?? null) ?: null,
            'location_id' => ($data['location_id'] ?? null) ?: null,
            'budget_amount' => round($this->format->numUf($data['budget_amount'] ?? 0), 4),
            'budget_period' => $data['budget_period'] ?? 'monthly',
            'is_active' => (bool) ($data['is_active'] ?? true),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }

    /**
     * The account's inception opening balance, expressed as a signed debit.
     *
     * `opening_balance` is stored unsigned and means "this much, on this account's
     * natural side". Assets and expenses are debit-natured, everything else is
     * credit-natured — the same split {@see ChartOfAccount::getCurrentBalanceAttribute()}
     * applies, kept identical on purpose so the two never disagree about an
     * account's sign.
     */
    protected function openingAsDebit(ChartOfAccount $account): float
    {
        $opening = (float) $account->opening_balance;

        return in_array($account->account_type, ['asset', 'expense'], true)
            ? $opening
            : -$opening;
    }

    /**
     * Debit and credit sums per account over a window, in one query.
     *
     * @return array<int, array{debit: float, credit: float}>
     */
    protected function movementMap(
        ?string $start,
        ?string $end,
        ?int $costCenterId = null,
        bool $endExclusive = false
    ): array {
        $query = $this->journalQuery()->notReversed();

        if (! empty($start)) {
            $query->where('date', '>=', $start);
        }

        if (! empty($end)) {
            // The "before the window" call needs strictly-less-than, or the first
            // day of the window would be counted as both opening and movement.
            $query->where('date', $endExclusive ? '<' : '<=', $end);
        }

        if (! empty($costCenterId)) {
            $query->where('cost_center_id', $costCenterId);
        }

        return $query->selectRaw(
            'chart_of_account_id, SUM(debit) AS debit_total, SUM(credit) AS credit_total'
        )
            ->groupBy('chart_of_account_id')
            ->get()
            ->mapWithKeys(fn ($row) => [
                (int) $row->chart_of_account_id => [
                    'debit' => (float) $row->debit_total,
                    'credit' => (float) $row->credit_total,
                ],
            ])
            ->all();
    }

    /**
     * Current balance per account type, on each type's natural side, openings
     * included.
     *
     * Computed from one grouped movement query plus one account read, rather than
     * by looping {@see ChartOfAccount::getCurrentBalanceAttribute()} over the
     * chart. The accessor costs two queries per account, so on a hundred-node
     * chart the readable version of this method was a two-hundred-query dashboard.
     * The sign rule is the same one, deliberately: a tenant whose balance sheet
     * disagreed with its own account screen would be worse than a slow one.
     *
     * @return array<string, float>
     */
    protected function balancesByType(): array
    {
        $movement = $this->movementMap(null, null);
        $totals = [];

        $accounts = ChartOfAccount::forBusiness()->get(['id', 'account_type', 'opening_balance']);

        foreach ($accounts as $account) {
            $net = ($movement[$account->id]['debit'] ?? 0.0) - ($movement[$account->id]['credit'] ?? 0.0);

            $signed = in_array($account->account_type, ['asset', 'expense'], true) ? $net : -$net;

            $totals[$account->account_type] = round(
                ($totals[$account->account_type] ?? 0.0) + (float) $account->opening_balance + $signed,
                4
            );
        }

        return $totals;
    }

    /**
     * Movement per account type inside the window, signed by nature and excluding
     * openings — because an opening balance is not something the period did.
     *
     * Every column here is table-qualified, and that is not style. This is the one
     * query in the class that joins `chart_of_accounts`, and both tables carry an
     * `active` column — so the unqualified `notReversed()` scope, which reads
     * `where('active', 1)`, raises "Column 'active' in where clause is ambiguous"
     * the moment the join is present. The scope's two conditions are therefore
     * spelled out instead of called.
     *
     * @return array<string, float>
     */
    protected function movementByType(array $range): array
    {
        $rows = $this->journalQuery()
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_entries.chart_of_account_id')
            ->where('journal_entries.reversed', 0)
            ->where('journal_entries.active', 1)
            ->when(! empty($range['start']), fn ($q) => $q->where('journal_entries.date', '>=', $range['start']))
            ->when(! empty($range['end']), fn ($q) => $q->where('journal_entries.date', '<=', $range['end']))
            ->selectRaw(
                'chart_of_accounts.account_type AS account_type,
                 SUM(journal_entries.debit) AS debit_total,
                 SUM(journal_entries.credit) AS credit_total'
            )
            ->groupBy('chart_of_accounts.account_type')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $net = (float) $row->debit_total - (float) $row->credit_total;

            $totals[$row->account_type] = round(
                in_array($row->account_type, ['asset', 'expense'], true) ? $net : -$net,
                4
            );
        }

        return $totals;
    }

    /**
     * Refuse an account id that belongs to somebody else.
     *
     * Belt to the validation rule's braces, and deliberately so: {@see postJournal()}
     * and {@see transfer()} are also reachable from a scheduled command and from a
     * test, neither of which runs a form request. Point 2 of the class docblock is
     * why this is worth a query — an account id from another tenant does not
     * produce a visible error, it produces a posting into their ledger.
     */
    protected function assertOwnAccount(int|string|null $accountId): void
    {
        if (empty($accountId)) {
            return;
        }

        $exists = ChartOfAccount::forBusiness()->whereKey((int) $accountId)->exists();

        if (! $exists) {
            throw new \InvalidArgumentException(__('accounting.unknown_account'));
        }
    }

    /**
     * Would re-parenting this account under `$parentId` close a loop?
     *
     * Walks up from the proposed parent looking for the account itself. The depth
     * guard is not paranoia about deep charts — it is what stops this method from
     * hanging if a cycle already exists in the data, which is possible for any row
     * written before this check did.
     */
    protected function wouldCycle(ChartOfAccount $account, int $parentId): bool
    {
        if ($parentId === (int) $account->id) {
            return true;
        }

        $seen = [];
        $cursor = $parentId;

        for ($depth = 0; $depth < 64 && $cursor; $depth++) {
            if (isset($seen[$cursor])) {
                return true;
            }

            $seen[$cursor] = true;

            $cursor = (int) ChartOfAccount::forBusiness()->whereKey($cursor)->value('parent_id');

            if ($cursor === (int) $account->id) {
                return true;
            }
        }

        return false;
    }
}
