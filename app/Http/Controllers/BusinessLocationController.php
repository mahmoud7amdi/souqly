<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SimpleCrudController;
use App\Models\BusinessLocation;
use App\Models\InvoiceLayout;
use App\Models\InvoiceScheme;
use App\Models\Printer;
use App\Models\SellingPriceGroup;
use App\Models\Transaction;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

/**
 * Business locations (branches / warehouses).
 *
 * Rides {@see SimpleCrudController} for the CRUD verbs but not for its forms:
 * a location carries five foreign keys and a receipt-printing block, so it has
 * its own `location/` views instead of the generic `crud/` pair.
 *
 * Gated by the flat `business_settings.access`, like the rest of the settings
 * area — {@see ability()} returns it for every action.
 *
 * Two constraints from the schema drive the rest:
 *
 * - `invoice_scheme_id` and `invoice_layout_id` are NOT NULL with foreign keys
 *   (migration `…_000500…:173,175`), so both dropdowns are `required` and both
 *   are validated with `exists`. The sale-side pair and the price group and the
 *   printer are all nullable and stay optional.
 * - Creating a location creates a *permission* too. The whole app decides who
 *   may see which branch with `location.{id}` ({@see Permissions::forLocation()}),
 *   and {@see BusinessLocation::permittedLocations()} returns `[]` for a location
 *   whose permission does not exist — meaning a branch nobody, not even its
 *   creator, can select. It is created in the same transaction as the row.
 */
class BusinessLocationController extends SimpleCrudController
{
    protected string $model = BusinessLocation::class;

    protected string $viewPath = 'location';

    protected string $routePrefix = 'business-location';

    protected string $permission = 'business_settings.access';

    protected string $label = 'lang_v1.business_location';

    protected array $columns = [
        'name' => 'lang_v1.name',
        'location_id' => 'lang_v1.location_id',
        'city' => 'lang_v1.city',
        'mobile' => 'lang_v1.mobile',
        'is_active' => 'lang_v1.status',
    ];

    protected array $with = ['price_group', 'invoice_scheme'];

    protected function ability(string $action): string
    {
        return $this->permission;
    }

    protected function searchableColumns(): array
    {
        return ['name', 'location_id', 'city', 'mobile'];
    }

    protected function rules(Request $request, ?Model $record = null): array
    {
        return [
            'name' => 'required|string|max:256',
            'location_id' => 'nullable|string|max:255',
            'landmark' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'zip_code' => 'nullable|string|max:7',
            'mobile' => 'nullable|string|max:255',
            'alternate_number' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'website' => 'nullable|string|max:255',

            // NOT NULL columns behind foreign keys — a bad id here is a 500 at
            // the database, so it is rejected at validation instead.
            'invoice_scheme_id' => 'required|integer|exists:invoice_schemes,id',
            'invoice_layout_id' => 'required|integer|exists:invoice_layouts,id',
            'sale_invoice_scheme_id' => 'nullable|integer|exists:invoice_schemes,id',
            'sale_invoice_layout_id' => 'nullable|integer|exists:invoice_layouts,id',
            'selling_price_group_id' => 'nullable|integer|exists:selling_price_groups,id',

            'receipt_printer_type' => 'required|in:browser,printer',
            // Only a configured ESC/POS profile can drive a network printer.
            'printer_id' => 'nullable|required_if:receipt_printer_type,printer|integer|exists:printers,id',
        ];
    }

    protected function prepare(array $validated, Request $request, ?Model $record = null): array
    {
        $validated['is_active'] = $record === null ? true : $request->boolean('is_active');
        $validated['print_receipt_on_invoice'] = $request->boolean('print_receipt_on_invoice');

        // A browser receipt has no printer profile; clearing it here stops a
        // stale id surviving a switch back and forth.
        if ($validated['receipt_printer_type'] === 'browser') {
            $validated['printer_id'] = null;
        }

        return $validated;
    }

    /**
     * A new location needs its `location.{id}` permission to exist before anyone
     * can be granted access to it — including the admin who just created it.
     */
    protected function afterSave(Model $record, Request $request, bool $created): void
    {
        if (! $created) {
            return;
        }

        Permission::findOrCreate(Permissions::forLocation($record->id), 'web');
    }

    /**
     * A location with transactions cannot be removed without orphaning them; it
     * is deactivated instead, which is what the toggle on the index is for.
     */
    protected function deletionBlockedBy(Model $record): ?string
    {
        return Transaction::where('location_id', $record->id)->exists()
            ? __('lang_v1.cannot_delete_in_use', ['name' => __('lang_v1.transactions')])
            : null;
    }

    protected function indexViewData(): array
    {
        return [];
    }

    protected function formViewData(?Model $record = null): array
    {
        return [
            'invoiceSchemes' => InvoiceScheme::forDropdown(),
            'invoiceLayouts' => InvoiceLayout::forDropdown(),
            'priceGroups' => ['' => __('lang_v1.none')] + SellingPriceGroup::forDropdown(),
            'printers' => ['' => __('lang_v1.none')] + Printer::forDropdown(),
        ];
    }

    /* ================================================================
     | Beyond the base CRUD
     ================================================================ */

    /**
     * Flip a location between active and inactive.
     *
     * The soft alternative to deletion: an inactive branch keeps its history and
     * drops out of {@see BusinessLocation::forDropdown()}, so nothing new can be
     * booked against it.
     */
    public function toggleActive(Request $request, int $id)
    {
        $this->permit($this->ability('update'));

        try {
            $record = $this->findRecord($id);

            DB::transaction(fn () => $record->update(['is_active' => ! $record->is_active]));

            $output = $this->ok(__('lang_v1.updated_successfully'));
        } catch (\Throwable $e) {
            $output = $this->failed($e);
        }

        return $this->backToIndex($this->routePrefix.'.index', $output);
    }
}
