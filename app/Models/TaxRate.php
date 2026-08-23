<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TaxRate extends Model
{
    use BelongsToBusiness, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'is_tax_group' => 'boolean',
            'for_tax_group' => 'boolean',
        ];
    }

    /**
     * Hides rates that only exist as members of a tax group.
     */
    public function scopeExcludeForTaxGroup(Builder $query): Builder
    {
        return $query->where('for_tax_group', 0);
    }

    public function scopeOnlyGroups(Builder $query): Builder
    {
        return $query->where('is_tax_group', 1);
    }

    public function sub_taxes(): BelongsToMany
    {
        return $this->belongsToMany(
            TaxRate::class,
            'group_sub_taxes',
            'group_tax_id',
            'tax_id'
        );
    }

    /**
     * @return array<int, string>
     */
    public static function forDropdown(bool $excludeForTaxGroup = true): array
    {
        $query = static::query();

        if ($excludeForTaxGroup) {
            $query->where('for_tax_group', 0);
        }

        return $query->orderBy('name')
            ->get()
            ->mapWithKeys(fn ($t) => [$t->id => $t->name.' - '.$t->amount.'%'])
            ->all();
    }

    /**
     * Tax rate + amount pairs, used to compute line tax client-side.
     *
     * @return array<int, float>
     */
    public static function amountsById(): array
    {
        return static::pluck('amount', 'id')
            ->map(fn ($a) => (float) $a)
            ->all();
    }
}
