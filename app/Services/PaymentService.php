<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Transaction;
use App\Models\TransactionPayment;
use App\Support\TransactionTypes;
use Illuminate\Support\Facades\DB;

/**
 * Payments and payment status.
 *
 * Rule: `transactions.payment_status` is **derived**, never set by hand. Any
 * code path that touches payments finishes by calling
 * {@see refreshPaymentStatus()}, which recomputes it from the payment rows.
 * The original project set it in a dozen places and shipped repair commands
 * for the resulting drift (§15.3 of the audit).
 */
class PaymentService
{
    public function __construct(
        private ReferenceService $references,
        private FormattingService $format,
    ) {}

    /* ====================================================================
     | Status
     ==================================================================== */

    /**
     * Total settled against a document (payments minus change/refunds).
     */
    public function amountPaid(Transaction $transaction): float
    {
        $paid = (float) $transaction->payment_lines()->where('is_return', 0)->sum('amount');
        $returned = (float) $transaction->payment_lines()->where('is_return', 1)->sum('amount');

        return round($paid - $returned, 4);
    }

    public function amountDue(Transaction $transaction): float
    {
        return round((float) $transaction->final_total - $this->amountPaid($transaction), 4);
    }

    /**
     * Derive and persist `payment_status` from the payment rows.
     *
     * Tolerance of 0.0001 absorbs decimal(22,4) rounding so a fully-settled
     * invoice is never left showing a fractional due.
     */
    public function refreshPaymentStatus(Transaction $transaction): string
    {
        $total = round((float) $transaction->final_total, 4);
        $paid = $this->amountPaid($transaction);

        $status = match (true) {
            $paid <= 0.0001 => TransactionTypes::DUE,
            $paid >= $total - 0.0001 => TransactionTypes::PAID,
            default => TransactionTypes::PARTIAL,
        };

        // Zero-value documents (e.g. a 100% discounted sale) count as paid.
        if ($total <= 0.0001) {
            $status = TransactionTypes::PAID;
        }

        if ($transaction->payment_status !== $status) {
            $transaction->payment_status = $status;
            $transaction->save();
        }

        return $status;
    }

    /* ====================================================================
     | Recording payments
     ==================================================================== */

    /**
     * Add a payment to a document.
     *
     * @param  array<string, mixed>  $data
     */
    public function addPayment(Transaction $transaction, array $data): TransactionPayment
    {
        $this->assertInTransaction();

        $amount = $this->format->numUf($data['amount'] ?? 0);

        if ($amount <= 0) {
            throw new \InvalidArgumentException(__('lang_v1.payment_amount_must_be_positive'));
        }

        $payment = new TransactionPayment([
            'transaction_id' => $transaction->id,
            'business_id' => $transaction->business_id,
            'amount' => $amount,
            'method' => $data['method'] ?? 'cash',
            'payment_type' => $this->paymentDirection($transaction),
            'paid_on' => $data['paid_on'] ?? now(),
            'created_by' => $data['created_by'] ?? auth()->id(),
            'payment_for' => $transaction->contact_id,
            'account_id' => $data['account_id'] ?? null,
            'is_return' => $data['is_return'] ?? 0,
            'note' => $data['note'] ?? null,
            'card_transaction_number' => $data['card_transaction_number'] ?? null,
            'card_number' => $data['card_number'] ?? null,
            'card_type' => $data['card_type'] ?? null,
            'card_holder_name' => $data['card_holder_name'] ?? null,
            'card_month' => $data['card_month'] ?? null,
            'card_year' => $data['card_year'] ?? null,
            'card_security' => $data['card_security'] ?? null,
            'cheque_number' => $data['cheque_number'] ?? null,
            'bank_account_number' => $data['bank_account_number'] ?? null,
            'transaction_no' => $data['transaction_no'] ?? null,
            'document' => $data['document'] ?? null,
            'parent_id' => $data['parent_id'] ?? null,
            'is_advance' => $data['is_advance'] ?? 0,
        ]);

        $payment->payment_ref_no = $data['payment_ref_no']
            ?? $this->references->generate($this->refTypeFor($transaction));

        $payment->save();

        $this->refreshPaymentStatus($transaction);

        event(new \App\Events\TransactionPaymentAdded($payment));

        return $payment;
    }

