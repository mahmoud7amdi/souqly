<?php

namespace App\Listeners;

use App\Events\TransactionPaymentUpdated;
use App\Services\CashRegisterService;

/**
 * Keeps the drawer entry in step when a payment is edited.
 */
class UpdateCashRegisterTransaction
{
    public function __construct(private CashRegisterService $registers) {}

    public function handle(TransactionPaymentUpdated $event): void
    {
        $this->registers->syncPayment($event->payment);
    }
}
