<?php

namespace App\Listeners;

use App\Events\TransactionPaymentAdded;
use App\Services\CashRegisterService;

/**
 * Mirrors a transaction payment into the cashier's open drawer.
 *
 * A listener rather than a call in the POS controller, for the same reason
 * {@see AddAccountTransaction} is one: money reaches a document from several
 * roads — a counter sale, a payment added to a due invoice, a refund on a
 * return, an offline sale synced later — and every one of them ends at
 * `PaymentService::addPayment()`. Hooking the roads means missing one.
 *
 * Runs inside the caller's database transaction (PaymentService asserts there is
 * one), so the drawer entry and the payment are committed together or not at
 * all.
 */
class AddCashRegisterTransaction
{
    public function __construct(private CashRegisterService $registers) {}

    public function handle(TransactionPaymentAdded $event): void
    {
        $this->registers->recordPayment($event->payment);
    }
}
