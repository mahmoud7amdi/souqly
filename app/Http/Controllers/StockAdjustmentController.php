<?php

namespace App\Http\Controllers;

use App\Models\BusinessLocation;
use App\Models\Transaction;
use App\Services\FormattingService;
use App\Services\StockAdjustmentService;
use App\Support\TransactionTypes;
use Illuminate\Http\Request;

/**
 * Stock adjustments — recorded write-offs.
 *
 * A thin controller by design: every rule about what an adjustment may do to
 * stock lives in {@see StockAdjustmentService}, which is where the FIFO map is
 * touched. What is here is the screens' vocabulary — filters, totals, the two
 * adjustment kinds — and the permission gates.
 */
class StockAdjustmentController extends Controller
{
    public function __construct(
        protected StockAdjustmentService $adjustments,
        protected FormattingService $format,
    ) {}

    /* ================================================================
     | Listing
     ================================================================ */

    public function index(Request $request)
    {
        $this->permit('stock_adjustment.view');

        $records = $this->listQuery($request)
            ->with(['location', 'created_user'])
            ->latest('transaction_date')
            ->paginate(25)
            ->withQueryString();

        return view('stock_adjustment.index', [
            'records' => $records,
            'totals' => $this->listTotals($request),
            'locations' => BusinessLocation::forDropdown(true),
            'types' => $this->typeOptions(true),
        ]);
    }

    /* ================================================================
     | Create / read / update / delete
     ================================================================ */

    public function create()
    {
        $this->permit('stock_adjustment.create');

        return view('stock_adjustment.create', $this->formData());
    }

    public function store(Request $request)
    {
        $this->permit('stock_adjustment.create');

        $validated = $this->validateAdjustment($request);

        try {
            $adjustment = $this->adjustments->create(
                $this->documentData($validated, true),
                $this->lineData($request)
            );

            $output = $this->ok(__('lang_v1.added_successfully'));
        } catch (\Throwable $e) {
            return back()->withInput()->with('status', $this->failed($e));
        }

        return redirect()->route('stock-adjustments.show', $adjustment->id)->with('status', $output);
    }

    public function show(int $id)
    {
        $this->permit('stock_adjustment.view');

        return view('stock_adjustment.show', [
            'record' => $this->findAdjustment($id, [
                'location', 'created_user',
                'stock_adjustment_lines.variation.product.unit',
            ]),
        ]);
    }

    public function edit(int $id)
    {
        $this->permit('stock_adjustment.update');

        $record = $this->findAdjustment($id, ['stock_adjustment_lines.variation.product']);

        return view('stock_adjustment.edit', $this->formData() + ['document' => $record]);
    }

    public function update(Request $request, int $id)
    {
        $this->permit('stock_adjustment.update');

        $record = $this->findAdjustment($id);
        $validated = $this->validateAdjustment($request);

        try {
            $this->adjustments->update(
                $record,
                $this->documentData($validated),
                $this->lineData($request)
            );

            $output = $this->ok(__('lang_v1.updated_successfully'));
        } catch (\Throwable $e) {
            return back()->withInput()->with('status', $this->failed($e));
        }

        return redirect()->route('stock-adjustments.show', $record->id)->with('status', $output);
    }

    public function destroy(Request $request, int $id)
    {
        $this->permit('stock_adjustment.delete');

        try {
            $this->adjustments->delete($this->findAdjustment($id, ['stock_adjustment_lines']));
            $output = $this->ok(__('lang_v1.deleted_successfully'));
        } catch (\Throwable $e) {
            $output = $this->failed($e);
        }

        return $request->ajax()
            ? response()->json($output)
            : $this->backToIndex('stock-adjustments.index', $output);
    }

    /* ================================================================
     | Internals
     ================================================================ */

    protected function listQuery(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        return Transaction::ofType(TransactionTypes::STOCK_ADJUSTMENT)
            ->permittedLocations()
            ->when($request->filled('location_id'),
                fn ($q) => $q->where('location_id', $request->integer('location_id')))
            ->when($request->filled('adjustment_type'),
                fn ($q) => $q->where('adjustment_type', $request->string('adjustment_type')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search').'%';
                $query->where(fn ($q) => $q->where('ref_no', 'like', $term)
                    ->orWhere('additional_notes', 'like', $term));
            })
            ->when($request->filled('start_date'),
                fn ($q) => $q->where('transaction_date', '>=',
                    $this->format->ufDate($request->input('start_date')).' 00:00:00'))
            ->when($request->filled('end_date'),
                fn ($q) => $q->where('transaction_date', '<=',
                    $this->format->ufDate($request->input('end_date')).' 23:59:59'));
    }

    /**
     * Headline figures for the filtered list.
     *
     * The loss and what came back are reported separately rather than netted:
     * a month where write-offs doubled but insurance covered them is a different
     * month from one where nothing broke, and a single net number cannot tell
     * the two apart.
     *
     * @return array<string, float>
     */
    protected function listTotals(Request $request): array
    {
        $query = $this->listQuery($request);

        $loss = (float) $query->clone()->sum('final_total');
        $recovered = (float) $query->clone()->sum('total_amount_recovered');

        return [
            'loss' => $loss,
            'recovered' => $recovered,
            'net' => round($loss - $recovered, 4),
            'abnormal' => (float) $query->clone()
                ->where('adjustment_type', 'abnormal')
                ->sum('final_total'),
        ];
    }

    /**
     * @param  array<int, string>  $with
     */
    protected function findAdjustment(int $id, array $with = []): Transaction
    {
        return Transaction::with($with)
            ->ofType(TransactionTypes::STOCK_ADJUSTMENT)
            ->permittedLocations()
            ->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validateAdjustment(Request $request): array
    {
        return $request->validate([
            'location_id' => 'required|integer|exists:business_locations,id',
            'ref_no' => 'nullable|string|max:255',
            'transaction_date' => 'required|date',
            'adjustment_type' => 'required|in:'.implode(',', array_keys($this->typeOptions())),
            'total_amount_recovered' => 'nullable|numeric|min:0',
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
    protected function documentData(array $validated, bool $isNew = false): array
    {
        $data = collect($validated)->except('lines')->all();

        $data['transaction_date'] = $this->format->ufDate($validated['transaction_date'], true)
            ?? $validated['transaction_date'];

        if ($isNew) {
            $data['created_by'] = auth()->id();
        }

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
     * Normal vs abnormal: the difference is whether the loss is a cost of doing
     * business (shrinkage, expiry) or an event somebody should look into
     * (theft, an accident). Reports separate them, so the form asks.
     *
     * @return array<string, string>
     */
    protected function typeOptions(bool $addAll = false): array
    {
        $options = [
            'normal' => __('lang_v1.normal'),
            'abnormal' => __('lang_v1.abnormal'),
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
            'types' => $this->typeOptions(),
        ];
    }
}
