<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bank / cheque metadata for a journal posting.
 */
class PaymentDetail extends Model
{
    protected $table = 'payment_details';

    protected $guarded = ['id'];

    public function payment_type(): BelongsTo
    {
        return $this->belongsTo(PaymentType::class, 'payment_type_id')->withDefault();
    }

    /**
     * True when there is more than just a payment type to show.
     */
    public function getHasMoreInfoAttribute(): bool
    {
        return ! empty($this->cheque_number)
            || ! empty($this->account_number)
            || ! empty($this->bank_name)
            || ! empty($this->routing_code)
            || ! empty($this->description);
    }
}
