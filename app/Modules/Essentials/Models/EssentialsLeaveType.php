<?php

namespace App\Modules\Essentials\Models;

use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EssentialsLeaveType extends Model
{
    protected $table = 'essentials_leave_types';

    protected $guarded = ['id'];

    public function scopeForBusiness(Builder $query, ?int $businessId = null): Builder
    {
        return $query->where('business_id', $businessId ?? Tenancy::id());
    }

    public function leaves(): HasMany
    {
        return $this->hasMany(EssentialsLeave::class, 'essentials_leave_type_id');
    }

    public function balances(): HasMany
    {
        return $this->hasMany(EssentialsLeaveBalance::class, 'leave_type_id');
    }

    /**
     * @return array<int, string>
     */
    public static function forDropdown(): array
    {
        return static::forBusiness()->orderBy('leave_type')->pluck('leave_type', 'id')->all();
    }
}
