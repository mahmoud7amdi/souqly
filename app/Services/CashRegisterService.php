<?php

namespace App\Services;

use App\Models\CashDenomination;
use App\Models\CashRegister;
use App\Models\CashRegisterTransaction;
use App\Models\TransactionPayment;
use App\Support\Tenancy;
use App\Support\TransactionTypes;
use Illuminate\Support\Facades\DB;

/**
 * The cashier's till session.
 *
 * A register is a shift, not an account: it answers "what went through this
 * drawer between the moment I opened it and the moment I counted it", which is
 * a different question from "what is the balance of the bank account". So it
 * keeps its own rows in `cash_register_transactions` rather than reusing
 * `account_transactions` — the two are reconciled by a human at close time, and
 * a discrepancy between them is the whole point of counting.
 *
 * One open register per user at a time. That is not a technical limit; it is
 * what makes the count mean anything.
 */
class CashRegisterService
{
    public function __construct(private FormattingService $format) {}

    /* ====================================================================
     | Session lifecycle
     ==================================================================== */

    /**
     * The user's open register, if they have one.
     *
     * `$businessId` is for callers that have the tenant in hand but may not be
     * running inside a bound request — a queued job, a console command. HTTP
     * always has one bound, so it defaults to the ambient tenant.
     */
    public function currentFor(?int $userId = null, ?int $businessId = null): ?CashRegister
    {
        return CashRegister::where('business_id', $businessId ?? Tenancy::id())
            ->where('user_id', $userId ?? auth()->id())
            ->where('status', 'open')
            ->latest('id')
            ->first();
    }

    /**
     * Open a register with an optional float in the drawer.
     *
     * @param  array<string, mixed>  $data
     */
    public function open(array $data): CashRegister
    {
        if ($this->currentFor($data['user_id'] ?? auth()->id())) {
            throw new \RuntimeException(__('lang_v1.register_already_open'));
        }

        return DB::transaction(function () use ($data) {
            $register = CashRegister::create([
                'business_id' => Tenancy::id(),
                'location_id' => $data['location_id'],
                'user_id' => $data['user_id'] ?? auth()->id(),
                'status' => 'open',
            ]);

            $opening = $this->format->numUf($data['opening_amount'] ?? 0);

            /*
             * The opening float is an `initial` row rather than a column, so the
             * drawer's cash is the sum of its rows and nothing has to remember to
             * add the float back in. A zero float writes no row: an empty drawer
             * is the normal case and does not need a ledger entry to say so.
             */
            if ($opening > 0.0001) {
                $this->post($register, [
                    'amount' => $opening,
                    'pay_method' => 'cash',
                    'type' => 'credit',
                    'transaction_type' => 'initial',
                ]);
            }

            return $register;
        });
    }

    /**
     * Count the drawer and close the session.
     *
     * @param  array<string, mixed>  $data
     */
    public function close(CashRegister $register, array $data): CashRegister
    {
        if (! $register->isOpen()) {
            throw new \RuntimeException(__('lang_v1.register_closed'));
        }

        return DB::transaction(function () use ($register, $data) {
            $denominations = $this->normaliseDenominations($data['denominations'] ?? []);

            /*
             * A counted breakdown wins over a typed total: if the cashier bothered
             * to count the notes, that count IS the closing amount, and asking
             * them to also type the sum invites the two to disagree.
             */
            $counted = $this->denominationTotal($denominations);

            $register->fill([
                'status' => 'close',
                'closed_at' => now(),
                'closing_amount' => $counted > 0.0001
                    ? $counted
                    : $this->format->numUf($data['closing_amount'] ?? 0),
                'total_card_slips' => (int) ($data['total_card_slips'] ?? 0),
                'total_cheques' => (int) ($data['total_cheques'] ?? 0),
                'closing_note' => $data['closing_note'] ?? null,
                'denominations' => $denominations ?: null,
            ]);

            $register->save();

            $this->syncDenominationRows($register, $denominations);

            return $register;
        });
    }

    /* ====================================================================
     | Recording activity
     ==================================================================== */

