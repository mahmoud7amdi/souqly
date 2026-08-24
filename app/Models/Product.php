<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Product extends Model
{
    use BelongsToBusiness;

    protected $guarded = ['id'];

    protected $appends = ['image_url'];

    protected function casts(): array
    {
        return [
            'sub_unit_ids' => 'array',
            'enable_stock' => 'boolean',
            'not_for_selling' => 'boolean',
            'is_inactive' => 'boolean',
            'enable_sr_no' => 'boolean',
            'alert_quantity' => 'float',
        ];
    }

    /* --------------------------------------------------------------------
     | Scopes
     -------------------------------------------------------------------- */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('products.is_inactive', 0);
    }

    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('products.is_inactive', 1);
    }

    public function scopeProductForSales(Builder $query): Builder
    {
        return $query->where('products.not_for_selling', 0);
    }

    public function scopeProductNotForSales(Builder $query): Builder
    {
        return $query->where('products.not_for_selling', 1);
    }

    /**
     * Products available at a location — either unrestricted (no rows in
     * product_locations) or explicitly allowed there.
     */
    public function scopeForLocation(Builder $query, ?int $locationId): Builder
    {
        if (empty($locationId)) {
            return $query;
        }

        return $query->where(function ($q) use ($locationId) {
            // `business_locations.id`, not `locations.id`: the pivot points at
            // business_locations, and the wrong qualifier is a SQL error rather
            // than an empty result — it took the POS product search down.
            $q->whereHas('product_locations', fn ($pl) => $pl->where('business_locations.id', $locationId))
                ->orWhereDoesntHave('product_locations');
        });
    }

    /* --------------------------------------------------------------------
     | Accessors
     -------------------------------------------------------------------- */

    public function getImageUrlAttribute(): string
    {
        if (! empty($this->image) && file_exists($this->image_path)) {
            return asset(config('constants.product_img_path').'/'.$this->image);
        }

        return asset('img/product-placeholder.svg');
    }

    public function getImagePathAttribute(): string
    {
        return public_path(config('constants.product_img_path').'/'.$this->image);
    }

    /**
     * Whether this product has a real picture on disk.
     *
     * Distinct from `image_url`, which never returns null — it falls back to a
     * placeholder SVG so an <img> is never broken. The UI needs to know the
     * difference: a screen showing the placeholder bitmap in every cell of a
     * half-populated catalogue looks defective, where the same screen showing a
     * muted icon looks incomplete, which is what it is. Views and the POS feed
     * both branch on this.
     */
    public function hasImage(): bool
    {
        return ! empty($this->image) && file_exists($this->image_path);
    }

    /* --------------------------------------------------------------------
     | Relationships
     -------------------------------------------------------------------- */

    public function product_variations(): HasMany
    {
        return $this->hasMany(ProductVariation::class);
    }

    public function variations(): HasMany
    {
        return $this->hasMany(Variation::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brands::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function second_unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'secondary_unit_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function sub_category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'sub_category_id', 'id');
    }

    public function product_tax(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class, 'tax', 'id');
    }

    public function warranty(): BelongsTo
    {
        return $this->belongsTo(Warranty::class);
    }

    public function purchase_lines(): HasMany
    {
        return $this->hasMany(PurchaseLine::class);
    }

    public function product_locations(): BelongsToMany
    {
        return $this->belongsToMany(
            BusinessLocation::class,
            'product_locations',
            'product_id',
            'location_id'
        );
    }

    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'model');
    }

    public function rack_details(): HasMany
    {
        return $this->hasMany(ProductRack::class);
    }

    /* --------------------------------------------------------------------
     | Helpers
     -------------------------------------------------------------------- */

    public function isVariable(): bool
    {
        return $this->type === 'variable';
    }

    public function isCombo(): bool
    {
        return $this->type === 'combo';
    }

    /**
     * Product types available when creating a product.
     *
     * @return array<string, string>
     */
    public static function types(): array
    {
        return [
            'single' => __('lang_v1.single'),
            'variable' => __('lang_v1.variable'),
            'combo' => __('lang_v1.combo'),
        ];
    }
}
