<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Country extends Model
{
    protected $table = 'countries';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::addGlobalScope('order', fn (Builder $builder) => $builder->orderBy('name'));
    }

    /**
     * @return array<int, string>
     */
    public static function forDropdown(): array
    {
        return static::pluck('name', 'id')->all();
    }
}
