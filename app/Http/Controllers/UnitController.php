<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SimpleCrudController;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class UnitController extends SimpleCrudController
{
    protected string $model = Unit::class;

    protected string $viewPath = 'crud';

    protected string $routePrefix = 'units';

    protected string $permission = 'unit';

    protected string $label = 'lang_v1.unit';

    protected array $with = ['base_unit'];

    protected array $columns = [
        'actual_name' => 'lang_v1.name',
        'short_name' => 'lang_v1.short_name',
    ];

    protected function searchableColumns(): array
    {
        return ['actual_name', 'short_name'];
    }

    protected function rules(Request $request, ?Model $record = null): array
    {
        return [
            'actual_name' => 'required|string|max:255',
            'short_name' => 'required|string|max:50',
            'allow_decimal' => 'nullable|boolean',
            // A sub unit names its base unit and how many base units it holds.
            'base_unit_id' => 'nullable|integer|exists:units,id',
            'base_unit_multiplier' => 'nullable|numeric|gt:0|required_with:base_unit_id',
        ];
    }

    protected function prepare(array $validated, Request $request, ?Model $record = null): array
    {
        $validated['allow_decimal'] = $request->boolean('allow_decimal');

        if (empty($validated['base_unit_id'])) {
            $validated['base_unit_id'] = null;
            $validated['base_unit_multiplier'] = null;
        }

        return parent::prepare($validated, $request, $record);
    }

    protected function formViewData(?Model $record = null): array
    {
        $baseUnits = Unit::whereNull('base_unit_id')
            ->when($record, fn ($q) => $q->where('id', '!=', $record->id))
            ->pluck('actual_name', 'id')
            ->all();

        return ['fields' => [
            ['name' => 'actual_name', 'label' => __('lang_v1.name'), 'type' => 'text', 'required' => true],
            ['name' => 'short_name', 'label' => __('lang_v1.short_name'), 'type' => 'text', 'required' => true],
            ['name' => 'allow_decimal', 'label' => __('lang_v1.allow_decimal'), 'type' => 'checkbox'],
            ['name' => 'base_unit_id', 'label' => __('lang_v1.base_unit'), 'type' => 'select',
                'options' => ['' => __('lang_v1.none')] + $baseUnits],
            ['name' => 'base_unit_multiplier', 'label' => __('lang_v1.base_unit_multiplier'), 'type' => 'number'],
        ]];
    }

    protected function deletionBlockedBy(Model $record): ?string
    {
        if (Product::where('unit_id', $record->id)->exists()) {
            return __('lang_v1.cannot_delete_in_use', ['name' => __('lang_v1.products')]);
        }

        return Unit::where('base_unit_id', $record->id)->exists()
            ? __('lang_v1.cannot_delete_in_use', ['name' => __('lang_v1.units')])
            : null;
    }
}
