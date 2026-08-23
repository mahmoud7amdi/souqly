<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\FormattingService;
use App\Services\PaymentService;
use App\Services\PurchaseService;
use App\Services\StockService;
use App\Support\TransactionTypes;
use Illuminate\Http\Request;

/**
 * Purchase returns — goods sent back to a supplier.
 *
 * A return is always raised against a specific purchase, and is capped at what
 * remains of each lot (anything already sold on cannot be returned).
 */
class PurchaseReturnController extends Controller
{
    public function __construct(
        private PurchaseService $purchases,
        private PaymentService $payments,
        private StockService $stock,
        private FormattingService $format,
    ) {}

    public function index(Request $request)
    {
        $this->permit('purchase.view', 'view_own_purchase');

        $returns = Transaction::with(['contact', 'location', 'return_parent_sell'])
            ->where('type', TransactionTypes::PURCHASE_RETURN)
            ->permittedLocations()
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search').'%';
                $query->where('ref_no', 'like', $term);
            })
            ->latest('transaction_date')
            ->paginate(25)
            ->withQueryString();

        return view('purchase_return.index', ['returns' => $returns]);
    }

    /**
     * Return form for one purchase, showing how much of each lot is still
     * returnable.
     */
    public function create(int $purchaseId)
    {
        $this->permit('purchase.update');

        $purchase = Transaction::with(['contact', 'purchase_lines.variations.product'])
            ->where('type', TransactionTypes::PURCHASE)
            ->permittedLocations()
            ->findOrFail($purchaseId);

        $lines = $purchase->purchase_lines->map(function ($lot) {
            return [
                'lot' => $lot,
                // Quantity untouched by sales, adjustments or earlier returns.
                'returnable' => $this->stock->lotRemaining($lot),
                'already_returned' => (float) $lot->quantity_returned,
            ];
        });

        return view('purchase_return.create', [
            'purchase' => $purchase,
            'lines' => $lines,
            'existingReturn' => $purchase->return_parent,
        ]);
    }

    public function store(Request $request, int $purchaseId)
    {
        $this->permit('purchase.update');

        $purchase = Transaction::where('type', TransactionTypes::PURCHASE)
            ->permittedLocations()
            ->findOrFail($purchaseId);

        $request->validate([
            'lines' => 'required|array|min:1',
            'lines.*.purchase_line_id' => 'required|integer|exists:purchase_lines,id',
            'lines.*.quantity' => 'required|numeric|min:0',
            'transaction_date' => 'nullable|date',
        ]);

        $lines = collect($request->input('lines'))
            ->filter(fn ($line) => $this->format->numUf($line['quantity'] ?? 0) > 0)
            ->values()
            ->all();

        if (empty($lines)) {
            return back()->with('status', [
                'success' => 0,
                'msg' => __('lang_v1.nothing_to_return'),
            ]);
        }

        try {
            $return = $this->purchases->addReturn($purchase, $lines, [
                'transaction_date' => $this->format->ufDate($request->input('transaction_date'), true),
            ]);

            $output = $this->ok(__('lang_v1.return_recorded'));
        } catch (\Throwable $e) {
            return back()->withInput()->with('status', $this->failed($e));
        }

        return redirect()->route('purchase-return.show', $return->id)->with('status', $output);
    }

    public function show(int $id)
    {
        $this->permit('purchase.view', 'view_own_purchase');

        $return = Transaction::with([
            'contact', 'location', 'return_parent_sell', 'payment_lines',
        ])
            ->where('type', TransactionTypes::PURCHASE_RETURN)
            ->permittedLocations()
            ->findOrFail($id);

        // The returned quantities live on the parent purchase's lots.
        $lots = $return->return_parent_sell
            ? $return->return_parent_sell->purchase_lines()
                ->with('variations.product')
                ->where('quantity_returned', '>', 0)
                ->get()
            : collect();

        return view('purchase_return.show', [
            'return' => $return,
            'lots' => $lots,
            'paid' => $this->payments->amountPaid($return),
            'due' => $this->payments->amountDue($return),
        ]);
    }
}
