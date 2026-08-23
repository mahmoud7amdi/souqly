<?php

namespace App\Modules\Essentials\Models;

use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A recurring or one-off allowance / deduction that can be attached to
 * employees and pulled into their payroll.
 */
class EssentialsAllowanceAndDeduction extends Model
{
    protected $table = 'essentials_allowances_and_deductions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'applicable_date' => 'date',
        ];
    }

    public function scopeForBusiness(Builder $query, ?int $businessId = null): Builder
    {
        return $query->where('business_id', $businessId ?? Tenancy::id());
    }

    public function scopeAllowances(Builder $query): Builder
    {
        return $query->where('type', 'allowance');
    }

    public function scopeDeductions(Builder $query): Builder
    {
        return $query->where('type', 'deduction');
    }

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'essentials_user_allowance_and_deductions',
            'allowance_deduction_id',
            'user_id'
        );
    }

    /**
     * Resolves this component to a money amount against a base salary.
     */
    public function amountFor(float $baseSalary): float
    {
        return $this->amount_type === 'percent'
            ? round($baseSalary * (float) $this->amount / 100, 4)
            : round((float) $this->amount, 4);
    }
}
