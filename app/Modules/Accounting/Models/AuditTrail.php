<?php

namespace App\Modules\Accounting\Models;

use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable record of every create/update/delete performed inside the
 * accounting module.
 */
class AuditTrail extends Model
{
    protected $table = 'accounting_audit_trail';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'changed_fields' => 'array',
            'requires_review' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    public function scopeForBusiness(Builder $query, ?int $businessId = null): Builder
    {
        return $query->where('business_id', $businessId ?? Tenancy::id());
    }

    public function scopeRequiringReview(Builder $query): Builder
    {
        return $query->where('requires_review', 1)->whereNull('reviewed_at');
    }

    public function scopeOfRiskLevel(Builder $query, string $level): Builder
    {
        return $query->where('risk_level', $level);
    }

    public function scopeOfModule(Builder $query, string $module): Builder
    {
        return $query->where('module', $module);
    }

    public function scopeBetweenDates(Builder $query, ?string $start, ?string $end): Builder
    {
        if (! empty($start)) {
            $query->whereDate('created_at', '>=', $start);
        }

        if (! empty($end)) {
            $query->whereDate('created_at', '<=', $end);
        }

        return $query;
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class, 'location_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
