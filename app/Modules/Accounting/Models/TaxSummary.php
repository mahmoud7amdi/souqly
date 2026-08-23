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
 * Periodic tax return summary (output tax vs input tax).
 */
class TaxSummary extends Model
{
    protected $table = 'tax_summaries';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'due_date' => 'date',
            'payment_date' => 'date',
            'filing_date' => 'date',
        ];
    }

    public function scopeForBusiness(Builder $query, ?int $businessId = null): Builder
    {
        return $query->where('business_id', $businessId ?? Tenancy::id());
    }

    public function scopeOfFilingStatus(Builder $query, string $status): Builder
    {
        return $query->where('filing_status', $status);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereIn('filing_status', ['draft', 'ready'])
            ->whereNotNull('due_date')
            ->where('due_date', '<', now()->toDateString());
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class, 'location_id');
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function filedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'filed_by');
    }

    /**
     * Net tax payable = output − input, then credits/adjustments/penalties.
     */
    public function recalculate(): void
    {
        $this->net_tax_payable = round(
            (float) $this->output_tax - (float) $this->input_tax,
            2
        );

        $this->total_tax_due = round(
            (float) $this->net_tax_payable
            - (float) $this->tax_credits
            + (float) $this->tax_adjustments
            + (float) $this->penalties_interest,
            2
        );

        $this->balance_due = round(
            (float) $this->total_tax_due - (float) $this->tax_paid,
            2
        );
    }
}
