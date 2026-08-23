<?php

namespace App\Modules\Superadmin\Models;

use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Subscription extends Model
{
    use SoftDeletes;

    protected $table = 'subscriptions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'trial_end_date' => 'date',
            'end_date' => 'date',
            'package_price' => 'float',
            'package_details' => 'array',
        ];
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    public function scopeWaiting(Builder $query): Builder
    {
        return $query->where('status', 'waiting');
    }

    public function scopeDeclined(Builder $query): Builder
    {
        return $query->where('status', 'declined');
    }

    /**
     * Approved and still inside its date window.
     */
    public function scopeCurrent(Builder $query): Builder
    {
        $today = now()->toDateString();

        return $query->where('status', 'approved')
            ->where('start_date', '<=', $today)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $today));
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class)->withTrashed();
    }

    public function created_user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_id');
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'business_id');
    }

    public function isActive(): bool
    {
        if ($this->status !== 'approved') {
            return false;
        }

        return empty($this->end_date) || ! $this->end_date->isPast();
    }

    public function isInTrial(): bool
    {
        return ! empty($this->trial_end_date) && ! $this->trial_end_date->isPast();
    }

    /**
     * Quota for a resource, read from the frozen package snapshot so that
     * later edits to the package don't retroactively change entitlements.
     */
    public function quota(string $resource): int
    {
        return (int) ($this->package_details[$resource.'_count'] ?? 0);
    }
}
