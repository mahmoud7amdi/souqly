<?php

namespace App\Listeners;

use App\Events\TransactionPaymentUpdated;
use App\Models\AccountTransaction;

/**
 * Keeps the account mirror in step when a payment is edited.
 *
 * Handles the account being changed, added or removed on an existing payment.
 */
class UpdateAccountTransaction
{
    public function handle(TransactionPaymentUpdated $event): void
    {
        $payment = $event->payment;

        $existing = AccountTransaction::where('transaction_payment_id', $payment->id)->get();

        // Account removed (or switched to an advance payment) — drop mirrors.
        if (empty($payment->account_id) || $payment->method === 'advance') {
            foreach ($existing as $row) {
                $row->delete();
            }

            return;
        }

        if ($existing->isEmpty()) {
            // Account added to a payment that previously had none.
            (new AddAccountTransaction)->handle(
                new \App\Events\TransactionPaymentAdded($payment)
            );

            return;
        }

        foreach ($existing as $row) {
            $row->account_id = $payment->account_id;
            $row->amount = $payment->amount;
            $row->operation_date = $payment->paid_on ?? $row->operation_date;
            $row->reff_no = $payment->payment_ref_no;
            $row->note = $payment->note;
            $row->save();
        }
    }
}
