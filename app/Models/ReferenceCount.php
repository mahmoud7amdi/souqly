<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class ReferenceCount extends Model
{
    use BelongsToBusiness;

    protected $guarded = ['id'];
}
