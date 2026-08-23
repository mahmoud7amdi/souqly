<?php

namespace App\Modules\Accounting\Models;

use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Twelve monthly budget figures for one account (optionally per cost centre)
 * within a financial year.
 */
class Budget extends Model
{
    protected $table = 'budgets';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return array_merge(
            ['chart_of_account_id' => 'integer'],
            collect(range(1, 12))
                ->mapWithKeys(fn ($m) => ['month_'.$m => 'float'])
                ->all()
        );
    }

    public function scopeForBusiness(Builder $query, ?int $businessId = null): Builder
    {
        return $query->where('business_id', $businessId ?? Tenancy::id());
    }

    public function chart_of_account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class);
    }

    public function cost_center(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    /**
     * Monthly figures rolled up into four quarters.
     *
     * @return array<int, float>
     */
    public function getQuarterlyAttribute(): array
    {
        $quarters = [];

        for ($q = 0; $q < 4; $q++) {
            $sum = 0.0;

            for ($m = 1; $m <= 3; $m++) {
                $sum += (float) $this->{'month_'.($q * 3 + $m)};
            }

            $quarters[$q + 1] = round($sum, 4);
        }

        return $quarters;
    }

    public function getYearlyAttribute(): float
    {
        $total = 0.0;

        for ($m = 1; $m <= 12; $m++) {
            $total += (float) $this->{'month_'.$m};
        }

        return round($total, 4);
    }
}
