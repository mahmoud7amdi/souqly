<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Barcode extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_continuous' => 'boolean',
        ];
    }

    /**
     * Barcode sticker sheet settings for the current business (plus the
     * global presets, which have a null business_id).
     *
     * @return array<int, string>
     */
    public static function forDropdown(): array
    {
        return static::where('business_id', \App\Support\Tenancy::id())
            ->orWhereNull('business_id')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
