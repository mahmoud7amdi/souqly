<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class System extends Model
{
    protected $table = 'system';

    public $timestamps = false;

    protected $guarded = ['id'];

    public static function getProperty(string $key): ?string
    {
        return static::where('key', $key)->value('value');
    }

    public static function setProperty(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
