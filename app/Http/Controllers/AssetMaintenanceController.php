<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\AssetManagement\Models\Asset;
use App\Modules\AssetManagement\Models\AssetMaintenance;
use App\Services\AssetService;
use App\Support\TenantRules;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Maintenance jobs against assets.
 *
 * A separate screen from the register, unlike warranties and allocations, for one
 * reason: the person who reads this list is usually not the person who reads the
 * register. A technician opens it to see what is assigned to them across every
 * asset; the register is organised the other way round. So `asset.show` shows the
 * last ten jobs on that one asset and links here, and here is where the work is
 * actually tracked.
 *
 * **Visibility is what the two maintenance permissions control, and nothing else.**
 * `asset.view_all_maintenance` sees the whole floor; `asset.view_own_maintenance`
 * sees the jobs it raised or was assigned. Writing is gated on the register's own
 * CRUD permissions — a maintenance job is a thing that happens to an asset, so
 * `asset.create` raises one and `asset.update` changes one. The alternative was
 * inventing `asset.maintenance.create`, which would mean three more permissions,
 * six more translation keys, and a catalogue entry for something the four existing
 * verbs already describe.
 *
 * The consequence is worth stating plainly, because it is a real one: a technician
 * holding `asset.view_own_maintenance` and `asset.update` can move their own job
 * from scheduled to completed, which is the point — but the same `asset.update`
 * also lets them edit the asset itself. Splitting that apart needs a permission
 * that does not exist yet; it is recorded in NOTES §17 rather than papered over.
 */
class AssetMaintenanceController extends Controller
{
    public function __construct(protected AssetService $assets) {}

    /* ================================================================
     | Listing
     ================================================================ */

    public function index(Request $request)
    {
        $this->requireModule('assetmanagement');
        $this->permit('asset.view_own_maintenance', 'asset.view_all_maintenance');

        $records = $this->listQuery($request)
            ->with(['asset', 'assignedTo', 'createdBy'])
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('asset_maintenance.index', [
            'records' => $records,
            'totals' => $this->listTotals($request),
            'statuses' => ['' => __('lang_v1.all')] + AssetMaintenance::statuses(),
            'priorities' => ['' => __('lang_v1.all')] + AssetMaintenance::priorities(),
            'users' => ['' => __('lang_v1.all')] + User::forDropdown(),
            'assets' => ['' => __('lang_v1.all')] + $this->assetOptions(),
            // A technician sees a list with no user filter on it: every row already
            // belongs to them, so the control would only ever narrow to nothing.
            'showUserFilter' => ! $this->ownRowsOnly(),
        ]);
    }

    /* ================================================================
     | Create / update / delete
     |
     | No `show`. A maintenance job is four fields and a note — the edit form is a
     | strictly better view of it than a read-only screen would be, and a
     | technician's first act on opening one is normally to change its status.
     ================================================================ */

    public function create(Request $request)
    {
        $this->requireModule('assetmanagement');
        $this->permit('asset.create');

        return view('asset_maintenance.create', $this->formData() + [
            // Arriving from an asset's own screen preselects it, so raising a job
            // from there does not mean finding the asset again in a dropdown.
            'presetAssetId' => $request->integer('asset_id') ?: null,
        ]);
    }

    public function store(Request $request)
    {
        $this->requireModule('assetmanagement');
        $this->permit('asset.create');

        $validated = $request->validate($this->rules());

        try {
            $maintenance = $this->assets->createMaintenance(
                $this->findAsset((int) $validated['asset_id']),
                $validated
            );
            $output = $this->ok(__('lang_v1.added_successfully'));
        } catch (\Throwable $e) {
            return back()->withInput()->with('status', $this->failed($e));
        }

        return redirect()->route('asset-maintenance.edit', $maintenance->id)->with('status', $output);
    }