    /**
     * Mirror a payment into the cashier's open drawer.
     *
     * The drawer follows **payment rows**, not sale documents. A sale is not the
     * moment money moves — a counter sale, a customer settling last week's
     * invoice at the till, and a refund handed back are three different
     * movements, and only the payment row knows which one happened. Hooking the
     * sale instead would miss the second and the third entirely, and the close
     * screen's variance is only worth showing if it counts all three.
     *
     * Silent in every case where no cash crossed a drawer: no register open (a
     * back-office sale is a real sale that simply did not pass through one), an
     * advance balance being spent, or an allocation row belonging to a parent
     * settlement. Refusing instead of skipping would make the register a gate on
     * selling rather than a record of it.
     */
    public function recordPayment(TransactionPayment $payment): void
    {
        if (! $this->isDrawerMovement($payment)) {
            return;
        }

        $register = $this->registerFor($payment);

        if (empty($register)) {
            return;
        }

        $amount = $this->format->numUf($payment->amount);

        if ($amount <= 0) {
            return;
        }

        $outgoing = $this->isOutgoing($payment);

        $this->post($register, [
            'amount' => $amount,
            'pay_method' => $payment->method ?: 'cash',
            'type' => $outgoing ? 'debit' : 'credit',
            'transaction_type' => $outgoing ? 'refund' : 'sell',
            'transaction_id' => $payment->transaction_id,
            'transaction_payment_id' => $payment->id,
        ]);
    }

    /**
     * Keep the drawer entry in step when a payment is corrected.
     *
     * Only ever updates an entry that already exists. A payment that did not
     * pass through a drawer does not acquire one by being edited later — the
     * shift it would have belonged to has usually been counted and closed.
     */
    public function syncPayment(TransactionPayment $payment): void
    {
        $entries = CashRegisterTransaction::where('transaction_payment_id', $payment->id)->get();

        if ($entries->isEmpty()) {
            return;
        }

        // Edited into something that never belonged in a drawer (an advance, say).
        if (! $this->isDrawerMovement($payment)) {
            $entries->each->delete();

            return;
        }

        $outgoing = $this->isOutgoing($payment);

        foreach ($entries as $entry) {
            $entry->amount = $this->format->numUf($payment->amount);
            $entry->pay_method = $payment->method ?: 'cash';
            $entry->type = $outgoing ? 'debit' : 'credit';
            $entry->transaction_type = $outgoing ? 'refund' : 'sell';
            $entry->save();
        }
    }

    /**
     * Drop the drawer entry when a payment is deleted.
     *
     * A mis-keyed payment entered and deleted within the same shift must leave
     * no trace, or the close is short by the mistake and the cashier is asked to
     * explain a difference that was already corrected properly.
     */
    public function removePayment(TransactionPayment $payment): void
    {
        CashRegisterTransaction::where('transaction_payment_id', $payment->id)
            ->get()
            ->each
            ->delete();
    }

    /* ====================================================================
     | Reading
     ==================================================================== */

    /**
     * Everything the close screen and the register detail screen need.
     *
     * @return array{
     *     opening: float, by_method: array<string, float>, refunds: float,
     *     cash_in_hand: float, total_collected: float, sales_count: int,
     *     transfers: float
     * }
     */
    public function summary(CashRegister $register): array
    {
        $rows = CashRegisterTransaction::where('cash_register_id', $register->id)
            ->selectRaw('pay_method, type, transaction_type, SUM(amount) as total')
            ->groupBy('pay_method', 'type', 'transaction_type')
            ->get();

        $opening = 0.0;
        $refunds = 0.0;
        $transfers = 0.0;
        $byMethod = [];

        foreach ($rows as $row) {
            $total = (float) $row->total;
            $signed = $row->type === 'credit' ? $total : -$total;

            if ($row->transaction_type === 'initial') {
                $opening += $signed;

                continue;
            }

            if ($row->transaction_type === 'transfer') {
                $transfers += $signed;

                continue;
            }

            if ($row->transaction_type === 'refund') {
                $refunds += $total;
            }

            $byMethod[$row->pay_method] = round(($byMethod[$row->pay_method] ?? 0) + $signed, 4);
        }

        $salesCount = CashRegisterTransaction::where('cash_register_id', $register->id)
            ->where('transaction_type', 'sell')
            ->distinct()
            ->count('transaction_id');

        return [
            'opening' => round($opening, 4),
            'by_method' => array_map(fn ($v) => round($v, 4), $byMethod),
            'refunds' => round($refunds, 4),
            // Only cash is physically in the drawer; a card slip is not.
            'cash_in_hand' => round($opening + ($byMethod['cash'] ?? 0), 4),
            'total_collected' => round(array_sum($byMethod), 4),
            'sales_count' => $salesCount,
            'transfers' => round($transfers, 4),
        ];
    }

    /* ====================================================================
     | Internals
     ==================================================================== */

    /**
     * Whether this payment is cash (or card, or a cheque) crossing a drawer.
     *
     * Three exclusions, each for its own reason:
     *
     * - `advance` moves the contact's balance, not the till.
     * - A row with a `parent_id` is one *allocation* of a settlement, not a
     *   movement. The money moved once, on the parent; counting the children
     *   would post the same notes twice and would silently lose any remainder
     *   that went to advance balance instead of to an invoice.
     * - Only sell-side documents. `transaction_type` is a four-value enum
     *   (`initial|sell|transfer|refund`) with no term for money paid out to a
     *   supplier or for an expense, and inventing one is a feature rather than
     *   part of this wiring — see {@see recordableTypes()}. A parentless payment
     *   with no document is a settlement, and its own `payment_type` says which
     *   side it belongs to.
     */
    protected function isDrawerMovement(TransactionPayment $payment): bool
    {
        if ($payment->method === 'advance' || ! empty($payment->parent_id)) {
            return false;
        }

        $type = $payment->transaction?->type;

        return $type === null
            ? $payment->payment_type === 'credit'
            : in_array($type, static::recordableTypes(), true);
    }

