<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SimpleCrudController;
use App\Models\Product;
use App\Models\TaxRate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class TaxRateController extends SimpleCrudController
{
    protected string $model = TaxRate::class;

    protected string $viewPath = 'crud';

    protected string $routePrefix = 'tax-rates';

    protected string $permission = 'tax_rate';

    protected string $label = 'lang_v1.tax_rate';

    protected array $columns = [
        'name' => 'lang_v1.name',
        'amount' => 'lang_v1.rate_percent',
    ];

    protected function indexQuery(): Builder
    {
        // Members of a tax group are managed from the group screen.
        return parent::indexQuery()->where('for_tax_group', 0);
    }

    protected function rules(Request $request, ?Model $record = null): array
    {
        return [
            'name' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0|max:100',
        ];
    }

    protected function prepare(array $validated, Request $request, ?Model $record = null): array
    {
        $validated['calculation_type'] = 'percentage';

        return parent::prepare($validated, $request, $record);
    }

    protected function formViewData(?Model $record = null): array
    {
        return ['fields' => [
            ['name' => 'name', 'label' => __('lang_v1.name'), 'type' => 'text', 'required' => true],
            ['name' => 'amount', 'label' => __('lang_v1.rate_percent'), 'type' => 'number', 'required' => true],
        ]];
    }

    protected function deletionBlockedBy(Model $record): ?string
    {
        return Product::where('tax', $record->id)->exists()
            ? __('lang_v1.cannot_delete_in_use', ['name' => __('lang_v1.products')])
            : null;
    }
}
