<?php

namespace App\Modules\Essentials\Models;

use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * HR profile for a user (one row per employee).
 */
class EssentialsEmployeeDetail extends Model
{
    protected $table = 'essentials_employee_details';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'join_date' => 'date',
            'confirmation_date' => 'date',
            'contract_end_date' => 'date',
            'exit_date' => 'date',
            'salary_effective_date' => 'date',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeForBusiness(Builder $query, ?int $businessId = null): Builder
    {
        return $query->where('business_id', $businessId ?? Tenancy::id());
    }

    public function scopeForDepartment(Builder $query, int $departmentId): Builder
    {
        return $query->where('department_id', $departmentId);
    }

    public function scopeForDesignation(Builder $query, int $designationId): Builder
    {
        return $query->where('designation_id', $designationId);
    }

    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(EssentialsDepartment::class, 'department_id');
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(EssentialsDesignation::class, 'designation_id');
    }

    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class, 'work_location_id');
    }

    public function reportingTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporting_to');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EssentialsEmployeeDocument::class, 'user_id', 'user_id');
    }

    public function getFullNameAttribute(): string
    {
        return $this->user->user_full_name ?? '';
    }

    public function getAgeAttribute(): ?int
    {
        return $this->date_of_birth?->age;
    }

    public function getYearsOfServiceAttribute(): ?float
    {
        if (empty($this->join_date)) {
            return null;
        }

        $end = $this->exit_date ?? now();

        return round($this->join_date->floatDiffInYears($end), 1);
    }

    /**
     * Tailwind badge classes for the employment status.
     */
    public function getStatusBadgeAttribute(): string
    {
        return match ($this->status) {
            'active' => 'badge-success',
            'on_leave' => 'badge-warning',
            'terminated', 'resigned' => 'badge-danger',
            'retired' => 'badge-muted',
            default => 'badge-muted',
        };
    }

    public function getEmploymentTypeLabelAttribute(): string
    {
        return __('essentials.'.$this->employment_type);
    }
}
