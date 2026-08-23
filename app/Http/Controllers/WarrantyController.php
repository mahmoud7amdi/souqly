<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SimpleCrudController;
use App\Models\Product;
use App\Models\Warranty;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class WarrantyController extends SimpleCrudController
{
    protected string $model = Warranty::class;

    protected string $viewPath = 'crud';

    protected string $routePrefix = 'warranties';

    protected string $permission = 'product';

    protected string $label = 'lang_v1.warranty';

    protected array $columns = [
        'name' => 'lang_v1.name',
        'duration' => 'lang_v1.duration',
        'description' => 'lang_v1.description',
    ];

    protected function rules(Request $request, ?Model $record = null): array
    {
        return [
            'name' => 'required|string|max:255',
            'duration' => 'required|integer|min:1',
            'duration_type' => 'required|in:days,months,years',
            'description' => 'nullable|string|max:1000',
        ];
    }

    protected function fillableSystemColumns(): array
    {
        return [];
    }

    protected function formViewData(?Model $record = null): array
    {
        return ['fields' => [
            ['name' => 'name', 'label' => __('lang_v1.name'), 'type' => 'text', 'required' => true],
            ['name' => 'duration', 'label' => __('lang_v1.duration'), 'type' => 'number', 'required' => true],
            ['name' => 'duration_type', 'label' => __('lang_v1.duration_type'), 'type' => 'select',
                'required' => true, 'options' => [
                    'days' => __('lang_v1.days'),
                    'months' => __('lang_v1.months'),
                    'years' => __('lang_v1.years'),
                ]],
            ['name' => 'description', 'label' => __('lang_v1.description'), 'type' => 'textarea'],
        ]];
    }

    protected function deletionBlockedBy(Model $record): ?string
    {
        return Product::where('warranty_id', $record->id)->exists()
            ? __('lang_v1.cannot_delete_in_use', ['name' => __('lang_v1.products')])
            : null;
    }
}
