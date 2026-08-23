<?php

namespace App\Modules\Superadmin\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A SaaS subscription plan with its resource quotas.
 *
 * A quota of 0 means unlimited.
 */
class Package extends Model
{
    use SoftDeletes;

    protected $table = 'packages';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'custom_permissions' => 'array',
            'price' => 'float',
            'is_active' => 'boolean',
            'is_private' => 'boolean',
            'is_one_time' => 'boolean',
            'enable_custom_link' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', 1);
    }

    public function scopeNotPrivate(Builder $query): Builder
    {
        return $query->where('is_private', 0);
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Quota columns keyed by the resource they limit.
     *
     * @return array<string, int>
     */
    public function quotas(): array
    {
        return [
            'locations' => (int) $this->location_count,
            'users' => (int) $this->user_count,
            'products' => (int) $this->product_count,
            'invoices' => (int) $this->invoice_count,
        ];
    }

    /**
     * Human-readable billing interval, e.g. "3 months".
     */
    public function getIntervalLabelAttribute(): string
    {
        return $this->interval_count.' '.__('superadmin.'.$this->interval);
    }
}
