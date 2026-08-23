<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends Model
{
    use BelongsToBusiness, SoftDeletes;

    protected $table = 'categories';

    protected $guarded = ['id'];

    /**
     * Top-level categories only.
     */
    public function scopeOnlyParent(Builder $query): Builder
    {
        return $query->where('parent_id', 0);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('category_type', $type);
    }

    public function sub_categories(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /**
     * @return array<int, string>
     */
    public static function forDropdown(string $type = 'product', bool $onlyParent = true): array
    {
        $query = static::where('category_type', $type);

        if ($onlyParent) {
            $query->where('parent_id', 0);
        }

        return $query->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * Sub categories of a given parent, for the cascading dropdown.
     *
     * @return array<int, string>
     */
    public static function subCategoriesForDropdown(int $parentId): array
    {
        return static::where('parent_id', $parentId)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