    /**
     * Update an existing payment.
     *
     * @param  array<string, mixed>  $data
     */
    public function updatePayment(TransactionPayment $payment, array $data): TransactionPayment
    {
        $this->assertInTransaction();

        if (array_key_exists('amount', $data)) {
            $amount = $this->format->numUf($data['amount']);

            if ($amount <= 0) {
                throw new \InvalidArgumentException(__('lang_v1.payment_amount_must_be_positive'));
            }

            $payment->amount = $amount;
        }

        foreach ([
            'method', 'paid_on', 'account_id', 'note', 'card_transaction_number',
            'card_number', 'card_type', 'card_holder_name', 'card_month',
            'card_year', 'card_security', 'cheque_number', 'bank_account_number',
            'transaction_no', 'document',
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $payment->{$field} = $data[$field];
            }
        }

        $payment->save();

        if (! empty($payment->transaction)) {
            $this->refreshPaymentStatus($payment->transaction);
        }

        event(new \App\Events\TransactionPaymentUpdated($payment));

        return $payment;
    }

    /**
     * Delete a payment, cascading to its child rows.
     */
    public function deletePayment(TransactionPayment $payment): void
    {
        $this->assertInTransaction();

        $transaction = $payment->transaction;

        // A parent payment (contact-due settlement) owns child rows.
        foreach ($payment->child_payments as $child) {
            $childTransaction = $child->transaction;
            event(new \App\Events\TransactionPaymentDeleted($child));
            $child->delete();

            if (! empty($childTransaction)) {
                $this->refreshPaymentStatus($childTransaction);
            }
        }

        event(new \App\Events\TransactionPaymentDeleted($payment));
        $payment->delete();

        if (! empty($transaction)) {
            $this->refreshPaymentStatus($transaction);
        }
    }

    /* ====================================================================
     | Contact-level settlement
     ==================================================================== */

    /**
     * Settle a contact's outstanding balance across their open documents,
     * oldest first.
     *
     * Creates one parent payment row plus a child row per document it covers,
     * mirroring the documented `parent_id` structure.
     *
     * @param  array<string, mixed>  $data
     * @return array{parent: TransactionPayment, children: array<int, TransactionPayment>, unallocated: float}
     */
    public function payContactDue(Contact $contact, array $data): array
    {
        $this->assertInTransaction();

        $amount = $this->format->numUf($data['amount'] ?? 0);

        if ($amount <= 0) {
            throw new \InvalidArgumentException(__('lang_v1.payment_amount_must_be_positive'));
        }

        $dueType = $data['due_type'] ?? 'sell';

        $parent = new TransactionPayment([
            'business_id' => $contact->business_id,
            'transaction_id' => null,
            'amount' => $amount,
            'method' => $data['method'] ?? 'cash',
            'paid_on' => $data['paid_on'] ?? now(),
            'created_by' => $data['created_by'] ?? auth()->id(),
            'payment_for' => $contact->id,
            'account_id' => $data['account_id'] ?? null,
            'note' => $data['note'] ?? null,
            'payment_type' => $dueType === 'sell' ? 'credit' : 'debit',
            'payment_ref_no' => $this->references->generate(
                $dueType === 'sell' ? 'payment' : 'purchase_payment'
            ),
        ]);
        $parent->save();

        /*
         * The parent is the movement; the children are allocations of it.
         *
         * So the parent is what the mirrors (a bank account, a cash drawer) are
         * told about, and they skip rows carrying a `parent_id`. Mirroring the
         * children instead would under-report by exactly the remainder that goes
         * to advance balance below — a customer handing over 500 against 300 of
         * invoices put 500 in the drawer, not 300, and the cashier would be 200
         * over at close with nothing to point at.
         */
        event(new \App\Events\TransactionPaymentAdded($parent));

        $remaining = $amount;
        $children = [];

        foreach ($this->openDocumentsFor($contact, $dueType) as $document) {
            if ($remaining <= 0.0001) {
                break;
            }

            $due = $this->amountDue($document);

            if ($due <= 0.0001) {
                continue;
            }

            $allocation = min($due, $remaining);

            $child = new TransactionPayment([
                'transaction_id' => $document->id,
                'business_id' => $document->business_id,
                'amount' => $allocation,
                'method' => $parent->method,
                'paid_on' => $parent->paid_on,
                'created_by' => $parent->created_by,
                'payment_for' => $contact->id,
                'account_id' => $parent->account_id,
                'parent_id' => $parent->id,
                'payment_type' => $parent->payment_type,
                'payment_ref_no' => $parent->payment_ref_no,
            ]);
            $child->save();

            $this->refreshPaymentStatus($document);

            event(new \App\Events\TransactionPaymentAdded($child));

            $children[] = $child;
            $remaining = round($remaining - $allocation, 4);
        }

        // Anything left over becomes advance balance on the contact.
        if ($remaining > 0.0001) {
            $this->addAdvanceBalance($contact, $remaining);
        }

        return [
            'parent' => $parent,
            'children' => $children,
            'unallocated' => round($remaining, 4),
        ];
    }

