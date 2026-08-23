<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VariationTemplate extends Model
{
    use BelongsToBusiness;

    protected $guarded = ['id'];

    public function values(): HasMany
    {
        return $this->hasMany(VariationValueTemplate::class);
    }

    /**
     * @return array<int, string>
     */
    public static function forDropdown(): array
    {
        return static::orderBy('name')->pluck('name', 'id')->all();
    }
}
