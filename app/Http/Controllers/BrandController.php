<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SimpleCrudController;
use App\Models\Brands;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BrandController extends SimpleCrudController
{
    protected string $model = Brands::class;

    protected string $viewPath = 'crud';

    protected string $routePrefix = 'brands';

    protected string $permission = 'brand';

    protected string $label = 'lang_v1.brand';

    protected array $columns = [
        'name' => 'lang_v1.name',
        'description' => 'lang_v1.description',
    ];

    protected function rules(Request $request, ?Model $record = null): array
    {
        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('brands', 'name')
                    ->where('business_id', \App\Support\Tenancy::id())
                    ->whereNull('deleted_at')
                    ->ignore($record?->id),
            ],
            'description' => 'nullable|string|max:1000',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function formViewData(?Model $record = null): array
    {
        return ['fields' => [
            ['name' => 'name', 'label' => __('lang_v1.name'), 'type' => 'text', 'required' => true],
            ['name' => 'description', 'label' => __('lang_v1.description'), 'type' => 'textarea'],
        ]];
    }

    protected function deletionBlockedBy(Model $record): ?string
    {
        return Product::where('brand_id', $record->id)->exists()
            ? __('lang_v1.cannot_delete_in_use', ['name' => __('lang_v1.products')])
            : null;
    }
}