    /**
     * Increase a contact's advance (prepaid) balance.
     */
    public function addAdvanceBalance(Contact $contact, float $amount): void
    {
        $this->assertInTransaction();

        $contact->balance = round((float) $contact->balance + $amount, 4);
        $contact->save();
    }

    /**
     * Spend a contact's advance balance against a document.
     */
    public function useAdvanceBalance(Contact $contact, Transaction $transaction, float $amount): TransactionPayment
    {
        $this->assertInTransaction();

        $available = round((float) $contact->balance, 4);

        if ($amount > $available + 0.0001) {
            throw new \RuntimeException(__('lang_v1.insufficient_advance_balance', [
                'available' => $this->format->currencyF($available),
            ]));
        }

        $payment = $this->addPayment($transaction, [
            'amount' => $amount,
            'method' => 'advance',
            'is_advance' => 0,
        ]);

        $contact->balance = round($available - $amount, 4);
        $contact->save();

        return $payment;
    }

    /* ====================================================================
     | Internals
     ==================================================================== */

    /**
     * Open documents for a contact, oldest first.
     *
     * @return \Illuminate\Support\Collection<int, Transaction>
     */
    protected function openDocumentsFor(Contact $contact, string $dueType)
    {
        $types = $dueType === 'sell'
            ? [TransactionTypes::SELL, TransactionTypes::OPENING_BALANCE]
            : [TransactionTypes::PURCHASE, TransactionTypes::OPENING_BALANCE];

        return Transaction::where('contact_id', $contact->id)
            ->whereIn('type', $types)
            ->whereIn('payment_status', [TransactionTypes::DUE, TransactionTypes::PARTIAL])
            ->whereIn('status', [
                TransactionTypes::STATUS_FINAL,
                TransactionTypes::STATUS_RECEIVED,
                TransactionTypes::STATUS_PENDING,
            ])
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Money coming in is a credit; money going out is a debit.
     */
    protected function paymentDirection(Transaction $transaction): string
    {
        return in_array($transaction->type, [
            TransactionTypes::SELL,
            TransactionTypes::SELL_RETURN,
            TransactionTypes::SALES_ORDER,
        ], true) ? 'credit' : 'debit';
    }

    protected function refTypeFor(Transaction $transaction): string
    {
        return match ($transaction->type) {
            TransactionTypes::PURCHASE, TransactionTypes::PURCHASE_RETURN => 'purchase_payment',
            TransactionTypes::EXPENSE, TransactionTypes::EXPENSE_REFUND => 'expense_payment',
            default => 'payment',
        };
    }

    protected function assertInTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException(
                static::class.': payment changes must run inside a database transaction.'
            );
        }
    }
}
