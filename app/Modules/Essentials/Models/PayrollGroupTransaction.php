<?php

namespace App\Modules\Essentials\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollGroupTransaction extends Model
{
    protected $table = 'essentials_payroll_group_transactions';

    public $timestamps = false;

    protected $guarded = [];
}
