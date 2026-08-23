<?php

namespace App\Modules\Accounting\Models;

use App\Models\BusinessLocation;
use App\Models\Contact;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One side of a double-entry journal posting. Entries sharing a
 * `transaction_number` form a balanced document.
 */
class JournalEntry extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'debit' => 'float',
            'credit' => 'float',
            'balance' => 'float',
            'active' => 'boolean',
            'reversed' => 'boolean',
            'reversible' => 'boolean',
            'manual_entry' => 'boolean',
        ];
    }

    public function scopeNotReversed(Builder $query): Builder
    {
        return $query->where('reversed', 0)->where('active', 1);
    }

    public function scopeForBusiness(Builder $query, ?int $businessId = null): Builder
    {
        $businessId ??= Tenancy::id();

        return $query->whereHas(
            'chart_of_account',
            fn ($q) => $q->where('business_id', $businessId)
        );
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

    public function chart_of_account(): HasOne
    {
        return $this->hasOne(ChartOfAccount::class, 'id', 'chart_of_account_id')->withDefault();
    }

    public function business_location(): HasOne
    {
        return $this->hasOne(BusinessLocation::class, 'id', 'location_id')->withDefault();
    }

    public function created_by(): HasOne
    {
        return $this->hasOne(User::class, 'id', 'created_by_id')->withDefault();
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function cost_center(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function payment_detail(): BelongsTo
    {
        return $this->belongsTo(PaymentDetail::class, 'payment_detail_id');
    }

    /**
     * Whichever side carries a value.
     */
    public function getAmountAttribute(): float
    {
        return (float) ($this->debit ?: $this->credit);
    }
}
