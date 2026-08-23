<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    protected $guarded = ['id'];

    /**
     * Currencies for a dropdown, formatted "Country - Currency (CODE)".
     *
     * @return array<int, string>
     */
    public static function forDropdown(): array
    {
        return static::orderBy('country')
            ->get()
            ->mapWithKeys(fn ($c) => [
                $c->id => $c->country.' - '.$c->currency.' ('.$c->code.')',
            ])
            ->all();
    }
}
