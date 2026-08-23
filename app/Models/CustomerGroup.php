<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomerGroup extends Model
{
    use BelongsToBusiness, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'float'];
    }

    public function price_group(): BelongsTo
    {
        return $this->belongsTo(SellingPriceGroup::class, 'selling_price_group_id');
    }

    /**
     * @return array<int, string>
     */
    public static function forDropdown(bool $prependNone = true): array
    {
        $groups = static::orderBy('name')->pluck('name', 'id')->all();

        if ($prependNone) {
            $groups = ['' => __('lang_v1.none')] + $groups;
        }

        return $groups;
    }
}
