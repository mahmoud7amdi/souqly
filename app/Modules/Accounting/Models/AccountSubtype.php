<?php

namespace App\Modules\Accounting\Models;

use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AccountSubtype extends Model
{
    protected $table = 'account_subtypes';

    protected $fillable = ['business_id', 'account_type', 'name', 'description', 'active'];

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

    public function getAccountTypeNameAttribute(): string
    {
        return __('accounting.'.$this->account_type);
    }

    /**
     * @return array<int, string>
     */
    public static function forDropdown(?string $accountType = null): array
    {
        $query = static::forBusiness()->active();

        if (! is_null($accountType)) {
            $query->where('account_type', $accountType);
        }

        return $query->orderBy('name')->pluck('name', 'id')->all();
    }
}
