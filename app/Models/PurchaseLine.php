<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A stock-IN line. Each row IS a lot: FIFO consumption is tracked against it
 * via transaction_sell_lines_purchase_lines.
 */
class PurchaseLine extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'mfg_date' => 'date',
            'exp_date' => 'date',
            'purchase_price' => 'float',
            'purchase_price_inc_tax' => 'float',
            'pp_without_discount' => 'float',
            'discount_percent' => 'float',
            'item_tax' => 'float',
            'quantity_sold' => 'float',
            'quantity_adjusted' => 'float',
            'quantity_returned' => 'float',
            'po_quantity_purchased' => 'float',
        ];
    }

    /**
     * Quantity of this lot not yet consumed by any outgoing movement.
     */
    public function getQuantityRemainingAttribute(): float
    {
        return round(
            (float) $this->attributes['quantity']
                - (float) $this->quantity_sold
                - (float) $this->quantity_adjusted
                - (float) $this->quantity_returned,
            4
        );
    }

    /**
     * Quantity already consumed out of this lot.
     */
    public function getQuantityUsedAttribute(): float
    {
        return round(
            (float) $this->quantity_sold
                + (float) $this->quantity_adjusted
                + (float) $this->quantity_returned,
            4
        );
    }

    /**
     * True once the lot's expiry date has passed.
     */
    public function isExpired(): bool
    {
        return ! empty($this->exp_date) && $this->exp_date->isPast();
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function variations(): BelongsTo
    {
        return $this->belongsTo(Variation::class, 'variation_id');
    }

    public function sub_unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'sub_unit_id');
    }

    public function line_tax(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class, 'tax_id');
    }

    public function purchase_order_line(): BelongsTo
    {
        return $this->belongsTo(PurchaseLine::class, 'purchase_order_line_id');
    }

    public function purchase_requisition_line(): BelongsTo
    {
        return $this->belongsTo(PurchaseLine::class, 'purchase_requisition_line_id');
    }

    /**
     * FIFO map rows consuming this lot.
     */
    public function sell_line_purchase_lines(): HasMany
    {
        return $this->hasMany(TransactionSellLinesPurchaseLines::class, 'purchase_line_id');
    }
}
