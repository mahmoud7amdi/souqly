<?php

namespace App\Modules\Accounting\Models;

use App\Models\BusinessLocation;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Capital injected into (or withdrawn from) a branch.
 */
class BranchCapital extends Model
{
    protected $table = 'branch_capital';

    protected $fillable = [
        'business_id', 'location_id', 'created_by_id',
        'debit', 'credit', 'description', 'date',
    ];

    protected $appends = ['amount'];

    protected function casts(): array
    {
        return [
            'debit' => 'float',
            'credit' => 'float',
        ];
    }

    public function scopeForBusiness(Builder $query, ?int $businessId = null): Builder
    {
        return $query->where('business_id', $businessId ?? Tenancy::id());
    }

    public function created_by(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id')->withDefault();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class, 'location_id')->withDefault();
    }

    /**
     * Signed amount: credits are positive, debits negative.
     */
    public function getAmountAttribute(): float
    {
        return round((float) $this->credit - (float) $this->debit, 4);
    }
}
