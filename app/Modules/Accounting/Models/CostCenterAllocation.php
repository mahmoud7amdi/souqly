<?php

namespace App\Modules\Accounting\Models;

use App\Models\Business;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Allocated vs actual spend for one cost centre / account / period.
 */
class CostCenterAllocation extends Model
{
    protected $table = 'cost_center_allocations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'allocated_amount' => 'float',
            'actual_amount' => 'float',
            'variance' => 'float',
            'variance_percentage' => 'float',
        ];
    }

    public function scopeForBusiness(Builder $query, ?int $businessId = null): Builder
    {
        return $query->where('business_id', $businessId ?? Tenancy::id());
    }

    public function scopeForPeriod(Builder $query, ?string $start, ?string $end): Builder
    {
        if (! empty($start)) {
            $query->where('period_start', '>=', $start);
        }

        if (! empty($end)) {
            $query->where('period_end', '<=', $end);
        }

        return $query;
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function chartOfAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class);
    }

    public function recalculate(): void
    {
        $allocated = (float) $this->allocated_amount;

        $this->variance = round((float) $this->actual_amount - $allocated, 4);
        $this->variance_percentage = $allocated != 0
            ? round((float) $this->variance / abs($allocated) * 100, 2)
            : 0;
    }
}
