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
 * Statement of changes in equity for a period.
 */
class EquityStatement extends Model
{
    protected $table = 'equity_statements';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
        ];
    }

    public function scopeForBusiness(Builder $query, ?int $businessId = null): Builder
    {
        return $query->where('business_id', $businessId ?? Tenancy::id());
    }

    public function scopeOfPeriodType(Builder $query, string $type): Builder
    {
        return $query->where('period_type', $type);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class, 'location_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Rolls the opening balances plus movements into the closing balances.
     */
    public function recalculate(): void
    {
        $this->closing_share_capital = round(
            (float) $this->opening_share_capital
            + (float) $this->share_capital_issued
            - (float) $this->share_capital_repurchased,
            2
        );

        $this->closing_retained_earnings = round(
            (float) $this->opening_retained_earnings
            + (float) $this->net_income
            - (float) $this->dividends_paid
            - (float) $this->transfers_to_reserves
            + (float) $this->prior_period_adjustments,
            2
        );

        $this->closing_reserves = round(
            (float) $this->opening_reserves
            + (float) $this->transfers_to_reserves
            + (float) $this->other_comprehensive_income,
            2
        );

        $this->closing_total_equity = round(
            (float) $this->closing_share_capital
            + (float) $this->closing_retained_earnings
            + (float) $this->closing_reserves,
            2
        );
    }
}
