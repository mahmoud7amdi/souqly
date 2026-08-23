<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Purchase payment schedule: a percentage of the invoice due on a date.
 */
class PaymentTerm extends Model
{
    protected $fillable = [
        'due_date',
        'payment_term',
        'purchase_transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'payment_term' => 'float',
            'due_date' => 'date',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'purchase_transaction_id');
    }

    /**
     * Money amount this term represents on its parent invoice.
     */
    public function getAmountAttribute(): float
    {
        $total = (float) ($this->transaction->final_total ?? 0);

        return round($total * (float) $this->payment_term / 100, 4);
    }
}
