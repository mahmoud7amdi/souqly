<?php

namespace App\Modules\Accounting\Models;

use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountDetailType extends Model
{
    protected $table = 'account_detail_types';

    protected $fillable = [
        'business_id', 'account_subtype_id', 'name', 'description', 'active',
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', 1);
    }

    public function scopeForBusiness(Builder $query, ?int $businessId = null): Builder
    {
        return $query->where('business_id', $businessId ?? Tenancy::id());
    }

    public function account_subtype(): BelongsTo
    {
        return $this->belongsTo(AccountSubtype::class, 'account_subtype_id')->withDefault();
    }

    /**
     * @return array<int, string>
     */
    public static function forDropdown(?int $subtypeId = null): array
    {
        $query = static::forBusiness()->active();

        if (! is_null($subtypeId)) {
            $query->where('account_subtype_id', $subtypeId);
        }

        return $query->orderBy('name')->pluck('name', 'id')->all();
    }
}
