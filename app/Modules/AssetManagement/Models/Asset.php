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
     * Current book value after straight-line depreciation.
     */
    public function getCurrentValueAttribute(): float
    {
        $cost = (float) $this->quantity * (float) $this->unit_price;

        if (empty($this->depreciation) || empty($this->purchase_date)) {
            return round($cost, 4);
        }

        $years = $this->purchase_date->floatDiffInYears(now());
        $value = $cost - ($cost * (float) $this->depreciation / 100 * $years);

        return round(max(0, $value), 4);
    }
}
