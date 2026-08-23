<?php

namespace App\Modules\Accounting\Models;

use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PaymentType extends Model
{
    protected $table = 'payment_types';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_cash' => 'boolean',
            'is_online' => 'boolean',
            'is_system' => 'boolean',
            'active' => 'boolean',
            'options' => 'array',
        ];
    }

    public function scopeByBusiness(Builder $query, ?int $businessId = null): Builder
    {
        return $query->where('business_id', $businessId ?? Tenancy::id());
    }

    public function scopeDefaultPaymentTypes(Builder $query): Builder
    {
        return $query->where('is_system', 1);
    }

    /**
     * @return array<int, string>
     */
    public static function forDropdown(): array
    {
        return static::byBusiness()
            ->where('active', 1)
            ->orderBy('position')
            ->pluck('name', 'id')
            ->all();
    }
}
