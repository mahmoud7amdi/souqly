<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class Warranty extends Model
{
    use BelongsToBusiness;

    protected $table = 'warranties';

    protected $guarded = ['id'];

    /**
     * "12 Months warranty" style label.
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->name.' - '.$this->duration.' '.__('lang_v1.'.$this->duration_type);
    }

    /**
     * @return array<int, string>
     */
    public static function forDropdown(): array
    {
        return static::orderBy('name')->pluck('name', 'id')->all();
    }
}
