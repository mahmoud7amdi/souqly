<?php

namespace App\Modules\Superadmin\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SuperadminFrontendPage extends Model
{
    protected $table = 'superadmin_frontend_pages';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_shown' => 'boolean'];
    }

    public function scopeShown(Builder $query): Builder
    {
        return $query->where('is_shown', 1)->orderBy('menu_order');
    }
}
