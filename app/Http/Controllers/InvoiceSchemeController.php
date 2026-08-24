<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SimpleCrudController;
use App\Models\BusinessLocation;
use App\Models\InvoiceScheme;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Invoice numbering schemes.
 *
 * A plain tenant-scoped CRUD, so it rides {@see SimpleCrudController} — but the
 * source system gates the whole invoice-settings area behind one flat
 * permission, `invoice_settings.access`, rather than the four-verb group the
 * base assumes. {@see ability()} returns that one name for every action.
 */
class InvoiceSchemeController extends SimpleCrudController
{
    protected string $model = InvoiceScheme::class;

    protected string $viewPath = 'crud';

    protected string $routePrefix = 'invoice-schemes';

    protected string $permission = 'invoice_settings.access';

    protected string $label = 'lang_v1.invoice_scheme';

    protected array $columns = [
        'name' => 'lang_v1.name',
        'prefix' => 'lang_v1.prefix',
        'start_number' => 'lang_v1.start_from',
        'invoice_count' => 'lang_v1.invoice_count',
    ];

    protected function ability(string $action): string
    {
        // One flat permission guards create/read/update/delete alike.
        return $this->permission;
    }

    protected function rules(Request $request, ?Model $record = null): array
    {
        return [
            'name' => 'required|string|max:255',
            'scheme_type' => 'required|in:blank,year',
            'number_type' => 'required|in:sequential,random',
            'prefix' => 'nullable|string|max:255',
            'start_number' => 'required|integer|min:0',
            'total_digits' => 'required|integer|min:1|max:12',
        ];
    }

    protected function prepare(array $validated, Request $request, ?Model $record = null): array
    {
        // The counter is owned by generateNumber(); a new scheme starts at zero
        // and an edit must never rewind it, so it is never taken from input.
        if (empty($record)) {
            $validated['invoice_count'] = 0;
        }

        // Only one default per tenant — a checkbox that, when set, clears the rest.
        $validated['is_default'] = $request->boolean('is_default');

        return parent::prepare($validated, $request, $record);
    }

    protected function afterSave(Model $record, Request $request, bool $created): void
    {
        if ($record->is_default) {
            InvoiceScheme::where('id', '!=', $record->id)->update(['is_default' => false]);
        }
    }

    protected function deletionBlockedBy(Model $record): ?string
    {
        // A location points at a scheme with a NOT NULL foreign key, so a scheme
        // still in use cannot be deleted without orphaning a location's numbering.
        $inUse = BusinessLocation::where('invoice_scheme_id', $record->id)
            ->orWhere('sale_invoice_scheme_id', $record->id)
            ->exists();

        return $inUse
            ? __('lang_v1.cannot_delete_in_use', ['name' => __('lang_v1.business_locations')])
            : null;
    }

    protected function formViewData(?Model $record = null): array
    {
        return ['fields' => [
            ['name' => 'name', 'label' => __('lang_v1.name'), 'type' => 'text', 'required' => true],
            ['name' => 'prefix', 'label' => __('lang_v1.prefix'), 'type' => 'text',
             'hint' => __('lang_v1.invoice_prefix_hint')],
            ['name' => 'scheme_type', 'label' => __('lang_v1.scheme_type'), 'type' => 'select',
             'required' => true, 'options' => [
                 'blank' => __('lang_v1.scheme_blank'),
                 'year' => __('lang_v1.scheme_year'),
             ], 'hint' => __('lang_v1.scheme_type_hint')],
            ['name' => 'number_type', 'label' => __('lang_v1.number_type'), 'type' => 'select',
             'required' => true, 'options' => [
                 'sequential' => __('lang_v1.sequential'),
                 'random' => __('lang_v1.random'),
             ]],
            ['name' => 'start_number', 'label' => __('lang_v1.start_from'), 'type' => 'number', 'required' => true],
            ['name' => 'total_digits', 'label' => __('lang_v1.total_digits'), 'type' => 'number', 'required' => true],
            ['name' => 'is_default', 'label' => __('lang_v1.set_as_default'), 'type' => 'checkbox'],
        ]];
    }
}
