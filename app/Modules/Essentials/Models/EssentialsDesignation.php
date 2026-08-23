<?php

namespace App\Modules\Essentials\Models;

use App\Models\Business;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EssentialsDesignation extends Model
{
    protected $table = 'essentials_designations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', 1);
    }

    public function scopeForBusiness(Builder $query, ?int $businessId = null): Builder
    {
        return $query->where('business_id', $businessId ?? Tenancy::id());
    }

    public function scopeForDepartment(Builder $query, int $departmentId): Builder
    {
        return $query->where('department_id', $departmentId);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(EssentialsDepartment::class, 'department_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(EssentialsEmployeeDetail::class, 'designation_id');
    }

    public function getEmployeeCountAttribute(): int
    {
        return $this->employees()->count();
    }

    /**
     * @return array<int, string>
     */
    public static function forDropdown(?int $departmentId = null): array
    {
        $query = static::forBusiness()->active();

        if (! is_null($departmentId)) {
            $query->where('department_id', $departmentId);
        }

        return $query->orderBy('name')->pluck('name', 'id')->all();
    }
}
