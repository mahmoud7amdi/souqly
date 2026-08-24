<?php

namespace App\Http\Controllers;

use App\Models\BusinessLocation;
use App\Models\Transaction;
use App\Services\FormattingService;
use App\Services\StockTransferService;
use App\Support\TransactionTypes;
use Illuminate\Http\Request;

/**
 * Stock transfers between the tenant's own locations.
 *
 * The listing is of out-legs only. A transfer is two documents (see
 * {@see StockTransferService}) but it is one event to the person who arranged
 * the van, and showing both halves would double every row and invite somebody to
 * delete the wrong one. The destination and its status are read through
 * `transfer_child`.
 *
 * There is no edit action, deliberately — the service explains why, and
 * `stock_transfer.update` instead guards marking a transfer received, which is
 * the one legitimate change to an existing transfer.
 */
class StockTransferController extends Controller
{
    public function __construct(
        protected StockTransferService $transfers,
        protected FormattingService $format,
    ) {}

    /* ================================================================
     | Listing
     ================================================================ */

    public function index(Request $request)
    {
        $this->permit('stock_transfer.view');

        $records = $this->listQuery($request)
            ->with(['location', 'transfer_child.location', 'created_user'])
            ->latest('transaction_date')
            ->paginate(25)
            ->withQueryString();

        return view('stock_transfer.index', [
            'records' => $records,
            'totals' => $this->listTotals($request),
            'locations' => BusinessLocation::forDropdown(true),
            'statuses' => $this->statusOptions(true),
        ]);
    }

    /* ================================================================
     | Create / read / delete
     ================================================================ */

    public function create()
    {
        $this->permit('stock_transfer.create');

        return view('stock_transfer.create', $this->formData());
    }

    public function store(Request $request)
    {
        $this->permit('stock_transfer.create');

        $validated = $this->validateTransfer($request);

        try {
            $transfer = $this->transfers->create(
                $this->documentData($validated),
                $this->lineData($request)
            );

            $output = $this->ok(__('lang_v1.added_successfully'));
        } catch (\Throwable $e) {
            return back()->withInput()->with('status', $this->failed($e));
        }

        return redirect()->route('stock-transfers.show', $transfer->id)->with('status', $output);
    }

    public function show(int $id)
    {
        $this->permit('stock_transfer.view');

        $record = $this->findTransfer($id, [
            'location', 'created_user', 'transfer_child.location',
            'sell_lines.variations.product.unit',
        ]);

        return view('stock_transfer.show', [
            'record' => $record,
            'canReceive' => $this->allows('stock_transfer.update')
                && $record->status === TransactionTypes::STATUS_IN_TRANSIT,
            'canDelete' => $this->allows('stock_transfer.delete'),
        ]);
    }

    /**
     * Confirm arrival. A POST rather than a GET because it books stock at the
     * destination — see the note on `sells.convert` in routes/web.php.
     */
    public function updateStatus(Request $request, int $id)
    {
        $this->permit('stock_transfer.update');

        try {
            $this->transfers->markReceived(
                $this->findTransfer($id, ['transfer_child.purchase_lines'])
            );

            $output = $this->ok(__('lang_v1.transfer_received'));
        } catch (\Throwable $e) {
            $output = $this->failed($e);
        }

        return back()->with('status', $output);
    }

    public function destroy(Request $request, int $id)
    {
        $this->permit('stock_transfer.delete');

        try {
            $this->transfers->delete(
                $this->findTransfer($id, ['sell_lines', 'transfer_child.purchase_lines'])
            );

            $output = $this->ok(__('lang_v1.deleted_successfully'));
        } catch (\Throwable $e) {
            $output = $this->failed($e);
        }

        return $request->ajax()
            ? response()->json($output)
            : $this->backToIndex('stock-transfers.index', $output);
    }

    /* ================================================================
     | Internals
     ================================================================ */

