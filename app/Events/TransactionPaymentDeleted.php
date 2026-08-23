<?php

namespace App\Events;

use App\Models\TransactionPayment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TransactionPaymentDeleted
{
    use Dispatchable, SerializesModels;

    public function __construct(public TransactionPayment $payment) {}
}
