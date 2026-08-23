<?php

namespace App\Modules\InventoryManagement\Models;

use App\Models\BusinessLocation;
use App\Models\Product;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A physical stock count for one location.
 *
 * status = false while counting, true once closed.
 */
class Inventory extends Model
{
    protected $table = 'inventory';

    protected $fillable = ['branch_id', 'name', 'created_at', 'end_date', 'status'];

    protected function casts(): array
    {
        return [
            'end_date' => 'datetime',
            'status' => 'boolean',
        ];
    }

    /**
     * The inventory table has no business_id — it is scoped through its
     * branch, which does.
     */
    public function scopeBusiness(Builder $query, ?int $businessId = null): Builder
    {
        $businessId ??= Tenancy::id();

        return $query->whereHas('branch', fn ($q) => $q->where('business_id', $businessId));
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 0);
    }

    public function scopeClosed(Builder $query): Builder
    {
        return $query->where('status', 1);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class, 'branch_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryProduct::class, 'inventory_id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'inventory_products')
            ->withPivot([
                'id', 'amount_after_inventory', 'Amount_difference',
                'inventory_type', 'qty_before', 'transaction_id', 'variation_id',
            ]);
    }

    /**
     * Lines counted so far (a stock adjustment has been raised).
     */
    public function processedLines(): HasMany
    {
        return $this->lines()->whereNotNull('transaction_id');
    }

    public function unprocessedLines(): HasMany
    {
        return $this->lines()->whereNull('transaction_id');
    }
}
