<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Note count of each cash denomination, attached polymorphically to a
 * register close or a payment.
 */
class CashDenomination extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'float'];
    }

    public function model(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Total value represented by this denomination row.
     */
    public function getTotalAttribute(): float
    {
        return round((float) $this->amount * (int) $this->total_count, 4);
    }
}