    /**
     * Whether the money left the drawer.
     *
     * A sell return pays the customer back, and `is_return` on an ordinary sale
     * is change handed over — so either one alone means money out, and both
     * together (change on a refund) means money in. Mirrors
     * {@see \App\Listeners\AddAccountTransaction::direction()}, which asks the
     * same question of a bank account.
     */
    protected function isOutgoing(TransactionPayment $payment): bool
    {
        $outgoing = $payment->transaction?->type === TransactionTypes::SELL_RETURN;

        return $payment->is_return ? ! $outgoing : $outgoing;
    }

    /**
     * The drawer a payment belongs in: the open register of whoever took it.
     *
     * Scoped by the payment's own `business_id` rather than the ambient tenant,
     * so a queued job or a console command records against the right business
     * even with no tenancy bound.
     *
     * The register is the one open *now*, not the one open when `paid_on` says
     * the money changed hands. That is deliberate: a payment backdated during a
     * shift is still cash sitting in that shift's drawer, and it is that drawer
     * which is about to be counted.
     */
    protected function registerFor(TransactionPayment $payment): ?CashRegister
    {
        return empty($payment->created_by)
            ? null
            : $this->currentFor((int) $payment->created_by, $payment->business_id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function post(CashRegister $register, array $data): CashRegisterTransaction
    {
        return CashRegisterTransaction::create([
            'cash_register_id' => $register->id,
            'amount' => $this->format->numUf($data['amount'] ?? 0),
            'pay_method' => $data['pay_method'] ?? 'cash',
            'type' => $data['type'] ?? 'credit',
            'transaction_type' => $data['transaction_type'] ?? 'sell',
            'transaction_id' => $data['transaction_id'] ?? null,
            'transaction_payment_id' => $data['transaction_payment_id'] ?? null,
        ]);
    }

    /**
     * Drop empty rows and coerce the posted grid into `value => count`.
     *
     * @param  array<int|string, mixed>  $denominations
     * @return array<string, int>
     */
    protected function normaliseDenominations(array $denominations): array
    {
        $clean = [];

        foreach ($denominations as $value => $count) {
            $value = (float) (is_array($count) ? ($count['amount'] ?? $value) : $value);
            $count = (int) (is_array($count) ? ($count['count'] ?? 0) : $count);

            if ($value <= 0 || $count <= 0) {
                continue;
            }

            $key = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
            $clean[$key] = ($clean[$key] ?? 0) + $count;
        }

        return $clean;
    }

    /**
     * @param  array<string, int>  $denominations
     */
    protected function denominationTotal(array $denominations): float
    {
        $total = 0.0;

        foreach ($denominations as $value => $count) {
            $total += (float) $value * (int) $count;
        }

        return round($total, 4);
    }

    /**
     * Mirror the breakdown into `cash_denominations`.
     *
     * The JSON column is what the close screen reads back; these rows are what
     * a denomination report groups across sessions. Kept in step here so no
     * screen has to know both shapes.
     *
     * @param  array<string, int>  $denominations
     */
    protected function syncDenominationRows(CashRegister $register, array $denominations): void
    {
        CashDenomination::where('model_type', CashRegister::class)
            ->where('model_id', $register->id)
            ->delete();

        foreach ($denominations as $value => $count) {
            CashDenomination::create([
                'business_id' => $register->business_id,
                'amount' => (float) $value,
                'total_count' => (int) $count,
                'model_type' => CashRegister::class,
                'model_id' => $register->id,
            ]);
        }
    }

    /**
     * Types of document that belong in a register at all.
     *
     * Sell side only, and that is a limitation rather than a principle: cash
     * handed to a supplier or spent on an expense really does leave the drawer,
     * but `cash_register_transactions.transaction_type` is a four-value enum
     * with no term for it. Naming that movement means extending the enum, the
     * summary, the session screen and the close rail — a feature, not a
     * side-effect of wiring payments up. Until then such a payment leaves the
     * shift short, and the close screen's note field is where the cashier says
     * so. Logged in NOTES.md §8.4.
     *
     * @return array<int, string>
     */
    public static function recordableTypes(): array
    {
        return [TransactionTypes::SELL, TransactionTypes::SELL_RETURN];
    }
}
