<?php

namespace App\Modules\Accounting\Models;

use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bank statement reconciliation for one cash/bank account.
 */
class BankReconciliation extends Model
{
    protected $table = 'bank_reconciliations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'reconciliation_date' => 'date',
            'statement_date' => 'date',
            'is_reconciled' => 'boolean',
            'prepared_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'statement_beginning_balance' => 'float',
            'statement_ending_balance' => 'float',
            'statement_deposits' => 'float',
            'statement_withdrawals' => 'float',
            'statement_fees' => 'float',
            'statement_interest' => 'float',
            'book_beginning_balance' => 'float',
            'book_ending_balance' => 'float',
            'deposits_in_transit' => 'float',
            'outstanding_checks' => 'float',
            'bank_errors' => 'float',
            'book_errors' => 'float',
            'other_adjustments' => 'float',
            'reconciled_balance' => 'float',
            'difference' => 'float',
        ];
    }

    public function scopeForBusiness(Builder $query, ?int $businessId = null): Builder
    {
        return $query->where('business_id', $businessId ?? Tenancy::id());
    }

    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeBetweenDates(Builder $query, ?string $start, ?string $end): Builder
    {
        if (! empty($start)) {
            $query->where('reconciliation_date', '>=', $start);
        }

        if (! empty($end)) {
            $query->where('reconciliation_date', '<=', $end);
        }

        return $query;
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function chartOfAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class, 'location_id');
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BankReconciliationLine::class, 'reconciliation_id');
    }

    /**
     * Recomputes the reconciled balance and the remaining difference.
     */
    public function recalculate(): void
    {
        $this->reconciled_balance = round(
            (float) $this->statement_ending_balance
            + (float) $this->deposits_in_transit
            - (float) $this->outstanding_checks
            + (float) $this->bank_errors
            + (float) $this->other_adjustments,
            4
        );

        $this->difference = round(
            (float) $this->reconciled_balance - (float) $this->book_ending_balance,
            4
        );

        $this->is_reconciled = abs((float) $this->difference) < 0.0001;
    }
}
