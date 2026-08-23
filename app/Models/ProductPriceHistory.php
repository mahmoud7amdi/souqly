<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit trail for every purchase/sell price change on a variation.
 */
class ProductPriceHistory extends Model
{
    protected $table = 'product_price_history';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'old_purchase_price' => 'float',
            'new_purchase_price' => 'float',
            'old_dpp_inc_tax' => 'float',
            'new_dpp_inc_tax' => 'float',
            'old_sell_price' => 'float',
            'new_sell_price' => 'float',
            'old_sell_price_inc_tax' => 'float',
            'new_sell_price_inc_tax' => 'float',
            'old_profit_percent' => 'float',
            'new_profit_percent' => 'float',
        ];
    }

    public function variation(): BelongsTo
    {
        return $this->belongsTo(Variation::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function getFormattedChangeTypeAttribute(): string
    {
        return __('lang_v1.'.$this->change_type);
    }
}
