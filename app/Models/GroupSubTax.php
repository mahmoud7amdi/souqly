<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupSubTax extends Model
{
    protected $table = 'group_sub_taxes';

    public $timestamps = false;

    protected $guarded = [];

    public function tax_rate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class, 'group_tax_id');
    }
}
