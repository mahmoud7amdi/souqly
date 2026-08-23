<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountType extends Model
{
    protected $guarded = ['id'];

    public function sub_types(): HasMany
    {
        return $this->hasMany(AccountType::class, 'parent_account_type_id');
    }

    public function parent_account(): BelongsTo
    {
        return $this->belongsTo(AccountType::class, 'parent_account_type_id');
    }

    /**
     * @return array<int, string>
     */
    public static function forDropdown(?int $businessId = null): array
    {
        return static::where('business_id', $businessId ?? \App\Support\Tenancy::id())
            ->whereNull('parent_account_type_id')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
