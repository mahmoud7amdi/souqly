<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DashboardConfiguration extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['configuration' => 'array'];
    }
}
