<?php

namespace App\Modules\Essentials\Models;

use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    protected $table = 'essentials_shifts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'holidays' => 'array',
            'working_days' => 'array',
            'is_allowed_auto_clockout' => 'boolean',
        ];
    }

    public function scopeForBusiness(Builder $query, ?int $businessId = null): Builder
    {
        return $query->where('business_id', $businessId ?? Tenancy::id());
    }

    public function user_shifts(): HasMany
    {
        return $this->hasMany(EssentialsUserShift::class, 'essentials_shift_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(EssentialsAttendance::class, 'essentials_shift_id');
    }

    /**
     * Scheduled length of the shift in hours (null for flexible shifts).
     */
    public function getDurationHoursAttribute(): ?float
    {
        if ($this->type !== 'fixed_shift' || empty($this->start_time) || empty($this->end_time)) {
            return null;
        }

        $start = \Carbon\Carbon::parse($this->start_time);
        $end = \Carbon\Carbon::parse($this->end_time);

        if ($end->lessThan($start)) {
            $end->addDay();
        }

        return round($start->floatDiffInHours($end), 2);
    }

    /**
     * @return array<int, string>
     */
    public static function forDropdown(): array
    {
        return static::forBusiness()->orderBy('name')->pluck('name', 'id')->all();
    }
}
