<?php

namespace App\Modules\Essentials\Models;

use App\Models\Business;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EssentialsOvertime extends Model
{
    protected $table = 'essentials_overtime';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'hours' => 'float',
            'rate_multiplier' => 'float',
            'amount' => 'float',
            'approved_at' => 'datetime',
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

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    public function scopeBetweenDates(Builder $query, ?string $start, ?string $end): Builder
    {
        if (! empty($start)) {
            $query->where('date', '>=', $start);
        }

        if (! empty($end)) {
            $query->where('date', '<=', $end);
        }

        return $query;
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(EssentialsAttendance::class, 'attendance_id');
    }

    /**
     * Overtime pay = hours x multiplier x the employee's hourly rate.
     */
    public function calculateAmount(float $hourlyRate): float
    {
        return round((float) $this->hours * (float) $this->rate_multiplier * $hourlyRate, 2);
    }

    public function getStatusBadgeAttribute(): string
    {
        return match ($this->status) {
            'approved' => 'badge-success',
            'pending' => 'badge-warning',
            'rejected' => 'badge-danger',
            default => 'badge-muted',
        };
    }
}
