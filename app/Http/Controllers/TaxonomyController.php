<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SimpleCrudController;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Categories (and their sub categories).
 *
 * The table is polymorphic-capable (`category_type`); the product screens use
 * type `product`.
 */
class TaxonomyController extends SimpleCrudController
{
    protected string $model = Category::class;

    protected string $viewPath = 'crud';

    protected string $routePrefix = 'taxonomies';

    protected string $permission = 'category';

    protected string $label = 'lang_v1.category';

    protected array $with = ['sub_categories'];

    protected array $columns = [
        'name' => 'lang_v1.name',
        'short_code' => 'lang_v1.code',
    ];

    protected function indexQuery(): Builder
    {
        return parent::indexQuery()
            ->where('category_type', $this->type())
            ->where('parent_id', 0);
    }

    protected function searchableColumns(): array
    {
        return ['name', 'short_code'];
    }

    protected function rules(Request $request, ?Model $record = null): array
    {
        return [
            'name' => 'required|string|max:255',
            'short_code' => 'nullable|string|max:255',
            'parent_id' => 'nullable|integer|exists:categories,id',
            'description' => 'nullable|string|max:1000',
        ];
    }

    protected function prepare(array $validated, Request $request, ?Model $record = null): array
    {
        $validated['category_type'] = $this->type();
        $validated['parent_id'] = $validated['parent_id'] ?? 0;
        $validated['slug'] = \Illuminate\Support\Str::slug($validated['name']);

        return parent::prepare($validated, $request, $record);
    }

    protected function formViewData(?Model $record = null): array
    {
        $parents = Category::where('category_type', $this->type())
            ->where('parent_id', 0)
            ->when($record, fn ($q) => $q->where('id', '!=', $record->id))
            ->pluck('name', 'id')
            ->all();

        return ['fields' => [
            ['name' => 'name', 'label' => __('lang_v1.name'), 'type' => 'text', 'required' => true],
            ['name' => 'short_code', 'label' => __('lang_v1.code'), 'type' => 'text'],
            ['name' => 'parent_id', 'label' => __('lang_v1.parent_category'), 'type' => 'select',
                'options' => ['0' => __('lang_v1.none')] + $parents],
            ['name' => 'description', 'label' => __('lang_v1.description'), 'type' => 'textarea'],
        ]];
    }

    protected function deletionBlockedBy(Model $record): ?string
    {
        $inUse = Product::where('category_id', $record->id)
            ->orWhere('sub_category_id', $record->id)
            ->exists();

        if ($inUse) {
            return __('lang_v1.cannot_delete_in_use', ['name' => __('lang_v1.products')]);
        }

        return Category::where('parent_id', $record->id)->exists()
            ? __('lang_v1.cannot_delete_has_children')
            : null;
    }

    protected function type(): string
    {
        return 'product';
    }
}
