<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VariationGroupPrice extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['price_inc_tax' => 'float'];
    }

    public function price_group(): BelongsTo
    {
        return $this->belongsTo(SellingPriceGroup::class, 'price_group_id');
    }

    public function variation(): BelongsTo
    {
        return $this->belongsTo(Variation::class);
    }

    /**
     * Effective price: `fixed` uses price_inc_tax verbatim, `percentage`
     * applies it as a markup on the variation's own sell price.
     */
    public function getCalculatedPriceAttribute(): float
    {
        if ($this->price_type === 'percentage') {
            $base = (float) ($this->variation->sell_price_inc_tax ?? 0);

            return $base + ($base * (float) $this->price_inc_tax / 100);
        }

        return (float) $this->price_inc_tax;
    }
}
