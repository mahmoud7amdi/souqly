<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SellingPriceGroup extends Model
{
    use BelongsToBusiness, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', 1);
    }

    /**
     * @return array<int, string>
     */
    public static function forDropdown(bool $onlyActive = true): array
    {
        $query = static::query();

        if ($onlyActive) {
            $query->where('is_active', 1);
        }

        return $query->orderBy('name')->pluck('name', 'id')->all();
    }
}
