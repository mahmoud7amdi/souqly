<?php

namespace App\Http\Controllers;

use App\Models\BusinessLocation;
use App\Models\Transaction;
use App\Modules\InventoryManagement\Models\Inventory;
use App\Modules\InventoryManagement\Models\InventoryProduct;
use App\Services\FormattingService;
use App\Services\InventoryCountService;
use App\Services\StockService;
use App\Support\TenantRules;
use Illuminate\Http\Request;

/**
 * Physical stock counts.
 *
 * Thin, like every controller here: what a count may do to stock lives in
 * {@see InventoryCountService}, because that is where the FIFO map is touched.
 *
 * The screens are a deliberate three-step shape — open a count, enter lines
 * against it, close it — rather than one form that takes a whole shelf at once.
 * Counting a shop takes hours and is done by someone walking around with a
 * tablet, so the work has to survive being interrupted, and each line is saved
 * on its own. `close()` is the only irreversible step, and it is the only one
 * that asks for confirmation.
 */
class InventoryController extends Controller
{
    public function __construct(
        protected InventoryCountService $counts,
        protected StockService $stock,
        protected FormattingService $format,
    ) {}

    /* ================================================================
     | Listing
     ================================================================ */

    public function index(Request $request)
    {
        $this->requireModule('inventorymanagement');
        $this->permit('inventorymanagement.view');

        $records = $this->listQuery($request)
            ->with('branch')
            ->withCount(['lines', 'processedLines'])
            ->latest('created_at')
            ->paginate(25)
            ->withQueryString();

        return view('inventory.index', [
            'records' => $records,
            'totals' => $this->listTotals($request),
            'locations' => BusinessLocation::forDropdown(true),
            'statuses' => $this->statusOptions(true),
        ]);
    }

    /* ================================================================
     | Create / read / update / delete
     ================================================================ */

    public function create()
    {
        $this->requireModule('inventorymanagement');
        $this->permit('inventorymanagement.create');

        return view('inventory.create', ['locations' => BusinessLocation::forDropdown()]);
    }

    public function store(Request $request)
    {
        $this->requireModule('inventorymanagement');
        $this->permit('inventorymanagement.create');

        $validated = $request->validate([
            'branch_id' => ['required', 'integer', TenantRules::location()],
            'name' => 'required|string|max:255',
            'end_date' => 'nullable|date',
        ]);

        try {
            $count = $this->counts->create($validated);
            $output = $this->ok(__('lang_v1.added_successfully'));
        } catch (\Throwable $e) {
            return back()->withInput()->with('status', $this->failed($e));
        }

        return redirect()->route('inventory.show', $count->id)->with('status', $output);
    }

    /**
     * The counting screen: the lines entered so far, and the form to add another.
     */
    public function show(Request $request, int $id)
    {
        $this->requireModule('inventorymanagement');
        $this->permit('inventorymanagement.view');

        $count = $this->findCount($id, ['branch']);

        $lines = $count->lines()
            ->with(['variation.product.unit', 'transaction'])
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('inventory.show', [
            'record' => $count,
            'lines' => $lines,
            'summary' => $this->counts->summary($count),
            'documents' => $this->postedDocuments($count),
            'canCount' => ! $count->status && $this->allows('inventorymanagement.create'),
        ]);
    }

