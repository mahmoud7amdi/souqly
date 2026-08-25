<?php

namespace App\Modules\AssetManagement\Models;

use App\Models\BusinessLocation;
use App\Models\Category;
use App\Models\Media;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Asset extends Model
{
    protected $table = 'assets';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'quantity' => 'float',
            'unit_price' => 'float',
            'depreciation' => 'float',
            'is_allocatable' => 'boolean',
        ];
    }

    public function scopeForBusiness(Builder $query, ?int $businessId = null): Builder
    {
        return $query->where('business_id', $businessId ?? Tenancy::id());
    }

    public function scopeAllocatable(Builder $query): Builder
    {
        return $query->where('is_allocatable', 1);
    }

    /**
     * Narrow to the branches this user may see — plus the assets that sit at no
     * branch at all.
     *
     * `location_id` is nullable here, unlike on a transaction, and the null means
     * something: a laptop belongs to the company, not to a shop. Filtering it out
     * along with the branches a user cannot reach would hide the head-office
     * register from everyone who is assigned to a store, which is the opposite of
     * what a location restriction is for.
     */
    public function scopePermitted(Builder $query): Builder
    {
        $permitted = BusinessLocation::permittedLocations();

        return $query->when($permitted !== 'all', fn ($q) => $q->where(
            fn ($inner) => $inner
                ->whereIn('location_id', (array) $permitted)
                ->orWhereNull('location_id')
        ));
    }

    /**
     * Preload everything a list row asks for, so a page of assets costs a fixed
     * number of queries instead of three per row.
     *
     * `allocated_quantity` is two aggregates and `is_in_warranty` is an existence
     * check; read off bare models that is seventy-five queries for twenty-five
     * rows. The three accessors below use these aggregates when they are present,
     * so the *definition* of allocated and in-warranty still lives in exactly one
     * place each and no screen re-derives either.
     */
    public function scopeWithListAggregates(Builder $query): Builder
    {
        $today = now()->toDateString();

        return $query
            ->withSum([
                'transactions as allocated_sum' => fn ($q) => $q->where('transaction_type', 'allocate'),
            ], 'quantity')
            ->withSum([
                'transactions as revoked_sum' => fn ($q) => $q->where('transaction_type', 'revoke'),
            ], 'quantity')
            ->withExists([
                'warranties as in_warranty' => fn ($q) => $q
                    ->where('start_date', '<=', $today)
                    ->where('end_date', '>=', $today),
            ]);
    }

    /**
     * How an asset came in. Four words, and they are about condition rather than
     * paperwork: what a register needs to know later is whether the price paid was
     * a new-goods price, because that is what makes a depreciation rate defensible.
     *
     * Kept in `assetmanagement` rather than `lang_v1` because nothing outside this
     * module has a use for the vocabulary.
     *
     * @return array<string, string>
     */
    public static function purchaseTypes(): array
    {
        return [
            'new' => __('assetmanagement.purchase_type_new'),
            'used' => __('assetmanagement.purchase_type_used'),
            'refurbished' => __('assetmanagement.purchase_type_refurbished'),
            'leased' => __('assetmanagement.purchase_type_leased'),
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function businessLocation(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class, 'location_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'model');
    }

    public function warranties(): HasMany
    {
        return $this->hasMany(AssetWarranty::class);
    }

    public function maintenances(): HasMany
    {
        return $this->hasMany(AssetMaintenance::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(AssetTransaction::class);
    }

    /**
     * True when any warranty covers today.
     */
    public function getIsInWarrantyAttribute(): bool
    {
        // Preloaded by scopeWithListAggregates(); see the note there.
        if (array_key_exists('in_warranty', $this->attributes)) {
            return (bool) $this->attributes['in_warranty'];
        }

        $today = now()->toDateString();

        return $this->warranties()
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->exists();
    }

    /**
     * Quantity currently allocated out (allocations minus revocations).
     */
    public function getAllocatedQuantityAttribute(): float
    {
        /*
         * Preloaded by scopeWithAllocationSums() on list screens. Read from the
         * raw attributes rather than through `$this->allocated_sum`, because an
         * absent aggregate has to be distinguishable from a present zero — and a
         * property read gives null for both.
         */
        if (array_key_exists('allocated_sum', $this->attributes)) {
            return round(
                (float) $this->attributes['allocated_sum'] - (float) ($this->attributes['revoked_sum'] ?? 0),
                4
            );
        }

        $allocated = (float) $this->transactions()
            ->where('transaction_type', 'allocate')->sum('quantity');
        $revoked = (float) $this->transactions()
            ->where('transaction_type', 'revoke')->sum('quantity');

        return round($allocated - $revoked, 4);
    }

    public function getAvailableQuantityAttribute(): float
    {
        return round((float) $this->quantity - $this->allocated_quantity, 4);
    }

    /**
     * What was paid, before any depreciation.
     */
    public function getAcquisitionCostAttribute(): float
    {
        return round((float) $this->quantity * (float) $this->unit_price, 4);
    }

    /**
     * Current book value after straight-line depreciation.
     */
    public function getCurrentValueAttribute(): float
    {
        $cost = $this->acquisition_cost;

        if (empty($this->depreciation) || empty($this->purchase_date)) {
            return round($cost, 4);
        }

        $years = $this->purchase_date->floatDiffInYears(now());
        $value = $cost - ($cost * (float) $this->depreciation / 100 * $years);

        return round(max(0, $value), 4);
    }
}
