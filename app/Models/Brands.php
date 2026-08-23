<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Brands extends Model
{
    use BelongsToBusiness, SoftDeletes;

    protected $table = 'brands';

    protected $guarded = ['id'];

    /**
     * @return array<int, string>
     */
    public static function forDropdown(bool $prependNone = false): array
    {
        $brands = static::orderBy('name')->pluck('name', 'id')->all();

        if ($prependNone) {
            $brands = ['' => __('lang_v1.none')] + $brands;
        }

        return $brands;
    }
}
