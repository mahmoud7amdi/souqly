<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Groups the variations of a product (e.g. "Size"). Single products get one
 * dummy row (is_dummy = 1) so the rest of the system can treat every product
 * uniformly.
 */
class ProductVariation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_dummy' => 'boolean'];
    }

    public function variations(): HasMany
    {
        return $this->hasMany(Variation::class);
    }

    public function variation_template(): BelongsTo
    {
        return $this->belongsTo(VariationTemplate::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
