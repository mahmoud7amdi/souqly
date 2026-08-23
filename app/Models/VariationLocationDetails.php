<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stock cache: qty_available per (variation x location).
 *
 * This must always agree with the sum of the FIFO map
 * (transaction_sell_lines_purchase_lines). Every service that moves stock
 * updates both inside one DB transaction.
 */
class VariationLocationDetails extends Model
{
    protected $table = 'variation_location_details';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['qty_available' => 'float'];
    }

    public function variation(): BelongsTo
    {
        return $this->belongsTo(Variation::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class, 'location_id');
    }
}
