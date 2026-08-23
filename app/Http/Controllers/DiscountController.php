<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SimpleCrudController;
use App\Models\Brands;
use App\Models\BusinessLocation;
use App\Models\Category;
use App\Models\Discount;
use App\Models\SellingPriceGroup;
use App\Services\FormattingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Time-boxed discounts, targetable by product, brand, category and location.
 */
class DiscountController extends SimpleCrudController
{
    public function __construct(private FormattingService $format) {}

    protected string $model = Discount::class;

    protected string $viewPath = 'crud';

    protected string $routePrefix = 'discount';

    protected string $permission = 'discount';

    protected string $label = 'lang_v1.discount';

    protected array $with = ['brand', 'category', 'location'];

    protected array $columns = [
        'name' => 'lang_v1.name',
        'discount_amount' => 'lang_v1.discount_amount',
        'priority' => 'lang_v1.priority',
        'is_active' => 'lang_v1.status',
    ];

    /**
     * `discount.access` is the single gate for this screen, so map the
     * granular permissions onto it.
     */
    protected function permit(string ...$permissions): void
    {
        parent::permit('discount.access');
    }

    protected function rules(Request $request, ?Model $record = null): array
    {
        return [
            'name' => 'required|string|max:255',
            'discount_type' => 'required|in:fixed,percentage',
            'discount_amount' => 'required|numeric|min:0',
            'priority' => 'nullable|integer|min:0',
            'brand_id' => 'nullable|integer|exists:brands,id',
            'category_id' => 'nullable|integer|exists:categories,id',
            'location_id' => 'nullable|integer|exists:business_locations,id',
            'spg' => 'nullable|integer|exists:selling_price_groups,id',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
        ];
    }

    protected function prepare(array $validated, Request $request, ?Model $record = null): array
    {
        $validated['discount_amount'] = $this->format->numUf($validated['discount_amount']);
        $validated['is_active'] = $request->boolean('is_active', true);

        return parent::prepare($validated, $request, $record);
    }

    protected function fillableSystemColumns(): array
    {
        return [];
    }

    protected function formViewData(?Model $record = null): array
    {
        return ['fields' => [
            ['name' => 'name', 'label' => __('lang_v1.name'), 'type' => 'text', 'required' => true],
            ['name' => 'discount_type', 'label' => __('lang_v1.discount_type'), 'type' => 'select',
                'required' => true, 'options' => [
                    'percentage' => __('lang_v1.percentage'),
                    'fixed' => __('lang_v1.fixed'),
                ]],
            ['name' => 'discount_amount', 'label' => __('lang_v1.discount_amount'),
                'type' => 'number', 'required' => true],
            ['name' => 'priority', 'label' => __('lang_v1.priority'), 'type' => 'number'],
            ['name' => 'brand_id', 'label' => __('lang_v1.brand'), 'type' => 'select',
                'options' => ['' => __('lang_v1.all')] + Brands::forDropdown()],
            ['name' => 'category_id', 'label' => __('lang_v1.category'), 'type' => 'select',
                'options' => ['' => __('lang_v1.all')] + Category::forDropdown()],
            ['name' => 'location_id', 'label' => __('lang_v1.location'), 'type' => 'select',
                'options' => ['' => __('lang_v1.all')] + BusinessLocation::forDropdown()],
            ['name' => 'spg', 'label' => __('lang_v1.selling_price_group'), 'type' => 'select',
                'options' => ['' => __('lang_v1.all')] + SellingPriceGroup::forDropdown()],
            ['name' => 'starts_at', 'label' => __('lang_v1.starts_at'), 'type' => 'datetime-local'],
            ['name' => 'ends_at', 'label' => __('lang_v1.ends_at'), 'type' => 'datetime-local'],
            ['name' => 'is_active', 'label' => __('lang_v1.active'), 'type' => 'checkbox'],
        ]];
    }

    public function activate(int $id)
    {
        parent::permit('discount.access');

        $discount = Discount::findOrFail($id);
        $discount->is_active = ! $discount->is_active;
        $discount->save();

        return $this->backToIndex(
            $this->routePrefix.'.index',
            $this->ok(__('lang_v1.updated_successfully'))
        );
    }
}
