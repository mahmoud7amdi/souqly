<?php

namespace App\Http\Controllers;

use App\Models\BusinessLocation;
use App\Models\User;
use App\Modules\AssetManagement\Models\Asset;
use App\Modules\AssetManagement\Models\AssetTransaction;
use App\Services\AssetService;
use App\Support\TenantRules;
use Illuminate\Http\Request;

/**
 * The fixed-asset register.
 *
 * Thin, like every controller here: the arithmetic and every refusal live in
 * {@see AssetService}. What this class decides is which screens exist and who may
 * open them.
 *
 * The shape is a register with a working screen, not a CRUD table. `show` is where
 * an asset is actually used — allocated to somebody, taken back, given a warranty,
 * sent for maintenance — because all four of those are things you do *to a known
 * asset*, and a separate screen per verb would mean navigating away from the one
 * record you are looking at. `index` and the form are ordinary.
 *
 * Two permissions in the catalogue are about maintenance visibility
 * (`asset.view_own_maintenance`, `asset.view_all_maintenance`) and are used by
 * {@see AssetMaintenanceController}; the register itself uses the four CRUD ones.
 * Allocation is gated on `asset.update` rather than `asset.create`: handing a
 * laptop to somebody does not add an asset to the register, it changes where an
 * existing one is.
 */
class AssetController extends Controller
{
    public function __construct(protected AssetService $assets) {}

    /* ================================================================
     | Listing
     ================================================================ */

    public function index(Request $request)
    {
        $this->requireModule('assetmanagement');
        $this->permit('asset.view');

        $records = $this->listQuery($request)
            ->withListAggregates()
            ->with(['businessLocation', 'category'])
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('asset.index', [
            'records' => $records,
            'totals' => $this->assets->summary($this->listQuery($request)),
            'locations' => BusinessLocation::forDropdown(true),
            'states' => $this->stateOptions(true),
        ]);
    }

    /* ================================================================
     | Create / read / update / delete
     ================================================================ */

    public function create()
    {
        $this->requireModule('assetmanagement');
        $this->permit('asset.create');

        return view('asset.create', $this->formData());
    }

    public function store(Request $request)
    {
        $this->requireModule('assetmanagement');
        $this->permit('asset.create');

        $validated = $request->validate($this->rules());

        try {
            $asset = $this->assets->create($validated + [
                'is_allocatable' => $request->boolean('is_allocatable'),
            ]);
            $output = $this->ok(__('lang_v1.added_successfully'));
        } catch (\Throwable $e) {
            return back()->withInput()->with('status', $this->failed($e));
        }

        return redirect()->route('assets.show', $asset->id)->with('status', $output);
    }

    /**
     * The working screen: what this asset is, who is holding it, what it is covered
     * by, and what has been done to it.
     */
    public function show(int $id)
    {
        $this->requireModule('assetmanagement');
        $this->permit('asset.view');

        $asset = $this->findAsset($id, ['businessLocation', 'category', 'createdBy']);

        return view('asset.show', [
            'record' => $asset,
            /*
             * Allocations only, with their returns eager-loaded. Revocations are not
             * rows in their own right on this screen — each one belongs under the
             * allocation it closes, and listing both flat would show one movement
             * twice and make the outstanding column meaningless.
             */
            'allocations' => $asset->transactions()
                ->allocations()
                ->with(['receiverUser', 'createdBy', 'revokeTransaction'])
                ->orderByDesc('transaction_datetime')
                ->orderByDesc('id')
                ->paginate(25),
            'warranties' => $asset->warranties()->orderByDesc('end_date')->get(),
            'maintenances' => $asset->maintenances()
                ->with('assignedTo')
                ->orderByDesc('id')
                ->limit(10)
                ->get(),
            'users' => User::forDropdown(),
            // Offered only where it leads somewhere: the service refuses an
            // allocation on a non-allocatable asset or with nothing available, and a
            // form whose only outcome is an error message is a lie about what is
            // possible.
            'canAllocate' => $asset->is_allocatable
                && $asset->available_quantity > 0
                && $this->allows('asset.update'),
            'canEdit' => $this->allows('asset.update'),
            'canDelete' => $this->allows('asset.delete'),
        ]);
    }

