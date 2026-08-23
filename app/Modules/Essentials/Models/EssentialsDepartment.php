<?php

namespace App\Modules\Essentials\Models;

use App\Models\Business;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EssentialsDepartment extends Model
{
    protected $table = 'essentials_departments';

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

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(EssentialsDepartment::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(EssentialsDepartment::class, 'parent_id');
    }

    public function head(): BelongsTo
    {
        return $this->belongsTo(User::class, 'head_user_id');
    }

    public function designations(): HasMany
    {
        return $this->hasMany(EssentialsDesignation::class, 'department_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(EssentialsEmployeeDetail::class, 'department_id');
    }

    public function getEmployeeCountAttribute(): int
    {
        return $this->employees()->count();
    }

    /**
     * @return array<int, string>
     */
    public static function forDropdown(): array
    {
        return static::forBusiness()->active()->orderBy('name')->pluck('name', 'id')->all();
    }
}
