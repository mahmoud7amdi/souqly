<?php

namespace App\Modules\AssetManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetWarranty extends Model
{
    protected $table = 'asset_warranties';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'additional_cost' => 'float',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * Warranty length in whole months.
     */
    public function getMonthsAttribute(): int
    {
        return (int) $this->start_date->diffInMonths($this->end_date);
    }

    public function isActive(): bool
    {
        $today = now()->startOfDay();

        return $this->start_date->lessThanOrEqualTo($today)
            && $this->end_date->greaterThanOrEqualTo($today);
    }
}
