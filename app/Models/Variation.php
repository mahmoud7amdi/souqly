<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The sellable / stockable unit. Prices live here; stock lives in
 * variation_location_details.
 */
class Variation extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'combo_variations' => 'array',
            'default_purchase_price' => 'float',
            'dpp_inc_tax' => 'float',
            'profit_percent' => 'float',
            'default_sell_price' => 'float',
            'sell_price_inc_tax' => 'float',
        ];
    }

    /**
     * "Product name (variation name)" — hides the dummy variation name.
     */
    public function getFullNameAttribute(): string
    {
        $name = $this->product->name ?? '';

        if (! empty($this->name) && $this->name !== 'DUMMY') {
            $name .= ' ('.$this->name.')';
        }

        return $name;
    }

    public function product_variation(): BelongsTo
    {
        return $this->belongsTo(ProductVariation::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function sell_lines(): HasMany
    {
        return $this->hasMany(TransactionSellLine::class);
    }

    public function purchase_lines(): HasMany
    {
        return $this->hasMany(PurchaseLine::class);
    }

    public function variation_location_details(): HasMany
    {
        return $this->hasMany(VariationLocationDetails::class);
    }

    public function group_prices(): HasMany
    {
        return $this->hasMany(VariationGroupPrice::class, 'variation_id');
    }

    public function price_history(): HasMany
    {
        return $this->hasMany(ProductPriceHistory::class, 'variation_id');
    }

    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'model');
    }

    /**
     * Stock currently available, optionally at a single location.
     */
    public function currentStock(?int $locationId = null): float
    {
        $query = $this->variation_location_details();

        if (! is_null($locationId)) {
            $query->where('location_id', $locationId);
        }

        return (float) $query->sum('qty_available');
    }
}
