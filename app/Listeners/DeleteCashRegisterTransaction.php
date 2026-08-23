<?php

namespace App\Listeners;

use App\Events\TransactionPaymentDeleted;
use App\Services\CashRegisterService;

/**
 * Removes the drawer entry when a payment is deleted.
 *
 * The alternative — treating the register as an immutable log of what physically
 * crossed the drawer — reads well but counts badly: a figure mis-keyed and
 * corrected within the same shift would leave the close short by the mistake,
 * every time, with nothing to point at.
 */
class DeleteCashRegisterTransaction
{
    public function __construct(private CashRegisterService $registers) {}

    public function handle(TransactionPaymentDeleted $event): void
    {
        $this->registers->removePayment($event->payment);
    }
}
