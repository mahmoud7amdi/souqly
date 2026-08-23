<?php

namespace App\Traits;

use App\Scopes\BusinessScope;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;

/**
 * Applies the tenant global scope and auto-fills `business_id` on create.
 *
 * Used by every model whose table carries a `business_id`.
 */
trait BelongsToBusiness
{
    public static function bootBelongsToBusiness(): void
    {
        static::addGlobalScope(new BusinessScope);

        static::creating(function ($model) {
            if (empty($model->business_id)) {
                $model->business_id = Tenancy::id();
            }
        });
    }

    /**
     * Query a specific business, ignoring the ambient tenant.
     */
    public function scopeForBusiness(Builder $query, int $businessId): Builder
    {
        return $query->withoutGlobalScope(BusinessScope::class)
            ->where($this->getTable().'.business_id', $businessId);
    }

    /**
     * Drop the tenant filter entirely (superadmin / maintenance use only).
     */
    public function scopeWithoutBusinessScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope(BusinessScope::class);
    }

    public function business()
    {
        return $this->belongsTo(\App\Models\Business::class, 'business_id');
    }
}
