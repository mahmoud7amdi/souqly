<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class TypesOfService extends Model
{
    use BelongsToBusiness;

    protected $table = 'types_of_services';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'location_price_group' => 'array',
            'enable_custom_fields' => 'boolean',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function forDropdown(): array
    {
        return static::orderBy('name')->pluck('name', 'id')->all();
    }
}
