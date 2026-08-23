<?php

namespace App\Modules\InventoryManagement\Models;

use App\Models\Product;
use App\Models\Transaction;
use App\Models\Variation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One counted line of a stock count.
 *
 * `qty_before` is the book quantity, `amount_after_inventory` the counted
 * quantity, and `Amount_difference` the surplus (+) or shortage (−).
 */
class InventoryProduct extends Model
{
    protected $table = 'inventory_products';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount_after_inventory' => 'float',
            'Amount_difference' => 'float',
        ];
    }

    /** Lines counted higher than the book quantity. */
    public function scopeSurplus(Builder $query): Builder
    {
        return $query->where('Amount_difference', '>', 0);
    }

    /** Lines counted lower than the book quantity. */
    public function scopeShortage(Builder $query): Builder
    {
        return $query->where('Amount_difference', '<', 0);
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class, 'inventory_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variation(): BelongsTo
    {
        return $this->belongsTo(Variation::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function isProcessed(): bool
    {
        return ! empty($this->transaction_id);
    }
}
