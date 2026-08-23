<?php

namespace App\Modules\Essentials\Models;

use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Transaction;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A payroll run. The individual payslips are `payroll` typed rows in
 * `transactions`, linked through essentials_payroll_group_transactions.
 */
class PayrollGroup extends Model
{
    protected $table = 'essentials_payroll_groups';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['gross_total' => 'float'];
    }

    public function scopeForBusiness(Builder $query, ?int $businessId = null): Builder
    {
        return $query->where('business_id', $businessId ?? Tenancy::id());
    }

    public function payrollGroupTransactions(): BelongsToMany
    {
        return $this->belongsToMany(
            Transaction::class,
            'essentials_payroll_group_transactions',
            'payroll_group_id',
            'transaction_id'
        );
    }

    public function businessLocation(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class, 'location_id');
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'business_id');
    }

    public function getStatusBadgeAttribute(): string
    {
        return match ($this->status) {
            'final' => 'badge-success',
            'draft' => 'badge-warning',
            default => 'badge-muted',
        };
    }
}
