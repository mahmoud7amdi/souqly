<?php

namespace App\Services;

use App\Events\ExpenseCreatedOrModified;
use App\Models\TaxRate;
use App\Models\Transaction;
use App\Support\TransactionTypes;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Expenses and expense refunds.
 *
 * An expense is a `transactions` row like any other — same table, same payment
 * rows, same derived `payment_status`. What it has instead of lines is a single
 * amount, so there is no line service and no stock path: this class exists to
 * keep the arithmetic (`total_before_tax` + tax = `final_total`), the reference
 * number and the recurring schedule in one place rather than in a controller.
 *
 * Tax convention: the amount entered is **before** tax and the selected rate is
 * added to it, matching the `exclusive` default used for purchase lines. An
 * expense receipt that shows a gross total is entered by picking no tax — the
 * alternative (treating the entry as inclusive and deriving the net) silently
 * changes what the user typed, which is worse than asking them to choose.
 */
class ExpenseService
{
    public function __construct(
        private ReferenceService $references,
        private PaymentService $payments,
        private FormattingService $format,
    ) {}

    /* ====================================================================
     | Writing
     ==================================================================== */

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $paymentLines
     */
    public function create(array $data, array $paymentLines = []): Transaction
    {
        return DB::transaction(function () use ($data, $paymentLines) {
            $expense = new Transaction($this->documentAttributes($data));

            $expense->business_id = Tenancy::id();
            $expense->type = $this->typeFor($data);
            $expense->status = TransactionTypes::STATUS_FINAL;
            $expense->created_by = $data['created_by'] ?? auth()->id();
            $expense->ref_no = ($data['ref_no'] ?? null) ?: $this->references->generate('expense');
            $expense->save();

            foreach ($paymentLines as $line) {
                $this->payments->addPayment($expense, $line + ['created_by' => $expense->created_by]);
            }

            // Even with no payment rows: an expense of zero counts as paid, and
            // a due one must not be left with a null status.
            $this->payments->refreshPaymentStatus($expense);

            event(new ExpenseCreatedOrModified($expense));

            return $expense;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Transaction $expense, array $data): Transaction
    {
        return DB::transaction(function () use ($expense, $data) {
            $expense->fill($this->documentAttributes($data));
            $expense->type = $this->typeFor($data);
            $expense->save();

            // The total may have moved, so what was "paid" may now be partial.
            $this->payments->refreshPaymentStatus($expense);

            event(new ExpenseCreatedOrModified($expense));

            return $expense;
        });
    }

    /**
     * Delete an expense and everything hanging off it.
     *
     * Payments go through PaymentService rather than an `onDelete('cascade')`,
     * so the account-transaction listeners fire and the mirrored rows on the
     * payment account go with them.
     */
    public function delete(Transaction $expense): void
    {
        DB::transaction(function () use ($expense) {
            foreach ($expense->payment_lines as $payment) {
                $this->payments->deletePayment($payment);
            }

            // Recurring children outlive their parent as ordinary expenses:
            // they are real money that was really spent. Only the link goes.
            Transaction::where('recur_parent_id', $expense->id)
                ->update(['recur_parent_id' => null]);

            $expense->delete();
        });
    }

    /* ====================================================================
     | Recurring
     ==================================================================== */

    /**
     * Generate every recurring expense that has come due.
     *
     * Returns how many were created. Called by the scheduler; safe to run more
     * than once a day because the next due date is derived from the last child
     * that exists, not from "today".
     *
     * @return int
     */
    public function generateDueRecurring(): int
    {
        $parents = Transaction::whereIn('type', [TransactionTypes::EXPENSE, TransactionTypes::EXPENSE_REFUND])
            ->where('is_recurring', 1)
            ->whereNull('recur_stopped_on')
            ->get();

        $created = 0;

        foreach ($parents as $parent) {
            $children = Transaction::where('recur_parent_id', $parent->id)->count();

            if ($parent->recur_repetitions && $children >= (int) $parent->recur_repetitions) {
                continue;
            }

            $lastDate = Transaction::where('recur_parent_id', $parent->id)
                ->max('transaction_date') ?? $parent->transaction_date;

            $due = $this->nextOccurrence(Carbon::parse($lastDate), $parent);

            if ($due->isFuture()) {
                continue;
            }

            $created += (int) (bool) $this->createOccurrence($parent, $due);
        }

        return $created;
    }

    protected function nextOccurrence(Carbon $from, Transaction $parent): Carbon
    {
        $interval = max(1, (int) $parent->recur_interval);

        return match ($parent->recur_interval_type) {
            'months' => $from->copy()->addMonths($interval),
            'years' => $from->copy()->addYears($interval),
            default => $from->copy()->addDays($interval),
        };
    }

    protected function createOccurrence(Transaction $parent, Carbon $on): Transaction
    {
        return DB::transaction(function () use ($parent, $on) {
            $child = $parent->replicate([
                'is_recurring', 'recur_interval', 'recur_interval_type',
                'recur_repetitions', 'recur_stopped_on', 'payment_status',
            ]);

            $child->recur_parent_id = $parent->id;
            $child->is_recurring = 0;
            $child->transaction_date = $on;
            $child->ref_no = $this->references->generate('expense');
            $child->save();

            $this->payments->refreshPaymentStatus($child);

            event(new ExpenseCreatedOrModified($child));

            return $child;
        });
    }

    /* ====================================================================
     | Internals
     ==================================================================== */

    /**
     * Normalise a posted form into column values.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function documentAttributes(array $data): array
    {
        $net = $this->format->numUf($data['total_before_tax'] ?? 0);
        $taxId = $data['tax_id'] ?? null;
        $taxAmount = $this->taxOn($net, $taxId);

        $attributes = [
            'location_id' => $data['location_id'],
            'expense_category_id' => $data['expense_category_id'] ?? null,
            'expense_sub_category_id' => $data['expense_sub_category_id'] ?? null,
            'expense_for' => $data['expense_for'] ?? null,
            'contact_id' => $data['contact_id'] ?? null,
            'transaction_date' => $this->format->ufDate($data['transaction_date'] ?? null, true) ?? now(),
            'tax_id' => $taxId ?: null,
            'tax_amount' => $taxAmount,
            'total_before_tax' => $net,
            'final_total' => round($net + $taxAmount, 4),
            'additional_notes' => $data['additional_notes'] ?? null,
            'document' => $data['document'] ?? null,
        ];

        return $attributes + $this->recurringAttributes($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function recurringAttributes(array $data): array
    {
        $isRecurring = ! empty($data['is_recurring']);

        return [
            'is_recurring' => $isRecurring ? 1 : 0,
            'recur_interval' => $isRecurring ? max(1, (int) ($data['recur_interval'] ?? 1)) : null,
            'recur_interval_type' => $isRecurring ? ($data['recur_interval_type'] ?? 'months') : null,
            'recur_repetitions' => $isRecurring && ! empty($data['recur_repetitions'])
                ? (int) $data['recur_repetitions']
                : null,
            'subscription_no' => $data['subscription_no'] ?? null,
        ];
    }

    protected function taxOn(float $net, mixed $taxId): float
    {
        if (empty($taxId)) {
            return 0.0;
        }

        $rate = (float) (TaxRate::find($taxId)?->amount ?? 0);

        return round($net * $rate / 100, 4);
    }

    /**
     * A refund is the same document with the sign of its meaning flipped, so it
     * is a `type`, not a boolean column.
     *
     * @param  array<string, mixed>  $data
     */
    protected function typeFor(array $data): string
    {
        return ! empty($data['is_refund'])
            ? TransactionTypes::EXPENSE_REFUND
            : TransactionTypes::EXPENSE;
    }
}
