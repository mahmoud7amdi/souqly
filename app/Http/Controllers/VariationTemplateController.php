<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SimpleCrudController;
use App\Models\ProductVariation;
use App\Models\VariationTemplate;
use App\Models\VariationValueTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Reusable variation templates (e.g. "Size" → S, M, L) used when building a
 * variable product.
 */
class VariationTemplateController extends SimpleCrudController
{
    protected string $model = VariationTemplate::class;

    protected string $viewPath = 'variation_template';

    protected string $routePrefix = 'variation-templates';

    protected string $permission = 'product';

    protected string $label = 'lang_v1.variation_template';

    protected array $with = ['values'];

    protected array $columns = ['name' => 'lang_v1.name'];

    protected function rules(Request $request, ?Model $record = null): array
    {
        return [
            'name' => 'required|string|max:255',
            'values' => 'required|array|min:1',
            'values.*' => 'nullable|string|max:255',
        ];
    }

    protected function prepare(array $validated, Request $request, ?Model $record = null): array
    {
        // Values live in their own table; keep them out of the model fill.
        unset($validated['values']);

        return parent::prepare($validated, $request, $record);
    }

    protected function fillableSystemColumns(): array
    {
        return [];
    }

    /**
     * Replace the template's values, refusing to drop any that a product is
     * already built on.
     */
    protected function afterSave(Model $record, Request $request, bool $created): void
    {
        $submitted = collect($request->input('values', []))
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values();

        $existing = VariationValueTemplate::where('variation_template_id', $record->id)
            ->get()
            ->keyBy('name');

        foreach ($submitted as $name) {
            if (! $existing->has($name)) {
                VariationValueTemplate::create([
                    'variation_template_id' => $record->id,
                    'name' => $name,
                ]);
            }
        }

        // Remove values no longer submitted — unless a variation references them.
        foreach ($existing as $name => $value) {
            if ($submitted->contains($name)) {
                continue;
            }

            $inUse = \App\Models\Variation::where('variation_value_id', $value->id)->exists();

            if (! $inUse) {
                $value->delete();
            }
        }
    }

    protected function deletionBlockedBy(Model $record): ?string
    {
        return ProductVariation::where('variation_template_id', $record->id)->exists()
            ? __('lang_v1.cannot_delete_in_use', ['name' => __('lang_v1.products')])
            : null;
    }
}
