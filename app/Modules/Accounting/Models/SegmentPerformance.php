<?php

namespace App\Modules\Accounting\Models;

use App\Models\Business;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Profitability of a slice of the business (a location, a category, a
 * customer group, …) over a period.
 */
class SegmentPerformance extends Model
{
    protected $table = 'segment_performance';

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

    public function scopeOfSegmentType(Builder $query, string $type): Builder
    {
        return $query->where('segment_type', $type);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function analyst(): BelongsTo
    {
        return $this->belongsTo(User::class, 'analyzed_by');
    }

    /**
     * Derives gross profit, margins, growth and average ticket size.
     */
    public function recalculate(): void
    {
        $revenue = (float) $this->revenue;

        $this->gross_profit = round($revenue - (float) $this->cost_of_goods_sold, 2);

        $this->segment_profit = round(
            (float) $this->gross_profit
            - (float) $this->operating_expenses
            - (float) $this->allocated_overhead,
            2
        );

        $this->gross_margin_percentage = $revenue != 0
            ? round((float) $this->gross_profit / $revenue * 100, 2)
            : 0;

        $this->profit_margin_percentage = $revenue != 0
            ? round((float) $this->segment_profit / $revenue * 100, 2)
            : 0;

        $this->average_transaction_value = (int) $this->transaction_count > 0
            ? round($revenue / (int) $this->transaction_count, 2)
            : 0;

        $previous = (float) $this->previous_period_revenue;
        $this->revenue_growth_percentage = $previous != 0
            ? round(($revenue - $previous) / abs($previous) * 100, 2)
            : 0;
    }

    /**
     * @return array<string, string>
     */
    public static function segmentTypes(): array
    {
        return [
            // These three used to address `business.`, `category.` and `brand.`
            // namespaces that have never existed in this application, so they
            // rendered as their own keys while the two below them worked. All
            // five words are already in `lang_v1`.
            'location' => __('lang_v1.business_location'),
            'category' => __('lang_v1.category'),
            'brand' => __('lang_v1.brand'),
            'customer_group' => __('lang_v1.customer_group'),
            'product' => __('lang_v1.product'),
        ];
    }
}
