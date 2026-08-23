<?php

namespace App\Listeners;

use App\Events\TransactionPaymentAdded;
use App\Models\AccountTransaction;
use App\Support\TransactionTypes;

/**
 * Mirrors a transaction payment onto its payment account.
 *
 * Only runs when the tenant has the `account` module enabled and the payment
 * names an account. Advance payments never touch an account — they move the
 * contact's balance instead (handled by PaymentService).
 *
 * Allocation rows are skipped: a contact settlement writes one parent row for
 * the money and a child per invoice it covered, and the money reached the bank
 * once. See {@see \App\Services\PaymentService::payContactDue()}.
 */
class AddAccountTransaction
{
    public function handle(TransactionPaymentAdded $event): void
    {
        $payment = $event->payment;

        if ($payment->method === 'advance' || empty($payment->account_id)) {
            return;
        }

        if (! empty($payment->parent_id)) {
            return;
        }

        if (! $this->accountModuleEnabled($payment->business_id)) {
            return;
        }

        AccountTransaction::create([
            'account_id' => $payment->account_id,
            'type' => $this->direction($payment),
            'amount' => $payment->amount,
            'operation_date' => $payment->paid_on ?? now(),
            'created_by' => $payment->created_by,
            'transaction_id' => $payment->transaction_id,
            'transaction_payment_id' => $payment->id,
            'reff_no' => $payment->payment_ref_no,
            'note' => $payment->note,
        ]);
    }

    /**
     * Money received into the account is a credit; money paid out a debit.
     *
     * A `is_return` payment on a sale is change given back to the customer,
     * so it reverses the direction.
     */
    protected function direction($payment): string
    {
        $transaction = $payment->transaction;

        $incoming = ! empty($transaction) && in_array($transaction->type, [
            TransactionTypes::SELL,
            TransactionTypes::SALES_ORDER,
            TransactionTypes::EXPENSE_REFUND,
        ], true);

        // Contact-due settlements carry the direction on the payment itself.
        if (empty($transaction)) {
            $incoming = $payment->payment_type === 'credit';
        }

        if ($payment->is_return) {
            $incoming = ! $incoming;
        }

        return $incoming ? 'credit' : 'debit';
    }

    protected function accountModuleEnabled(?int $businessId): bool
    {
        $enabled = session('business.enabled_modules');

        if (is_null($enabled) && ! empty($businessId)) {
            $enabled = \App\Models\Business::find($businessId)?->enabled_modules;
        }

        return in_array('account', (array) $enabled, true);
    }
}
