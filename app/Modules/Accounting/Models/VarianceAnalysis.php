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
 * Budget-vs-actual (or period-over-period) variance for one account.
 */
class VarianceAnalysis extends Model
{
    protected $table = 'variance_analysis';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'analyzed_at' => 'datetime',
            'requires_attention' => 'boolean',
        ];
    }

    public function scopeForBusiness(Builder $query, ?int $businessId = null): Builder
    {
        return $query->where('business_id', $businessId ?? Tenancy::id());
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('variance_type', $type);
    }

    public function scopeOfSeverity(Builder $query, string $severity): Builder
    {
        return $query->where('severity', $severity);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class, 'location_id');
    }

    public function chartOfAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class);
    }

    public function analyst(): BelongsTo
    {
        return $this->belongsTo(User::class, 'analyzed_by');
    }

    /**
     * Derives the variance amount, percentage and favourable/unfavourable
     * verdict from the budgeted and actual figures.
     *
     * For income accounts more is favourable; for expense accounts less is.
     */
    public function recalculate(string $accountType = 'expense'): void
    {
        $budgeted = (float) $this->budgeted_amount;
        $actual = (float) $this->actual_amount;

        $this->variance_amount = round($actual - $budgeted, 2);
        $this->variance_percentage = $budgeted != 0
            ? round(($actual - $budgeted) / abs($budgeted) * 100, 2)
            : 0;

        if (abs((float) $this->variance_amount) < 0.01) {
            $this->variance_status = 'neutral';
        } elseif (in_array($accountType, ['income', 'equity'], true)) {
            $this->variance_status = $this->variance_amount > 0 ? 'favorable' : 'unfavorable';
        } else {
            $this->variance_status = $this->variance_amount < 0 ? 'favorable' : 'unfavorable';
        }

        $absPercent = abs((float) $this->variance_percentage);
        $this->severity = match (true) {
            $absPercent >= 50 => 'critical',
            $absPercent >= 25 => 'high',
            $absPercent >= 10 => 'medium',
            default => 'low',
        };

        $this->requires_attention = $this->variance_status === 'unfavorable'
            && in_array($this->severity, ['high', 'critical'], true);
    }
}
