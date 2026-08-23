<?php

namespace App\Modules\Essentials\Models;

use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One clock-in / clock-out pair for an employee.
 */
class EssentialsAttendance extends Model
{
    protected $table = 'essentials_attendances';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'clock_in_time' => 'datetime',
            'clock_out_time' => 'datetime',
            'overtime_hours' => 'float',
            'work_hours' => 'float',
            'overtime_approved' => 'boolean',
        ];
    }

    public function scopeForBusiness(Builder $query, ?int $businessId = null): Builder
    {
        return $query->where('business_id', $businessId ?? Tenancy::id());
    }

    public function scopeForDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('clock_in_time', $date);
    }

    public function scopeBetweenDates(Builder $query, ?string $start, ?string $end): Builder
    {
        if (! empty($start)) {
            $query->whereDate('clock_in_time', '>=', $start);
        }

        if (! empty($end)) {
            $query->whereDate('clock_in_time', '<=', $end);
        }

        return $query;
    }

    /** Rows where the employee has clocked in but not out. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotNull('clock_in_time')->whereNull('clock_out_time');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'essentials_shift_id');
    }

    /**
     * Recomputes worked hours, lateness, early leaving and overtime from the
     * clock times and the assigned shift.
     */
    public function recalculate(): void
    {
        if (empty($this->clock_in_time) || empty($this->clock_out_time)) {
            return;
        }

        $this->work_hours = round(
            $this->clock_in_time->floatDiffInHours($this->clock_out_time),
            2
        );

        $shift = $this->shift;

        if (empty($shift) || $shift->type !== 'fixed_shift'
            || empty($shift->start_time) || empty($shift->end_time)) {
            return;
        }

        $date = $this->clock_in_time->toDateString();
        $shiftStart = \Carbon\Carbon::parse($date.' '.$shift->start_time);
        $shiftEnd = \Carbon\Carbon::parse($date.' '.$shift->end_time);

        // Overnight shift.
        if ($shiftEnd->lessThan($shiftStart)) {
            $shiftEnd->addDay();
        }

        $grace = (int) ($shift->grace_period_minutes ?? 0);

        $this->late_minutes = $this->clock_in_time->greaterThan($shiftStart->copy()->addMinutes($grace))
            ? $shiftStart->diffInMinutes($this->clock_in_time) - $grace
            : 0;

        $this->early_leaving_minutes = $this->clock_out_time->lessThan($shiftEnd)
            ? $this->clock_out_time->diffInMinutes($shiftEnd)
            : 0;

        $overtimeAfter = (int) ($shift->overtime_start_after_minutes ?? 0);
        $overtimeThreshold = $shiftEnd->copy()->addMinutes($overtimeAfter);

        $this->overtime_hours = $this->clock_out_time->greaterThan($overtimeThreshold)
            ? round($overtimeThreshold->floatDiffInHours($this->clock_out_time), 2)
            : 0;
    }
}
