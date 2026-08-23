<?php

namespace App\Modules\Essentials\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sales commission tier: earn `commission_percent` when sales fall between
 * target_start and target_end.
 */
class EssentialsUserSalesTarget extends Model
{
    protected $table = 'essentials_user_sales_targets';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'target_start' => 'float',
            'target_end' => 'float',
            'commission_percent' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * True when the given sales figure falls in this tier.
     */
    public function covers(float $totalSales): bool
    {
        return $totalSales >= (float) $this->target_start
            && $totalSales <= (float) $this->target_end;
    }
}
