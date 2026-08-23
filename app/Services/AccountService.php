<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;

/**
 * Payment accounts and the movements on them.
 *
 * Two kinds of row land in `account_transactions`:
 *
 *   - **Mirrored** — the account side of a transaction payment. Written by the
 *     {@see \App\Listeners\AddAccountTransaction} listener, never here, and never
 *     editable from the account screen: the payment is the fact, the account row
 *     is its shadow.
 *   - **Direct** — an opening balance, a deposit, a withdrawal or a leg of a
 *     fund transfer. Those originate on the account itself and are what this
 *     service writes.
 *
 * Balance is credits − debits ({@see Account::getBalanceAttribute()}), so both
 * kinds are counted the same way once written and nothing has to know which is
 * which to add them up.
 */
class AccountService
{
    public function __construct(
        private ReferenceService $references,
        private FormattingService $format,
    ) {}

    /* ====================================================================
     | Accounts
     ==================================================================== */

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Account
    {
        return DB::transaction(function () use ($data) {
            $account = new Account([
                'name' => $data['name'],
                'account_number' => $data['account_number'] ?? null,
                'account_type_id' => $data['account_type_id'] ?? null,
                'account_type' => $data['account_type'] ?? 'saving_current',
                'note' => $data['note'] ?? null,
            ]);

            $account->business_id = Tenancy::id();
            $account->created_by = $data['created_by'] ?? auth()->id();
            $account->save();

            $opening = $this->format->numUf($data['opening_balance'] ?? 0);

            if (abs($opening) > 0.0001) {
                $this->post($account, [
                    // A negative opening balance is an overdraft, not an error.
                    'type' => $opening > 0 ? 'credit' : 'debit',
                    'sub_type' => 'opening_balance',
                    'amount' => abs($opening),
                    'operation_date' => $data['opening_balance_date'] ?? now(),
                    'note' => __('lang_v1.opening_balance'),
                    // The same author as the account itself. Without this the
                    // entry falls back to `auth()->id()` and a caller with no
                    // authenticated user — a seeder, a console command — writes
                    // null into a not-null column halfway through the call.
                    'created_by' => $account->created_by,
                ]);
            }

            return $account;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Account $account, array $data): Account
    {
        $account->fill([
            'name' => $data['name'],
            'account_number' => $data['account_number'] ?? null,
            'account_type_id' => $data['account_type_id'] ?? null,
            'account_type' => $data['account_type'] ?? $account->account_type,
            'note' => $data['note'] ?? null,
        ]);

        $account->save();

        return $account;
    }

    /**
     * Close an account, or reopen it.
     *
     * Closing is not deleting: the balance and every movement stay readable, the
     * account simply stops appearing in pickers. A cash account that held real
     * money last year cannot be made to have never existed.
     */
    public function setClosed(Account $account, bool $closed): Account
    {
        $account->is_closed = $closed;
        $account->save();

        return $account;
    }

    /* ====================================================================
     | Movements
     ==================================================================== */

    /**
     * @param  array<string, mixed>  $data
     */
    public function deposit(Account $account, array $data): AccountTransaction
    {
        return $this->post($account, $data + ['type' => 'credit', 'sub_type' => 'deposit']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function withdraw(Account $account, array $data): AccountTransaction
    {
        return $this->post($account, $data + ['type' => 'debit', 'sub_type' => 'deposit']);
    }

    /**
     * Move money between two accounts.
     *
     * Written as two rows, not one signed row: each account has to be able to
     * show the movement in its own ledger, and each has to add up on its own.
     * `transfer_transaction_id` is set both ways so deleting either leg finds
     * the other.
     *
     * @param  array<string, mixed>  $data
     * @return array{from: AccountTransaction, to: AccountTransaction}
     */
    public function transfer(Account $from, Account $to, array $data): array
    {
        if ($from->id === $to->id) {
            throw new \InvalidArgumentException(__('lang_v1.same_account_transfer'));
        }

        foreach ([$from, $to] as $account) {
            if ($account->is_closed) {
                throw new \RuntimeException(__('lang_v1.account_is_closed'));
            }
        }

        return DB::transaction(function () use ($from, $to, $data) {
            $reference = ($data['reff_no'] ?? null) ?: $this->references->generate('transfer');

            $out = $this->post($from, $data + [
                'type' => 'debit',
                'sub_type' => 'fund_transfer',
                'reff_no' => $reference,
            ]);

            $in = $this->post($to, $data + [
                'type' => 'credit',
                'sub_type' => 'fund_transfer',
                'reff_no' => $reference,
                'transfer_transaction_id' => $out->id,
            ]);

            $out->transfer_transaction_id = $in->id;
            $out->save();

            return ['from' => $out, 'to' => $in];
        });
    }

    /**
     * Correct a direct movement.
     *
     * Mirrored rows are refused for the same reason they cannot be deleted here.
     * A transfer's amount and date propagate to the other leg: the two rows are
     * one event, and letting them drift would make two accounts disagree about
     * how much moved between them.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateTransaction(AccountTransaction $entry, array $data): AccountTransaction
    {
        if (! empty($entry->transaction_payment_id)) {
            throw new \RuntimeException(__('lang_v1.cannot_edit_linked_transaction'));
        }

        $amount = $this->format->numUf($data['amount'] ?? $entry->amount);

        if ($amount <= 0) {
            throw new \InvalidArgumentException(__('lang_v1.payment_amount_must_be_positive'));
        }

        return DB::transaction(function () use ($entry, $data, $amount) {
            $attributes = [
                'amount' => $amount,
                'operation_date' => $this->format->ufDate($data['operation_date'] ?? null, true)
                    ?? $entry->operation_date,
                'note' => $data['note'] ?? $entry->note,
            ];

            if (array_key_exists('reff_no', $data)) {
                $attributes['reff_no'] = $data['reff_no'] ?: null;
            }

            $entry->fill($attributes);
            $entry->save();

            if (! empty($entry->transfer_transaction_id)) {
                AccountTransaction::where('id', $entry->transfer_transaction_id)
                    ->update([
                        'amount' => $attributes['amount'],
                        'operation_date' => $attributes['operation_date'],
                        'note' => $attributes['note'],
                    ]);
            }

            return $entry;
        });
    }

    /**
     * Delete a direct movement.
     *
     * A mirrored row is refused: it belongs to a payment, and removing it here
     * would leave the account disagreeing with the invoice it came from. The
     * caller is told to delete the payment instead.
     */
    public function deleteTransaction(AccountTransaction $entry): void
    {
        if (! empty($entry->transaction_payment_id)) {
            throw new \RuntimeException(__('lang_v1.cannot_delete_linked_transaction'));
        }

        DB::transaction(function () use ($entry) {
            // Both legs of a transfer go together or the two accounts disagree.
            if (! empty($entry->transfer_transaction_id)) {
                AccountTransaction::where('id', $entry->transfer_transaction_id)
                    ->update(['transfer_transaction_id' => null]);

                AccountTransaction::find($entry->transfer_transaction_id)?->delete();
            }

            $entry->delete();
        });
    }

    /* ====================================================================
     | Reading
     ==================================================================== */

    /**
     * Totals for one account: what came in, what went out, and the balance.
     *
     * @return array{in: float, out: float, balance: float}
     */
    public function totalsFor(Account $account): array
    {
        $rows = AccountTransaction::where('account_id', $account->id)
            ->selectRaw('type, SUM(amount) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $in = (float) ($rows['credit'] ?? 0);
        $out = (float) ($rows['debit'] ?? 0);

        return ['in' => $in, 'out' => $out, 'balance' => round($in - $out, 4)];
    }

    /**
     * Balances for many accounts in one query, keyed by account id.
     *
     * The listing shows a balance per row; the accessor on the model would run
     * two queries per account, so the list asks once for all of them.
     *
     * @param  array<int, int>  $accountIds
     * @return array<int, float>
     */
    public function balancesFor(array $accountIds): array
    {
        if (empty($accountIds)) {
            return [];
        }

        return AccountTransaction::whereIn('account_id', $accountIds)
            ->selectRaw("account_id, SUM(CASE WHEN type = 'credit' THEN amount ELSE -amount END) as balance")
            ->groupBy('account_id')
            ->pluck('balance', 'account_id')
            ->map(fn ($balance) => round((float) $balance, 4))
            ->all();
    }

    /* ====================================================================
     | Internals
     ==================================================================== */

    /**
     * Write one movement.
     *
     * @param  array<string, mixed>  $data
     */
    protected function post(Account $account, array $data): AccountTransaction
    {
        $amount = $this->format->numUf($data['amount'] ?? 0);

        if ($amount <= 0) {
            throw new \InvalidArgumentException(__('lang_v1.payment_amount_must_be_positive'));
        }

        return AccountTransaction::create([
            'account_id' => $account->id,
            'type' => $data['type'],
            'sub_type' => $data['sub_type'] ?? null,
            'amount' => $amount,
            'reff_no' => $data['reff_no'] ?? null,
            'operation_date' => $this->format->ufDate($data['operation_date'] ?? null, true) ?? now(),
            'created_by' => $data['created_by'] ?? auth()->id(),
            'transfer_transaction_id' => $data['transfer_transaction_id'] ?? null,
            'note' => $data['note'] ?? null,
        ]);
    }
}
