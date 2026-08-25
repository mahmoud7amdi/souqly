<?php

namespace App\Modules\AssetManagement\Models;

use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Allocation of an asset to a user, or its revocation (linked back to the
 * allocation through parent_id).
 */
class AssetTransaction extends Model
{
    protected $table = 'asset_transactions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'transaction_datetime' => 'datetime',
            'allocated_upto' => 'date',
            'quantity' => 'float',
        ];
    }

    public function scopeForBusiness(Builder $query, ?int $businessId = null): Builder
    {
        return $query->where('business_id', $businessId ?? Tenancy::id());
    }

    public function scopeAllocations(Builder $query): Builder
    {
        return $query->where('transaction_type', 'allocate');
    }

    public function scopeRevocations(Builder $query): Builder
    {
        return $query->where('transaction_type', 'revoke');
    }

    /** Allocations that are overdue for return. */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('transaction_type', 'allocate')
            ->whereNotNull('allocated_upto')
            ->where('allocated_upto', '<', now()->toDateString())
            ->whereDoesntHave('revokeTransaction');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    public function receiverUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function revokeTransaction(): HasMany
    {
        return $this->hasMany(AssetTransaction::class, 'parent_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(AssetTransaction::class, 'parent_id');
    }

    /**
     * Quantity of this allocation still outstanding.
     *
     * Sums the loaded relation when the caller eager-loaded it, and only falls back
     * to a query when it did not. A page of twenty-five allocations reads this once
     * per row, so the difference between the two branches is twenty-five queries and
     * one — and the list screen that shows an outstanding column is precisely the
     * caller that already has the returns in memory.
     */
    public function getQuantityOutstandingAttribute(): float
    {
        $returned = $this->relationLoaded('revokeTransaction')
            ? $this->revokeTransaction->sum('quantity')
            : $this->revokeTransaction()->sum('quantity');

        return round((float) $this->quantity - (float) $returned, 4);
    }
}
