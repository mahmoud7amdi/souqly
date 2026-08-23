<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExpenseCategory extends Model
{
    use BelongsToBusiness, SoftDeletes;

    protected $table = 'expense_categories';

    protected $guarded = ['id'];

    public function scopeOnlyParent(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    public function sub_categories(): HasMany
    {
        return $this->hasMany(ExpenseCategory::class, 'parent_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'parent_id');
    }

    /**
     * @return array<int, string>
     */
    public static function forDropdown(bool $onlyParent = true): array
    {
        $query = static::query();

        if ($onlyParent) {
            $query->whereNull('parent_id');
        }

        return $query->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * Parents and their children as one flat picker, children indented under
     * the parent they belong to.
     *
     * A tree in a `<select>` is a lie — the browser renders it flat whatever the
     * markup says — so the nesting is carried by the label. `<optgroup>` was the
     * other option and is worse here: a parent category is itself selectable, and
     * an optgroup label is not.
     *
     * @return array<int, string>
     */
    public static function forDropdownWithSubs(): array
    {
        $options = [];

        foreach (static::with('sub_categories')->onlyParent()->orderBy('name')->get() as $parent) {
            $options[$parent->id] = $parent->name;

            foreach ($parent->sub_categories->sortBy('name') as $child) {
                $options[$child->id] = '— '.$child->name;
            }
        }

        return $options;
    }

    /**
     * Sub-categories grouped by the parent they belong to.
     *
     * The expense form has two selects where the second depends on the first.
     * Shipping the whole map once and filtering it in the browser beats an AJAX
     * round trip per change: the data is a few dozen rows, and a dependent
     * dropdown that pauses on a network call feels broken.
     *
     * @return array<int, array<int, string>>
     */
    public static function subCategoriesByParent(): array
    {
        return static::whereNotNull('parent_id')
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id'])
            ->groupBy('parent_id')
            ->map(fn ($group) => $group->pluck('name', 'id')->all())
            ->all();
    }
}