    public function edit(int $id)
    {
        $this->requireModule('assetmanagement');
        $this->permit('asset.update');

        return view('asset_maintenance.edit', $this->formData() + [
            'record' => $this->findMaintenance($id, ['asset']),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $this->requireModule('assetmanagement');
        $this->permit('asset.update');

        $maintenance = $this->findMaintenance($id);

        // `asset_id` is not in the update rules: moving a job to a different asset
        // is not a correction, it is a different job. Raise that one and delete this.
        $validated = $request->validate($this->rules(forUpdate: true));

        try {
            $this->assets->updateMaintenance($maintenance, $validated);
            $output = $this->ok(__('lang_v1.updated_successfully'));
        } catch (\Throwable $e) {
            return back()->withInput()->with('status', $this->failed($e));
        }

        return redirect()->route('asset-maintenance.index')->with('status', $output);
    }

    public function destroy(Request $request, int $id)
    {
        $this->requireModule('assetmanagement');
        $this->permit('asset.delete');

        try {
            $this->assets->deleteMaintenance($this->findMaintenance($id));
            $output = $this->ok(__('lang_v1.deleted_successfully'));
        } catch (\Throwable $e) {
            $output = $this->failed($e);
        }

        return $request->ajax()
            ? response()->json($output)
            : $this->backToIndex('asset-maintenance.index', $output);
    }

    /* ================================================================
     | Internals
     ================================================================ */

    /**
     * @return \Illuminate\Database\Eloquent\Builder<AssetMaintenance>
     */
    protected function listQuery(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        return AssetMaintenance::query()
            ->forBusiness()
            /*
             * The asset side of the restriction, which the maintenance table cannot
             * express on its own: a job at a branch this user cannot reach is not
             * theirs to read, and `asset_maintenances` has no `location_id`.
             * `whereHas` rather than a join, so the paginator's own count stays
             * correct.
             */
            ->whereHas('asset', fn ($q) => $q->permitted())
            ->when($this->ownRowsOnly(), fn ($q) => $q->where(
                fn ($inner) => $inner
                    ->where('created_by', auth()->id())
                    ->orWhere('assigned_to', auth()->id())
            ))
            ->when($request->filled('status'),
                fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('priority'),
                fn ($q) => $q->where('priority', $request->string('priority')))
            ->when($request->filled('asset_id'),
                fn ($q) => $q->where('asset_id', $request->integer('asset_id')))
            ->when($request->filled('assigned_to') && ! $this->ownRowsOnly(),
                fn ($q) => $q->where('assigned_to', $request->integer('assigned_to')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';

                $q->where(fn ($inner) => $inner
                    ->where('maitenance_id', 'like', $term)
                    ->orWhere('details', 'like', $term)
                    ->orWhereHas('asset', fn ($a) => $a
                        ->where('name', 'like', $term)
                        ->orWhere('asset_code', 'like', $term)));
            });
    }

    /**
     * @return array<string, int>
     */
    protected function listTotals(Request $request): array
    {
        $query = $this->listQuery($request);

        return [
            'total' => $query->clone()->count(),
            'scheduled' => $query->clone()->withStatus('scheduled')->count(),
            'in_progress' => $query->clone()->withStatus('in_progress')->count(),
            'completed' => $query->clone()->withStatus('completed')->count(),
        ];
    }

    protected function findMaintenance(int $id, array $with = []): AssetMaintenance
    {
        // The same narrowing as the list, applied before `findOrFail`: a technician
        // must not be able to open somebody else's job by typing its id.
        return $this->listQuery(request())->with($with)->findOrFail($id);
    }

    protected function findAsset(int $id): Asset
    {
        return Asset::query()->forBusiness()->permitted()->findOrFail($id);
    }

    /**
     * A cashier reads their own jobs; a manager reads the floor.
     */
    protected function ownRowsOnly(): bool
    {
        return ! $this->allows('asset.view_all_maintenance');
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(bool $forUpdate = false): array
    {
        $rules = [
            'maintenance_ref' => 'nullable|string|max:255',
            'status' => ['required', Rule::in(array_keys(AssetMaintenance::statuses()))],
            'priority' => ['required', Rule::in(array_keys(AssetMaintenance::priorities()))],
            'assigned_to' => ['nullable', 'integer', TenantRules::user()],
            'details' => 'nullable|string|max:2000',
            'maintenance_note' => 'nullable|string|max:2000',
        ];

        if (! $forUpdate) {
            /*
             * A 422 rather than the 404 `findAsset()` would give, because a foreign
             * id in a select is a rejected value and should read like one. The
             * location restriction is still enforced afterwards by `findAsset()`,
             * which is the only check that can see it.
             */
            $rules['asset_id'] = ['required', 'integer',
                Rule::exists('assets', 'id')->where('business_id', \App\Support\Tenancy::id())];
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    protected function formData(): array
    {
        return [
            'assets' => $this->assetOptions(),
            'statuses' => AssetMaintenance::statuses(),
            'priorities' => AssetMaintenance::priorities(),
            'users' => ['' => __('lang_v1.unassigned')] + User::forDropdown(),
        ];
    }

    /**
     * Assets this user may raise a job against, labelled by code as well as name —
     * a shop with nine identical monitors needs the code to tell them apart, and the
     * code is what is written on the machine.
     *
     * @return array<int, string>
     */
    protected function assetOptions(): array
    {
        return Asset::query()
            ->forBusiness()
            ->permitted()
            ->orderBy('name')
            ->get(['id', 'name', 'asset_code'])
            ->mapWithKeys(fn (Asset $asset) => [
                $asset->id => $asset->name.' — '.$asset->asset_code,
            ])
            ->all();
    }
}
