<?php

namespace App\Modules\Accounting\Models;

use App\Models\BusinessLocation;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A responsibility centre that costs and revenues can be attributed to.
 */
class CostCenter extends Model
{
    protected $table = 'cost_centers';

    protected $fillable = [
        'business_id', 'code', 'name', 'description', 'parent_id', 'type',
        'manager_id', 'location_id', 'budget_amount', 'budget_period',
        'is_active', 'sort_order', 'settings',
    ];

    protected function casts(): array
    {
        return [
            'budget_amount' => 'float',
            'is_active' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function scopeForBusiness(Builder $query, ?int $businessId = null): Builder
    {
        return $query->where('business_id', $businessId ?? Tenancy::id());
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', 1);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeRootLevel(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(CostCenter::class, 'parent_id')->orderBy('sort_order');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class, 'location_id');
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'cost_center_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(CostCenterAllocation::class);
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    /**
     * @return array<int, string>
     */
    public static function forDropdown(): array
    {
        return static::forBusiness()
            ->active()
            ->orderBy('sort_order')
            ->get()
            ->mapWithKeys(fn ($c) => [$c->id => $c->code.' - '.$c->name])
            ->all();
    }
}
