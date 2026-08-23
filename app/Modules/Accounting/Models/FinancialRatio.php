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
 * Snapshot of the computed financial ratios for a period.
 */
class FinancialRatio extends Model
{
    protected $table = 'financial_ratios';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['calculation_date' => 'date'];
    }

    public function scopeForBusiness(Builder $query, ?int $businessId = null): Builder
    {
        return $query->where('business_id', $businessId ?? Tenancy::id());
    }

    public function scopeOfPeriodType(Builder $query, string $type): Builder
    {
        return $query->where('period_type', $type);
    }

    public function scopeBetweenDates(Builder $query, ?string $start, ?string $end): Builder
    {
        if (! empty($start)) {
            $query->where('calculation_date', '>=', $start);
        }

        if (! empty($end)) {
            $query->where('calculation_date', '<=', $end);
        }

        return $query;
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
     * Ratios grouped for display, keyed by section.
     *
     * @return array<string, array<int, string>>
     */
    public static function groups(): array
    {
        return [
            'liquidity' => [
                'current_ratio', 'quick_ratio', 'cash_ratio', 'operating_cash_flow_ratio',
            ],
            'profitability' => [
                'gross_profit_margin', 'operating_profit_margin', 'net_profit_margin',
                'return_on_assets', 'return_on_equity', 'return_on_investment',
            ],
            'efficiency' => [
                'asset_turnover_ratio', 'inventory_turnover_ratio',
                'receivables_turnover_ratio', 'payables_turnover_ratio',
                'days_sales_outstanding', 'days_inventory_outstanding',
                'days_payables_outstanding', 'cash_conversion_cycle',
            ],
            'leverage' => [
                'debt_to_equity_ratio', 'debt_to_assets_ratio', 'equity_ratio',
                'debt_service_coverage_ratio', 'interest_coverage_ratio',
            ],
            'market' => [
                'earnings_per_share', 'price_earnings_ratio',
                'book_value_per_share', 'dividend_yield',
            ],
        ];
    }
}