    public function edit(int $id)
    {
        $this->requireModule('assetmanagement');
        $this->permit('asset.update');

        $asset = $this->findAsset($id);

        return view('asset.edit', $this->formData() + [
            'record' => $asset,
            /*
             * The quantity floor and the allocation switch are both constrained
             * while anything is out, and the service says so — but the form should
             * say it first, in the hint under the field, rather than letting
             * somebody type a number and lose the rest of their edit to a redirect.
             */
            'allocated' => $asset->allocated_quantity,
        ]);
    }

    public function update(Request $request, int $id)
    {
        $this->requireModule('assetmanagement');
        $this->permit('asset.update');

        $asset = $this->findAsset($id);

        $validated = $request->validate($this->rules($asset));

        try {
            $this->assets->update($asset, $validated + [
                'is_allocatable' => $request->boolean('is_allocatable'),
            ]);
            $output = $this->ok(__('lang_v1.updated_successfully'));
        } catch (\Throwable $e) {
            return back()->withInput()->with('status', $this->failed($e));
        }

        return redirect()->route('assets.show', $asset->id)->with('status', $output);
    }

    public function destroy(Request $request, int $id)
    {
        $this->requireModule('assetmanagement');
        $this->permit('asset.delete');

        try {
            $this->assets->delete($this->findAsset($id));
            $output = $this->ok(__('lang_v1.deleted_successfully'));
        } catch (\Throwable $e) {
            $output = $this->failed($e);
        }

        return $request->ajax()
            ? response()->json($output)
            : $this->backToIndex('assets.index', $output);
    }

    /* ================================================================
     | Allocation
     ================================================================ */

    public function allocate(Request $request, int $id)
    {
        $this->requireModule('assetmanagement');
        $this->permit('asset.update');

        $asset = $this->findAsset($id);

        $validated = $request->validate([
            'receiver' => ['required', 'integer', TenantRules::user()],
            'quantity' => 'required|numeric|min:0.0001',
            'transaction_datetime' => 'nullable|date',
            'allocated_upto' => 'nullable|date|after_or_equal:transaction_datetime',
            'reason' => 'nullable|string|max:1000',
        ]);

        try {
            $this->assets->allocate($asset, $validated);
            $output = $this->ok(__('assetmanagement.allocated_successfully'));
        } catch (\Throwable $e) {
            return back()->withInput()->with('status', $this->failed($e));
        }

        return redirect()->route('assets.show', $asset->id)->with('status', $output);
    }

    public function revoke(Request $request, int $id, int $transactionId)
    {
        $this->requireModule('assetmanagement');
        $this->permit('asset.update');

        $asset = $this->findAsset($id);

        $allocation = AssetTransaction::query()
            ->forBusiness()
            ->where('asset_id', $asset->id)
            ->findOrFail($transactionId);

        $validated = $request->validate([
            // Blank means all of it, which is what the row's own button submits.
            'quantity' => 'nullable|numeric|min:0.0001',
            'reason' => 'nullable|string|max:1000',
        ]);

        try {
            $this->assets->revoke($allocation, $validated);
            $output = $this->ok(__('assetmanagement.returned_successfully'));
        } catch (\Throwable $e) {
            $output = $this->failed($e);
        }

        return redirect()->route('assets.show', $asset->id)->with('status', $output);
    }

    /* ================================================================
     | Warranty
     ================================================================ */

