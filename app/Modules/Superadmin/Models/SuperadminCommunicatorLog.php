<?php

namespace App\Modules\Superadmin\Models;

use Illuminate\Database\Eloquent\Model;

class SuperadminCommunicatorLog extends Model
{
    protected $table = 'superadmin_communicator_logs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['business_ids' => 'array'];
    }
}