    protected function listQuery(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        return Transaction::ofType(TransactionTypes::SELL_TRANSFER)
            ->permittedLocations()
            // "Which shop was it to or from?" is one question to a user, so one
            // filter answers it — a transfer between two shops is relevant to
            // both of them.
            ->when($request->filled('location_id'), function ($query) use ($request) {
                $id = $request->integer('location_id');

                $query->where(fn ($q) => $q->where('location_id', $id)
                    ->orWhereHas('transfer_child', fn ($c) => $c->where('location_id', $id)));
            })
            ->when($request->filled('status'),
                fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search').'%';
                $query->where(fn ($q) => $q->where('ref_no', 'like', $term)
                    ->orWhere('additional_notes', 'like', $term)
                    ->orWhere('shipping_details', 'like', $term));
            })
            ->when($request->filled('start_date'),
                fn ($q) => $q->where('transaction_date', '>=',
                    $this->format->ufDate($request->input('start_date')).' 00:00:00'))
            ->when($request->filled('end_date'),
                fn ($q) => $q->where('transaction_date', '<=',
                    $this->format->ufDate($request->input('end_date')).' 23:59:59'));
    }

    /**
     * @return array<string, float|int>
     */
    protected function listTotals(Request $request): array
    {
        $query = $this->listQuery($request);

        return [
            'value' => (float) $query->clone()->sum('final_total'),
            'shipping' => (float) $query->clone()->sum('shipping_charges'),
            'in_transit' => $query->clone()
                ->where('status', TransactionTypes::STATUS_IN_TRANSIT)
                ->count(),
            'count' => $query->clone()->count(),
        ];
    }

    /**
     * @param  array<int, string>  $with
     */
    protected function findTransfer(int $id, array $with = []): Transaction
    {
        return Transaction::with($with)
            ->ofType(TransactionTypes::SELL_TRANSFER)
            ->permittedLocations()
            ->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validateTransfer(Request $request): array
    {
        return $request->validate([
            'location_id' => 'required|integer|exists:business_locations,id',
            'transfer_location_id' => 'required|integer|different:location_id|exists:business_locations,id',
            'ref_no' => 'nullable|string|max:255',
            'transaction_date' => 'required|date',
            'status' => 'required|string|in:'.implode(',', array_keys($this->statusOptions())),
            'shipping_charges' => 'nullable|numeric|min:0',
            'shipping_details' => 'nullable|string|max:255',
            'additional_notes' => 'nullable|string|max:2000',

            'lines' => 'required|array|min:1',
            'lines.*.variation_id' => 'required|integer|exists:variations,id',
            'lines.*.quantity' => 'required|numeric|gt:0',
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function documentData(array $validated): array
    {
        $data = collect($validated)->except('lines')->all();

        $data['transaction_date'] = $this->format->ufDate($validated['transaction_date'], true)
            ?? $validated['transaction_date'];

        $data['created_by'] = auth()->id();

        return $data;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function lineData(Request $request): array
    {
        return collect($request->input('lines', []))
            ->filter(fn ($line) => ! empty($line['variation_id'])
                && $this->format->numUf($line['quantity'] ?? 0) > 0)
            ->values()
            ->all();
    }

    /**
     * The two states a transfer can be saved in: it either went on a van
     * (`in_transit`, destination stock waits for confirmation) or it was carried
     * across the yard and is already there (`completed`).
     *
     * @return array<string, string>
     */
    protected function statusOptions(bool $addAll = false): array
    {
        $options = [
            TransactionTypes::STATUS_COMPLETED => __('lang_v1.completed'),
            TransactionTypes::STATUS_IN_TRANSIT => __('lang_v1.in_transit'),
        ];

        return $addAll ? ['' => __('lang_v1.all')] + $options : $options;
    }

    /**
     * @return array<string, mixed>
     */
    protected function formData(): array
    {
        return [
            'locations' => BusinessLocation::forDropdown(),
            'statuses' => $this->statusOptions(),
        ];
    }
}