    public function edit(int $id)
    {
        $this->requireModule('inventorymanagement');
        $this->permit('inventorymanagement.update');

        $count = $this->findCount($id);

        if ($count->status) {
            return redirect()->route('inventory.show', $count->id)
                ->with('status', $this->failed(null, __('lang_v1.count_already_closed')));
        }

        return view('inventory.edit', [
            'record' => $count,
            'locations' => BusinessLocation::forDropdown(),
            'branchLocked' => $count->processedLines()->exists(),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $this->requireModule('inventorymanagement');
        $this->permit('inventorymanagement.update');

        $count = $this->findCount($id);

        $validated = $request->validate([
            'branch_id' => ['required', 'integer', TenantRules::location()],
            'name' => 'required|string|max:255',
            'end_date' => 'nullable|date',
        ]);

        try {
            $this->counts->update($count, $validated);
            $output = $this->ok(__('lang_v1.updated_successfully'));
        } catch (\Throwable $e) {
            return back()->withInput()->with('status', $this->failed($e));
        }

        return redirect()->route('inventory.show', $count->id)->with('status', $output);
    }

    public function destroy(Request $request, int $id)
    {
        $this->requireModule('inventorymanagement');
        $this->permit('inventorymanagement.delete');

        try {
            $this->counts->delete($this->findCount($id));
            $output = $this->ok(__('lang_v1.deleted_successfully'));
        } catch (\Throwable $e) {
            $output = $this->failed($e);
        }

        return $request->ajax()
            ? response()->json($output)
            : $this->backToIndex('inventory.index', $output);
    }

    /* ================================================================
     | Lines
     ================================================================ */

    /**
     * Record one counted quantity.
     *
     * Deliberately posts back to the counting screen rather than answering JSON:
     * the person doing this is holding a tablet and needs to see the difference
     * the system computed, because a difference of −40 usually means they scanned
     * the wrong variation and it is cheaper to catch that now than at closing.
     */
    public function storeLine(Request $request, int $id)
    {
        $this->requireModule('inventorymanagement');
        $this->permit('inventorymanagement.create');

        $count = $this->findCount($id);

        if ($count->status) {
            return back()->with('status', $this->failed(null, __('lang_v1.count_already_closed')));
        }

        $validated = $request->validate([
            'variation_id' => 'required|integer|exists:variations,id',
            'counted' => 'required|numeric|min:0',
        ]);

        try {
            $this->counts->countLine(
                $count,
                (int) $validated['variation_id'],
                $this->format->numUf($validated['counted'])
            );

            $output = $this->ok(__('lang_v1.added_successfully'));
        } catch (\Throwable $e) {
            $output = $this->failed($e);
        }

        return back()->with('status', $output);
    }

    public function destroyLine(Request $request, int $id, int $lineId)
    {
        $this->requireModule('inventorymanagement');
        $this->permit('inventorymanagement.update');

        $count = $this->findCount($id);

        try {
            $line = InventoryProduct::where('inventory_id', $count->id)->findOrFail($lineId);

            $this->counts->removeLine($line);
            $output = $this->ok(__('lang_v1.deleted_successfully'));
        } catch (\Throwable $e) {
            $output = $this->failed($e);
        }

        return $request->ajax()
            ? response()->json($output)
            : back()->with('status', $output);
    }

    /* ================================================================
     | Closing
     ================================================================ */

    /**
     * Post the count and close it.
     *
     * The one action here that moves stock, so it carries its own permission
     * rather than riding on `update`: entering counted numbers and committing
     * them to the ledger are different acts, and a shop may well want the second
     * one to need a supervisor.
     */
    public function close(Request $request, int $id)
    {
        $this->requireModule('inventorymanagement');
        $this->permit('inventorymanagement.close');

        $count = $this->findCount($id);

        if ($count->status) {
            return back()->with('status', $this->failed(null, __('lang_v1.count_already_closed')));
        }

        try {
            $result = $this->counts->close($count);

            $output = $this->ok(__('lang_v1.stock_count_closed', [
                'lines' => $this->format->quantity((float) $result['lines']),
            ]));
        } catch (\Throwable $e) {
            $output = $this->failed($e);
        }

        return redirect()->route('inventory.show', $count->id)->with('status', $output);
    }

    /* ================================================================
     | Internals
     ================================================================ */

    protected function listQuery(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        /*
         * `inventory` has no `business_id` column, so `BusinessScope` cannot
         * apply and `scopeBusiness()` reaches through the branch instead. The
         * permitted-locations filter has to be spelled out here for the same
         * reason — `Transaction::permittedLocations()` is a scope on a model that
         * has `location_id`, and this one calls it `branch_id`.
         */
        $permitted = BusinessLocation::permittedLocations();

        return Inventory::business()
            ->when($permitted !== 'all', fn ($q) => $q->whereIn('branch_id', (array) $permitted))
            ->when($request->filled('branch_id'),
                fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('status'),
                fn ($q) => $q->where('status', $request->input('status') === 'closed' ? 1 : 0))
            ->when($request->filled('search'),
                fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'));
    }

    /**
     * @return array<string, float|int>
     */
    protected function listTotals(Request $request): array
    {
        $query = $this->listQuery($request);

        return [
            'total' => $query->clone()->count(),
            'open' => $query->clone()->open()->count(),
            'closed' => $query->clone()->closed()->count(),
        ];
    }

    protected function findCount(int $id, array $with = []): Inventory
    {
        $permitted = BusinessLocation::permittedLocations();

        return Inventory::with($with)
            ->business()
            ->when($permitted !== 'all', fn ($q) => $q->whereIn('branch_id', (array) $permitted))
            ->findOrFail($id);
    }

    /**
     * The documents this count raised — at most two, one per direction.
     *
     * Read from the count as a whole rather than from the lines on screen: the
     * lines are paginated, and a "posted documents" panel that changed as you
     * paged through would be describing the page rather than the count.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Transaction>
     */
    protected function postedDocuments(Inventory $count): \Illuminate\Database\Eloquent\Collection
    {
        $ids = $count->processedLines()->distinct()->pluck('transaction_id');

        return $ids->isEmpty()
            ? Transaction::whereRaw('1 = 0')->get()
            : Transaction::whereIn('id', $ids)->orderBy('id')->get();
    }

    /**
     * @return array<string, string>
     */
    protected function statusOptions(bool $addAll = false): array
    {
        $options = [
            'open' => __('lang_v1.open'),
            'closed' => __('lang_v1.closed'),
        ];

        return $addAll ? ['' => __('lang_v1.all')] + $options : $options;
    }
}
