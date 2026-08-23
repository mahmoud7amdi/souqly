<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockAdjustmentLine extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'unit_price' => 'float',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function variation(): BelongsTo
    {
        return $this->belongsTo(Variation::class, 'variation_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function lot_details(): BelongsTo
    {
        return $this->belongsTo(PurchaseLine::class, 'lot_no_line_id');
    }

    /**
     * FIFO map rows recording which lots this adjustment removed.
     */
    public function purchase_lines(): HasMany
    {
        return $this->hasMany(
            TransactionSellLinesPurchaseLines::class,
            'stock_adjustment_line_id'
        );
    }
}