    public function storeWarranty(Request $request, int $id)
    {
        $this->requireModule('assetmanagement');
        $this->permit('asset.update');

        $asset = $this->findAsset($id);

        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'additional_cost' => 'nullable|numeric|min:0',
            'additional_note' => 'nullable|string|max:1000',
        ]);

        try {
            $this->assets->addWarranty($asset, $validated);
            $output = $this->ok(__('lang_v1.added_successfully'));
        } catch (\Throwable $e) {
            return back()->withInput()->with('status', $this->failed($e));
        }

        return redirect()->route('assets.show', $asset->id)->with('status', $output);
    }

    public function destroyWarranty(int $id, int $warrantyId)
    {
        $this->requireModule('assetmanagement');
        $this->permit('asset.update');

        $asset = $this->findAsset($id);

        // Scoped through the asset rather than looked up by id: `asset_warranties`
        // has no `business_id` at all, so the asset is the only thing that makes
        // this row ours.
        $asset->warranties()->whereKey($warrantyId)->firstOrFail()->delete();

        return redirect()->route('assets.show', $asset->id)
            ->with('status', $this->ok(__('lang_v1.deleted_successfully')));
    }

    /* ================================================================
     | Internals
     ================================================================ */

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Asset>
     */
    protected function listQuery(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        return Asset::query()
            ->forBusiness()
            ->permitted()
            ->when($request->filled('location_id'),
                fn ($q) => $q->where('location_id', $request->integer('location_id')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';

                $q->where(fn ($inner) => $inner
                    ->where('name', 'like', $term)
                    ->orWhere('asset_code', 'like', $term)
                    ->orWhere('serial_no', 'like', $term)
                    ->orWhere('model', 'like', $term));
            })
            /*
             * "Still out" is a grouped sum, so it is resolved to a set of ids once
             * and matched against — a correlated `whereHas` cannot express it. Both
             * directions are safe when the set is empty: nothing is `whereIn []`,
             * and everything is `whereNotIn []`.
             */
            ->when($request->input('state') === 'allocated', fn ($q) => $q
                ->whereIn('id', array_keys($this->assets->outstandingByAsset()->all())))
            ->when($request->input('state') === 'available', fn ($q) => $q
                ->whereNotIn('id', array_keys($this->assets->outstandingByAsset()->all())));
    }

    protected function findAsset(int $id, array $with = []): Asset
    {
        return Asset::with($with)
            ->forBusiness()
            ->permitted()
            ->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(?Asset $record = null): array
    {
        return [
            'name' => 'required|string|max:255',
            /*
             * Unique per tenant, because two AST0007s make the register unusable —
             * the code is what the sticker on the machine says. `Rule::unique`
             * compiles to the query builder just as `Rule::exists` does (§12.6), so
             * `BusinessScope` does not run and the tenant clause is written out; it
             * matters in both directions here, since without it one tenant's codes
             * would block another's.
             */
            'asset_code' => ['nullable', 'string', 'max:255',
                \Illuminate\Validation\Rule::unique('assets', 'asset_code')
                    ->where('business_id', \App\Support\Tenancy::id())
                    ->ignore($record?->id)],
            'quantity' => 'required|numeric|min:0',
            'model' => 'nullable|string|max:255',
            'serial_no' => 'nullable|string|max:255',
            'location_id' => ['nullable', 'integer', TenantRules::location()],
            'purchase_date' => 'nullable|date',
            'purchase_type' => ['nullable', 'string', 'in:'.implode(',', array_keys(Asset::purchaseTypes()))],
            'unit_price' => 'nullable|numeric|min:0',
            // A percentage per year, so 100 is the ceiling: anything above it would
            // write off more than the asset cost inside the first year.
            'depreciation' => 'nullable|numeric|min:0|max:100',
            'description' => 'nullable|string|max:2000',
        ];
    }

    /**
     * Shared by the create and edit screens.
     *
     * No category field, and that is deliberate rather than forgotten: `category_id`
     * points at `categories`, whose only managed `category_type` is `product`, so
     * the dropdown would either offer product categories (a stockroom vocabulary
     * that says nothing about a vehicle) or offer an always-empty list. Recorded in
     * NOTES §17 with the one-line route to adding it properly.
     *
     * @return array<string, mixed>
     */
    protected function formData(): array
    {
        return [
            'locations' => ['' => __('lang_v1.none')] + BusinessLocation::forDropdown(),
            'purchaseTypes' => ['' => __('lang_v1.none')] + Asset::purchaseTypes(),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function stateOptions(bool $addAll = false): array
    {
        $options = [
            'allocated' => __('assetmanagement.state_allocated'),
            'available' => __('assetmanagement.state_available'),
        ];

        return $addAll ? ['' => __('lang_v1.all')] + $options : $options;
    }
}
