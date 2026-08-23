<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\FormattingService;
use App\Services\PaymentService;
use App\Services\SellService;
use App\Support\TransactionTypes;
use Illuminate\Http\Request;

/**
 * Sell returns — goods a customer brings back.
 *
 * A return is always raised against a specific sale and is capped per line at
 * what has not already been returned. The goods go back onto the lots they came
 * off (SellService::addReturn → StockService::returnToLots), so cost and the
 * FIFO map stay consistent instead of the stock reappearing at an average
 * price.
 */
class SellReturnController extends Controller
{
    public function __construct(
        private SellService $sells,
        private PaymentService $payments,
        private FormattingService $format,
    ) {}

    public function index(Request $request)
    {
        $this->permitView();

        $returns = Transaction::with(['contact', 'location', 'return_parent_sell'])
            ->where('type', TransactionTypes::SELL_RETURN)
            ->permittedLocations()
            ->when($this->viewOwnOnly(), fn ($q) => $q->where('created_by', auth()->id()))
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search').'%';
                $query->where(fn ($q) => $q->where('invoice_no', 'like', $term)
                    ->orWhere('ref_no', 'like', $term));
            })
            ->when($request->filled('location_id'),
                fn ($q) => $q->where('location_id', $request->integer('location_id')))
            ->when($request->filled('contact_id'),
                fn ($q) => $q->where('contact_id', $request->integer('contact_id')))
            ->latest('transaction_date')
            ->paginate(25)
            ->withQueryString();

        return view('sell_return.index', [
            'returns' => $returns,
            'locations' => \App\Models\BusinessLocation::forDropdown(true),
            'customers' => ['' => __('lang_v1.all')] + \App\Models\Contact::customersForDropdown(),
        ]);
    }

    /**
     * Return form for one sale, showing how much of each line is still
     * returnable.
     */
    public function create(int $sellId)
    {
        $this->permit('access_sell_return');

        $sale = $this->findSale($sellId, ['contact', 'sell_lines.variations.product']);

        // Combo child lines carry no money and are not returned in their own
        // right — the parent line is what the customer bought.
        $lines = $sale->sell_lines
            ->reject(fn ($line) => $line->children_type === 'combo')
            ->map(fn ($line) => [
                'line' => $line,
                'already_returned' => (float) $line->quantity_returned,
                'returnable' => round((float) $line->quantity - (float) $line->quantity_returned, 4),
            ])
            ->values();

        return view('sell_return.create', [
            'sale' => $sale,
            'lines' => $lines,
            'existingReturn' => $sale->return_parent,
        ]);
    }

    public function store(Request $request, int $sellId)
    {
        $this->permit('access_sell_return');

        $sale = $this->findSale($sellId);

        $request->validate([
            'lines' => 'required|array|min:1',
            'lines.*.sell_line_id' => 'required|integer|exists:transaction_sell_lines,id',
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
            $return = $this->sells->addReturn($sale, $lines, [
                'transaction_date' => $this->format->ufDate($request->input('transaction_date'), true),
            ]);

            $output = $this->ok(__('lang_v1.return_recorded'));
        } catch (\Throwable $e) {
            return back()->withInput()->with('status', $this->failed($e));
        }

        return redirect()->route('sell-return.show', $return->id)->with('status', $output);
    }

    public function show(int $id)
    {
        $this->permitView();

        $return = Transaction::with(['contact', 'location', 'return_parent_sell', 'payment_lines'])
            ->where('type', TransactionTypes::SELL_RETURN)
            ->permittedLocations()
            ->when($this->viewOwnOnly(), fn ($q) => $q->where('created_by', auth()->id()))
            ->findOrFail($id);

        // The returned quantities live on the parent sale's lines.
        $lines = $return->return_parent_sell
            ? $return->return_parent_sell->sell_lines()
                ->with('variations.product')
                ->where('quantity_returned', '>', 0)
                ->get()
            : collect();

        return view('sell_return.show', [
            'return' => $return,
            'lines' => $lines,
            'paid' => $this->payments->amountPaid($return),
            'due' => $this->payments->amountDue($return),
        ]);
    }

    /* ================================================================
     | Internals
     ================================================================ */

    protected function permitView(): void
    {
        $this->permit('access_sell_return', 'access_own_sell_return');
    }

    protected function viewOwnOnly(): bool
    {
        return ! $this->allows('access_sell_return')
            && $this->allows('access_own_sell_return');
    }

    /**
     * The sale being returned against — a real, finalised invoice. A draft or
     * quotation never moved stock, so there is nothing to send back.
     */
    protected function findSale(int $id, array $with = []): Transaction
    {
        return Transaction::with($with)
            ->where('type', TransactionTypes::SELL)
            ->where('status', TransactionTypes::STATUS_FINAL)
            ->permittedLocations()
            ->findOrFail($id);
    }
}
