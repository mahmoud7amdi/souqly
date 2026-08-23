<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Tenant-aware role.
 *
 * Role names are namespaced per business (`Admin#3`, `Cashier#3`) because the
 * spatie `roles` table enforces a global unique constraint on (name, guard).
 * `is_default` marks the two roles created automatically with every business.
 */
class Role extends SpatieRole
{
    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function scopeForBusiness(Builder $query, ?int $businessId = null): Builder
    {
        return $query->where('business_id', $businessId ?? \App\Support\Tenancy::id());
    }

    public function scopeNotDefault(Builder $query): Builder
    {
        return $query->where('is_default', 0);
    }

    /**
     * Role name without the tenant suffix.
     */
    public function getDisplayNameAttribute(): string
    {
        return explode('#', $this->name)[0];
    }

    /**
     * Build the stored name for a role within a business.
     */
    public static function nameFor(string $role, int $businessId): string
    {
        return $role.'#'.$businessId;
    }

    /**
     * @return array<int, string>
     */
    public static function forDropdown(?int $businessId = null): array
    {
        return static::forBusiness($businessId)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->id => $r->display_name])
            ->all();
    }
}
