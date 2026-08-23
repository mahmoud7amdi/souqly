<?php

namespace App\Modules\Essentials\Models;

use App\Models\Business;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Yearly leave entitlement and consumption per employee / leave type.
 */
class EssentialsLeaveBalance extends Model
{
    protected $table = 'essentials_leave_balances';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'allocated' => 'float',
            'used' => 'float',
            'carried_forward' => 'float',
            'adjusted' => 'float',
            'balance' => 'float',
        ];
    }

    public function scopeForBusiness(Builder $query, ?int $businessId = null): Builder
    {
        return $query->where('business_id', $businessId ?? Tenancy::id());
    }

    public function scopeForYear(Builder $query, int $year): Builder
    {
        return $query->where('year', $year);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(EssentialsLeaveType::class, 'leave_type_id');
    }

    /**
     * balance = allocated + carried_forward + adjusted − used
     */
    public function recalculate(): void
    {
        $this->balance = round(
            (float) $this->allocated
            + (float) $this->carried_forward
            + (float) $this->adjusted
            - (float) $this->used,
            2
        );
    }
}
