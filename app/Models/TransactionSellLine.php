<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A stock-OUT line. Linked to the lots it consumed through
 * transaction_sell_lines_purchase_lines.
 */
class TransactionSellLine extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'station_started_at' => 'datetime',
            'station_completed_at' => 'datetime',
            'quantity' => 'float',
            'quantity_returned' => 'float',
            'unit_price' => 'float',
            'unit_price_inc_tax' => 'float',
            'unit_price_before_discount' => 'float',
            'line_discount_amount' => 'float',
            'item_tax' => 'float',
            'so_quantity_invoiced' => 'float',
        ];
    }

    /**
     * Net quantity after returns.
     */
    public function getNetQuantityAttribute(): float
    {
        return round((float) $this->quantity - (float) $this->quantity_returned, 4);
    }

    /**
     * Line total including tax and after the line discount.
     */
    public function getLineTotalAttribute(): float
    {
        return round((float) $this->quantity * (float) $this->unit_price_inc_tax, 4);
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

    /**
     * The lot explicitly chosen on this line (when lot tracking is on).
     */
    public function lot_details(): BelongsTo
    {
        return $this->belongsTo(PurchaseLine::class, 'lot_no_line_id');
    }

    public function sub_unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'sub_unit_id');
    }

    public function line_tax(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class, 'tax_id');
    }

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class, 'discount_id');
    }

    public function so_line(): BelongsTo
    {
        return $this->belongsTo(TransactionSellLine::class, 'so_line_id');
    }

    /** Combo / modifier child lines. */
    public function child_lines(): HasMany
    {
        return $this->hasMany(TransactionSellLine::class, 'parent_sell_line_id');
    }

    public function parent_line(): BelongsTo
    {
        return $this->belongsTo(TransactionSellLine::class, 'parent_sell_line_id');
    }

    public function warranties(): BelongsToMany
    {
        return $this->belongsToMany(
            Warranty::class,
            'sell_line_warranties',
            'sell_line_id',
            'warranty_id'
        );
    }

    /**
     * The FIFO map rows telling which lots this line consumed.
     */
    public function sell_line_purchase_lines(): HasMany
    {
        return $this->hasMany(TransactionSellLinesPurchaseLines::class, 'sell_line_id');
    }
}
