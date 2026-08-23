<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SimpleCrudController;
use App\Models\Contact;
use App\Models\CustomerGroup;
use App\Models\SellingPriceGroup;
use App\Services\FormattingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Customer groups: either a flat percentage adjustment on the sell price, or a
 * pointer to a selling price group.
 */
class CustomerGroupController extends SimpleCrudController
{
    public function __construct(private FormattingService $format) {}

    protected string $model = CustomerGroup::class;

    protected string $viewPath = 'crud';

    protected string $routePrefix = 'customer-group';

    protected string $permission = 'customer';

    protected string $label = 'lang_v1.customer_group';

    protected array $columns = [
        'name' => 'lang_v1.name',
        'amount' => 'lang_v1.calculation_percent',
    ];

    protected function rules(Request $request, ?Model $record = null): array
    {
        return [
            'name' => 'required|string|max:255',
            'amount' => 'required|numeric',
            'price_calculation_type' => 'required|in:percentage,selling_price_group',
            'selling_price_group_id' => 'nullable|integer|exists:selling_price_groups,id',
        ];
    }

    protected function prepare(array $validated, Request $request, ?Model $record = null): array
    {
        $validated['amount'] = $this->format->numUf($validated['amount']);

        // The two strategies are mutually exclusive — clear the unused one so
        // the pricing code never has to guess which applies.
        if ($validated['price_calculation_type'] === 'percentage') {
            $validated['selling_price_group_id'] = null;
        } else {
            $validated['amount'] = 0;
        }

        return parent::prepare($validated, $request, $record);
    }

    protected function formViewData(?Model $record = null): array
    {
        return ['fields' => [
            ['name' => 'name', 'label' => __('lang_v1.name'), 'type' => 'text', 'required' => true],
            ['name' => 'price_calculation_type', 'label' => __('lang_v1.price_calculation_type'),
                'type' => 'select', 'required' => true, 'options' => [
                    'percentage' => __('lang_v1.percentage'),
                    'selling_price_group' => __('lang_v1.selling_price_group'),
                ]],
            ['name' => 'amount', 'label' => __('lang_v1.calculation_percent'), 'type' => 'number'],
            ['name' => 'selling_price_group_id', 'label' => __('lang_v1.selling_price_group'),
                'type' => 'select', 'options' => ['' => __('lang_v1.none')] + SellingPriceGroup::forDropdown()],
        ]];
    }

    protected function deletionBlockedBy(Model $record): ?string
    {
        return Contact::where('customer_group_id', $record->id)->exists()
            ? __('lang_v1.cannot_delete_in_use', ['name' => __('lang_v1.contacts')])
            : null;
    }
}
