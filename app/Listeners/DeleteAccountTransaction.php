<?php

namespace App\Listeners;

use App\Events\TransactionPaymentDeleted;
use App\Models\AccountTransaction;

/**
 * Removes the account mirror when a payment is deleted.
 */
class DeleteAccountTransaction
{
    public function handle(TransactionPaymentDeleted $event): void
    {
        AccountTransaction::where('transaction_payment_id', $event->payment->id)
            ->get()
            ->each
            ->delete();
    }
}
