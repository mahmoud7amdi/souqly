<?php

namespace App\Modules\Essentials\Models;

use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historic payroll figures per employee / month. The authoritative payslip
 * is the `payroll` transaction; this is the flat summary used by reports.
 */
class EssentialsPayroll extends Model
{
    protected $table = 'essentials_payrolls';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'allowances' => 'array',
            'deductions' => 'array',
            'duration' => 'float',
            'amount_per_unit_duration' => 'float',
            'gross_amount' => 'float',
        ];
    }

    public function scopeForBusiness(Builder $query, ?int $businessId = null): Builder
    {
        return $query->where('business_id', $businessId ?? Tenancy::id());
    }

    public function scopeForPeriod(Builder $query, int $month, int $year): Builder
    {
        return $query->where('month', $month)->where('year', $year);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function created_user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
