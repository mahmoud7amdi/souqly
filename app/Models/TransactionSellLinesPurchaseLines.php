<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The FIFO map.
 *
 * Ties each outgoing line (sell or stock adjustment) to the purchase line
 * (lot) it consumed, with the quantity taken. This is the single source of
 * truth for cost of goods, profit and return handling.
 */
class TransactionSellLinesPurchaseLines extends Model
{
    protected $table = 'transaction_sell_lines_purchase_lines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'qty_returned' => 'float',
        ];
    }

    public function purchase_line(): BelongsTo
    {
        return $this->belongsTo(PurchaseLine::class, 'purchase_line_id');
    }

    public function sell_line(): BelongsTo
    {
        return $this->belongsTo(TransactionSellLine::class, 'sell_line_id');
    }

    public function stock_adjustment_line(): BelongsTo
    {
        return $this->belongsTo(StockAdjustmentLine::class, 'stock_adjustment_line_id');
    }

    /**
     * Quantity net of returns.
     */
    public function getNetQuantityAttribute(): float
    {
        return round((float) $this->quantity - (float) $this->qty_returned, 4);
    }
}
