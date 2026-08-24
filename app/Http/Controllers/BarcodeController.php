<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SimpleCrudController;
use App\Models\Barcode;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Barcode sticker-sheet presets (label layouts).
 *
 * Rides {@see SimpleCrudController}; the whole area is gated by the single flat
 * permission `barcode_settings.access`, so {@see ability()} returns it for every
 * action.
 *
 * The one twist is shared presets. {@see Barcode} deliberately omits the
 * `BelongsToBusiness` global scope so a tenant can *see* the seeded global sheet
 * sizes (`business_id IS NULL`, e.g. the standard Avery layouts) next to their
 * own. That read is widened in {@see indexQuery()}; the write verbs are narrowed
 * back in {@see findRecord()} so a tenant can edit and delete only their own
 * rows — a global preset every tenant shares returns 404, never an edit form.
 * `barcodes` has no `created_by`, so {@see fillableSystemColumns()} is emptied.
 */
class BarcodeController extends SimpleCrudController
{
    protected string $model = Barcode::class;

    protected string $viewPath = 'crud';

    protected string $routePrefix = 'barcodes';

    protected string $permission = 'barcode_settings.access';

    protected string $label = 'lang_v1.barcode_setting';

    protected array $columns = [
        'name' => 'lang_v1.name',
        'width' => 'lang_v1.width',
        'height' => 'lang_v1.height',
        'stickers_in_one_row' => 'lang_v1.stickers_in_one_row',
        'is_default' => 'lang_v1.default',
    ];

    protected function ability(string $action): string
    {
        return $this->permission;
    }

    /**
     * The index lists the tenant's own presets alongside the shared global ones,
     * mirroring {@see Barcode::forDropdown()}. Writes are narrower — see
     * {@see findRecord()}.
     *
     * The pair is wrapped in its own closure rather than left at the top level.
     * {@see SimpleCrudController::index()} appends the search term as a further
     * `where`, and AND binds tighter than OR: a flat
     * `where(business_id)->orWhereNull(business_id)` would compile to
     * `business_id = X OR (business_id IS NULL AND name LIKE …)`, so every one of
     * the tenant's own rows would come back no matter what was typed in the box.
     */
    protected function indexQuery(): Builder
    {
        return Barcode::query()->where(function (Builder $query) {
            $query->where('business_id', Tenancy::id())->orWhereNull('business_id');
        });
    }

    /**
     * Edit/update/delete may only touch the tenant's own rows. A global preset
     * (`business_id IS NULL`) is outside this scope and 404s, so it can be read
     * and used but never mutated.
     */
    protected function findRecord(int $id): Model
    {
        return Barcode::where('business_id', Tenancy::id())->findOrFail($id);
    }

    protected function rules(Request $request, ?Model $record = null): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
            'width' => 'nullable|numeric|min:0',
            'height' => 'nullable|numeric|min:0',
            'paper_width' => 'nullable|numeric|min:0',
            'paper_height' => 'nullable|numeric|min:0',
            'top_margin' => 'nullable|numeric|min:0',
            'left_margin' => 'nullable|numeric|min:0',
            'row_distance' => 'nullable|numeric|min:0',
            'col_distance' => 'nullable|numeric|min:0',
            'stickers_in_one_row' => 'nullable|integer|min:1',
            'stickers_in_one_sheet' => 'nullable|integer|min:1',
        ];
    }

    protected function prepare(array $validated, Request $request, ?Model $record = null): array
    {
        if (empty($record)) {
            $validated['business_id'] = Tenancy::id();
        }

        $validated['is_default'] = $request->boolean('is_default');
        $validated['is_continuous'] = $request->boolean('is_continuous');

        return $validated;
    }

    /**
     * `barcodes` has no `created_by`; the base default would try to stamp one.
     *
     * @return array<int, string>
     */
    protected function fillableSystemColumns(): array
    {
        return [];
    }

    protected function afterSave(Model $record, Request $request, bool $created): void
    {
        // Only one default sheet per tenant — setting this one clears the rest of
        // the tenant's own presets (never the shared global rows).
        if ($record->is_default) {
            Barcode::where('id', '!=', $record->id)
                ->where('business_id', Tenancy::id())
                ->update(['is_default' => false]);
        }
    }

    /**
     * Tell the index which rows are the shared global presets, so it renders a
     * "built-in" badge instead of edit/delete icons that {@see findRecord()}
     * would 404 anyway.
     *
     * @return array<string, mixed>
     */
    protected function indexViewData(): array
    {
        return ['rowLocked' => fn (Model $record): bool => $record->business_id === null];
    }

    protected function formViewData(?Model $record = null): array
    {
        return ['fields' => [
            ['name' => 'name', 'label' => __('lang_v1.name'), 'type' => 'text', 'required' => true],
            ['name' => 'description', 'label' => __('lang_v1.description'), 'type' => 'text'],
            ['name' => 'is_continuous', 'label' => __('lang_v1.continuous_roll'), 'type' => 'checkbox',
             'hint' => __('lang_v1.continuous_roll_hint')],
            ['name' => 'width', 'label' => __('lang_v1.width'), 'type' => 'number'],
            ['name' => 'height', 'label' => __('lang_v1.height'), 'type' => 'number'],
            ['name' => 'paper_width', 'label' => __('lang_v1.paper_width'), 'type' => 'number'],
            ['name' => 'paper_height', 'label' => __('lang_v1.paper_height'), 'type' => 'number'],
            ['name' => 'top_margin', 'label' => __('lang_v1.top_margin'), 'type' => 'number'],
            ['name' => 'left_margin', 'label' => __('lang_v1.left_margin'), 'type' => 'number'],
            ['name' => 'row_distance', 'label' => __('lang_v1.row_distance'), 'type' => 'number'],
            ['name' => 'col_distance', 'label' => __('lang_v1.col_distance'), 'type' => 'number'],
            ['name' => 'stickers_in_one_row', 'label' => __('lang_v1.stickers_in_one_row'), 'type' => 'number'],
            ['name' => 'stickers_in_one_sheet', 'label' => __('lang_v1.stickers_in_one_sheet'), 'type' => 'number'],
            ['name' => 'is_default', 'label' => __('lang_v1.set_as_default'), 'type' => 'checkbox'],
        ]];
    }
}
