<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A fund transfer between two chart-of-accounts nodes.
 */
class Transfer extends Model
{
    protected $table = 'transfers';

    protected $fillable = [
        'journal_transaction_number', 'transfer_from_id', 'transfer_to_id',
        'transfer_by_id', 'amount',
    ];

    protected function casts(): array
    {
        return ['amount' => 'float'];
    }

    public function scopeForBusiness(Builder $query, ?int $businessId = null): Builder
    {
        $businessId ??= Tenancy::id();

        return $query->whereHas(
            'transfer_from',
            fn ($q) => $q->where('business_id', $businessId)
        );
    }

    public function transfer_from(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'transfer_from_id');
    }

    public function transfer_to(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'transfer_to_id');
    }

    public function transfer_by(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transfer_by_id');
    }
}
