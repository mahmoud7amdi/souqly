<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Unit extends Model
{
    use BelongsToBusiness, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'allow_decimal' => 'boolean',
            'base_unit_multiplier' => 'float',
        ];
    }

    public function sub_units(): HasMany
    {
        return $this->hasMany(Unit::class, 'base_unit_id');
    }

    public function base_unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    /**
     * @return array<int, string>
     */
    public static function forDropdown(bool $showSubUnits = false): array
    {
        $query = static::query();

        if (! $showSubUnits) {
            $query->whereNull('base_unit_id');
        }

        return $query->orderBy('actual_name')
            ->get()
            ->mapWithKeys(fn ($u) => [$u->id => $u->actual_name.' ('.$u->short_name.')'])
            ->all();
    }

    /**
     * Base unit plus its sub units, keyed by id, each with its multiplier.
     * Used by the POS/purchase screens to convert quantities.
     *
     * @return array<int, array{name: string, multiplier: float}>
     */
    public static function subUnitsWithMultiplier(int $unitId): array
    {
        $unit = static::with('sub_units')->find($unitId);

        if (empty($unit)) {
            return [];
        }

        $units = [
            $unit->id => [
                'name' => $unit->actual_name,
                'multiplier' => 1.0,
            ],
        ];

        foreach ($unit->sub_units as $subUnit) {
            $units[$subUnit->id] = [
                'name' => $subUnit->actual_name,
                'multiplier' => (float) ($subUnit->base_unit_multiplier ?: 1),
            ];
        }

        return $units;
    }
}
