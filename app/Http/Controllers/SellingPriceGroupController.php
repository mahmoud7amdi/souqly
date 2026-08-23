<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SimpleCrudController;
use App\Models\SellingPriceGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class SellingPriceGroupController extends SimpleCrudController
{
    protected string $model = SellingPriceGroup::class;

    protected string $viewPath = 'crud';

    protected string $routePrefix = 'selling-price-group';

    protected string $permission = 'product';

    protected string $label = 'lang_v1.selling_price_group';

    protected array $columns = [
        'name' => 'lang_v1.name',
        'description' => 'lang_v1.description',
        'is_active' => 'lang_v1.status',
    ];

    protected function rules(Request $request, ?Model $record = null): array
    {
        return [
            'name' => 'required|string|max:255',
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
            ['name' => 'description', 'label' => __('lang_v1.description'), 'type' => 'textarea'],
        ]];
    }

    /**
     * Groups are deactivated rather than deleted, so prices already recorded
     * against them stay intact.
     */
    public function activateDeactivate(int $id)
    {
        $this->permit('product.update');

        $group = SellingPriceGroup::findOrFail($id);
        $group->is_active = ! $group->is_active;
        $group->save();

        return $this->backToIndex(
            $this->routePrefix.'.index',
            $this->ok(__('lang_v1.updated_successfully'))
        );
    }
}
