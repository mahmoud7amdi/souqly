<?php

namespace App\Http\Controllers;

use App\Models\BusinessLocation;
use App\Models\Contact;
use App\Models\PurchaseLine;
use App\Models\TaxRate;
use App\Models\Transaction;
use App\Services\FormattingService;
use App\Services\PaymentService;
use App\Services\PurchaseService;
use App\Support\TenantRules;
use App\Support\TransactionTypes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Purchase invoices, and the shared behaviour for the purchase-side documents
 * (orders and requisitions) which differ only by `type` and status vocabulary.
 */
class PurchaseController extends Controller
{
    public function __construct(
        protected PurchaseService $purchases,
        protected PaymentService $payments,
        protected FormattingService $format,
    ) {}

    /** The document type this controller manages. */
    protected function type(): string
    {
        return TransactionTypes::PURCHASE;
    }

    /** Route name prefix. */
    protected function prefix(): string
    {
        return 'purchases';
    }

    /** Permission prefix. */
    protected function permission(): string
    {
        return 'purchase';
    }

    /* ================================================================
     | Listing
     ================================================================ */

    public function index(Request $request)
    {
        $this->permitView();

        $documents = Transaction::with(['contact', 'location'])
            ->where('type', $this->type())
            ->permittedLocations()
            ->when($this->viewOwnOnly(), fn ($q) => $q->where('created_by', auth()->id()))
            ->when($request->filled('location_id'),
                fn ($q) => $q->where('location_id', $request->integer('location_id')))
            ->when($request->filled('contact_id'),
                fn ($q) => $q->where('contact_id', $request->integer('contact_id')))
            ->when($request->filled('status'),
                fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('payment_status'),
                fn ($q) => $q->where('payment_status', $request->string('payment_status')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search').'%';
                $query->where(fn ($q) => $q->where('ref_no', 'like', $term)
                    ->orWhere('invoice_no', 'like', $term));
            })
            ->when($request->filled('start_date'),
                fn ($q) => $q->where('transaction_date', '>=',
                    $this->format->ufDate($request->input('start_date')).' 00:00:00'))
            ->when($request->filled('end_date'),
                fn ($q) => $q->where('transaction_date', '<=',
                    $this->format->ufDate($request->input('end_date')).' 23:59:59'))
            ->latest('transaction_date')
            ->paginate(25)
            ->withQueryString();

        $totals = $this->listTotals($request);

        return view('purchase.index', [
            'documents' => $documents,
            'totals' => $totals,
            'locations' => BusinessLocation::forDropdown(true),
            'suppliers' => ['' => __('lang_v1.all')] + Contact::suppliersForDropdown(),
            'statuses' => $this->statusOptions(),
            'prefix' => $this->prefix(),
            'type' => $this->type(),
            'canCreate' => $this->allows($this->permission().'.create'),
            'canUpdate' => $this->allows($this->permission().'.update'),
            'canDelete' => $this->allows($this->permission().'.delete'),
        ]);
    }

    public function create()
    {
        $this->permit($this->permission().'.create');

        return view('purchase.create', $this->formData());
    }

    public function store(Request $request)
    {
        $this->permit($this->permission().'.create');

        $validated = $this->validateDocument($request);

        try {
            $document = $this->purchases->create(
                $this->documentData($validated, $request),
                $this->lineData($request),
                $this->paymentData($request),
                $this->type()
            );

            $output = $this->ok(__('lang_v1.added_successfully'));
        } catch (\Throwable $e) {
            return back()->withInput()->with('status', $this->failed($e));
        }

        return redirect()->route($this->prefix().'.show', $document->id)->with('status', $output);
    }

    public function show(int $id)
    {
        $this->permitView();

        $document = $this->findDocument($id, [
            'contact', 'location', 'purchase_lines.variations.product',
            'purchase_lines.line_tax', 'payment_lines.payment_account', 'terms', 'tax',
        ]);

        return view('purchase.show', [
            'document' => $document,
            'paid' => $this->payments->amountPaid($document),
            'due' => $this->payments->amountDue($document),
            'prefix' => $this->prefix(),
        ]);
    }

    public function edit(int $id)
    {
        $this->permit($this->permission().'.update');

        $document = $this->findDocument($id, ['purchase_lines.variations.product', 'terms']);

        if (! $document->canBeEdited()) {
            return redirect()->route($this->prefix().'.show', $document->id)
                ->with('status', ['success' => 0, 'msg' => __('lang_v1.edit_window_expired')]);
        }

        return view('purchase.edit', $this->formData() + ['document' => $document]);
    }

    public function update(Request $request, int $id)
    {
        $this->permit($this->permission().'.update');

        $document = $this->findDocument($id);
        $validated = $this->validateDocument($request, $document);

        try {
            $this->purchases->update(
                $document,
                $this->documentData($validated, $request, $document),
                $this->lineData($request)
            );

            $output = $this->ok(__('lang_v1.updated_successfully'));
        } catch (\Throwable $e) {
            return back()->withInput()->with('status', $this->failed($e));
        }

        return redirect()->route($this->prefix().'.show', $document->id)->with('status', $output);
    }

    public function destroy(Request $request, int $id)
    {
        $this->permit($this->permission().'.delete');

        try {
            $this->purchases->delete($this->findDocument($id));
            $output = $this->ok(__('lang_v1.deleted_successfully'));
        } catch (\Throwable $e) {
            $output = $this->failed($e);
        }

        return $request->ajax()
            ? response()->json($output)
            : $this->backToIndex($this->prefix().'.index', $output);
    }

    /* ================================================================
     | Status
     ================================================================ */

    /**
     * Flip a purchase between pending / ordered / received. Moving to
     * `received` is what actually books the stock.
     */
    public function updateStatus(Request $request, int $id)
    {
        $this->permit('purchase.update_status', 'purchase.update');

        $request->validate(['status' => 'required|in:received,pending,ordered']);

        $document = $this->findDocument($id, ['purchase_lines']);

        try {
            $lines = $document->purchase_lines->map(fn ($line) => [
                'purchase_line_id' => $line->id,
                'variation_id' => $line->variation_id,
                'quantity' => $line->quantity,
                'purchase_price' => $line->purchase_price,
                'purchase_price_inc_tax' => $line->purchase_price_inc_tax,
                'item_tax' => $line->item_tax,
                'tax_id' => $line->tax_id,
                'lot_number' => $line->lot_number,
                'mfg_date' => $line->mfg_date?->toDateString(),
                'exp_date' => $line->exp_date?->toDateString(),
            ])->all();

            $this->purchases->update($document, ['status' => $request->string('status')], $lines);

            $output = $this->ok(__('lang_v1.updated_successfully'));
        } catch (\Throwable $e) {
            $output = $this->failed($e);
        }

        return back()->with('status', $output);
    }

    /* ================================================================
     | AJAX
     ================================================================ */

    /**
     * Lines of a purchase order, so an invoice can be raised from it.
     */
    public function orderLines(int $orderId)
    {
        $this->permit('purchase.create');

        $order = Transaction::where('type', TransactionTypes::PURCHASE_ORDER)
            ->with('purchase_lines.variations.product')
            ->findOrFail($orderId);

        return response()->json($order->purchase_lines->map(function ($line) {
            $outstanding = round(
                (float) $line->quantity - (float) $line->po_quantity_purchased, 4
            );

            return [
                'purchase_order_line_id' => $line->id,
                'variation_id' => $line->variation_id,
                'name' => $line->variations->full_name,
                'sku' => $line->variations->sub_sku,
                'ordered' => (float) $line->quantity,
                'already_invoiced' => (float) $line->po_quantity_purchased,
                // Pre-fill with what is still outstanding, never more.
                'quantity' => max(0, $outstanding),
                'purchase_price' => (float) $line->purchase_price,
                'purchase_price_inc_tax' => (float) $line->purchase_price_inc_tax,
                'tax_id' => $line->tax_id,
                'item_tax' => (float) $line->item_tax,
            ];
        })->filter(fn ($line) => $line['quantity'] > 0)->values());
    }

    /**
     * Open purchase orders for a supplier.
     */
    public function ordersForSupplier(int $contactId)
    {
        $this->permit('purchase.create');

        $orders = Transaction::where('type', TransactionTypes::PURCHASE_ORDER)
            ->where('contact_id', $contactId)
            ->whereIn('status', [TransactionTypes::STATUS_ORDERED, TransactionTypes::STATUS_PARTIAL])
            ->permittedLocations()
            ->get(['id', 'ref_no', 'transaction_date', 'final_total', 'status']);

        return response()->json($orders->map(fn ($order) => [
            'id' => $order->id,
            'text' => $order->ref_no.' — '.$this->format->formatDate($order->transaction_date),
            'status' => $order->status,
        ]));
    }

    /* ================================================================
     | Internals
     ================================================================ */

    protected function permitView(): void
    {
        $this->permit($this->permission().'.view', 'view_own_purchase');
    }

    /**
     * True when the user may only see documents they created.
     */
    protected function viewOwnOnly(): bool
    {
        return ! $this->allows($this->permission().'.view')
            && $this->allows('view_own_purchase');
    }

    protected function findDocument(int $id, array $with = []): Transaction
    {
        return Transaction::with($with)
            ->where('type', $this->type())
            ->permittedLocations()
            ->when($this->viewOwnOnly(), fn ($q) => $q->where('created_by', auth()->id()))
            ->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validateDocument(Request $request, ?Transaction $document = null): array
    {
        return $request->validate([
            'location_id' => ['required', 'integer', TenantRules::location()],
            'contact_id' => 'required|integer|exists:contacts,id',
            'ref_no' => 'nullable|string|max:255',
            'transaction_date' => 'required|date',
            'status' => 'required|string|in:'.implode(',', array_keys($this->statusOptions())),
            'tax_id' => 'nullable|integer|exists:tax_rates,id',
            'discount_type' => 'nullable|in:fixed,percentage',
            'discount_amount' => 'nullable|numeric|min:0',
            'shipping_charges' => 'nullable|numeric|min:0',
            'shipping_details' => 'nullable|string|max:255',
            'additional_notes' => 'nullable|string|max:2000',
            'pay_term_number' => 'nullable|integer|min:0',
            'pay_term_type' => 'nullable|in:days,months',
            'exchange_rate' => 'nullable|numeric|gt:0',
            'delivery_date' => 'nullable|date',
            'purchase_order_ids' => 'nullable|array',

            'lines' => 'required|array|min:1',
            'lines.*.variation_id' => 'required|integer|exists:variations,id',
            'lines.*.quantity' => 'required|numeric|gt:0',
            'lines.*.purchase_price' => 'required|numeric|min:0',
            'lines.*.lot_number' => 'nullable|string|max:255',
            'lines.*.mfg_date' => 'nullable|date',
            'lines.*.exp_date' => 'nullable|date|after_or_equal:lines.*.mfg_date',

            'terms' => 'nullable|array',
            'terms.*.payment_term' => 'nullable|numeric|min:0|max:100',
            'terms.*.due_date' => 'nullable|date',

            'payments' => 'nullable|array',
            'payments.*.amount' => 'nullable|numeric|min:0',
            'payments.*.method' => 'nullable|string|max:50',
            'payments.*.account_id' => 'nullable|integer|exists:accounts,id',
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function documentData(array $validated, Request $request, ?Transaction $document = null): array
    {
        $data = collect($validated)->except(['lines', 'payments'])->all();

        $data['transaction_date'] = $this->format->ufDate($validated['transaction_date'], true)
            ?? $validated['transaction_date'];

        if (empty($document)) {
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
     * @return array<int, array<string, mixed>>
     */
    protected function paymentData(Request $request): array
    {
        return collect($request->input('payments', []))
            ->filter(fn ($payment) => $this->format->numUf($payment['amount'] ?? 0) > 0)
            ->values()
            ->all();
    }

    /**
     * Headline totals for the filtered list.
     *
     * @return array<string, float>
     */
    protected function listTotals(Request $request): array
    {
        $query = Transaction::where('type', $this->type())
            ->permittedLocations()
            ->when($this->viewOwnOnly(), fn ($q) => $q->where('created_by', auth()->id()))
            ->when($request->filled('location_id'),
                fn ($q) => $q->where('location_id', $request->integer('location_id')))
            ->when($request->filled('contact_id'),
                fn ($q) => $q->where('contact_id', $request->integer('contact_id')));

        $total = (float) $query->clone()->sum('final_total');

        $paid = (float) \App\Models\TransactionPayment::whereIn(
            'transaction_id', $query->clone()->select('id')
        )->where('is_return', 0)->sum('amount');

        return [
            'total' => $total,
            'paid' => $paid,
            'due' => round($total - $paid, 4),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function statusOptions(): array
    {
        return [
            TransactionTypes::STATUS_RECEIVED => __('lang_v1.received'),
            TransactionTypes::STATUS_PENDING => __('lang_v1.pending'),
            TransactionTypes::STATUS_ORDERED => __('lang_v1.ordered'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formData(): array
    {
        return [
            'locations' => BusinessLocation::forDropdown(),
            'suppliers' => Contact::suppliersForDropdown(),
            'taxes' => ['' => __('lang_v1.none')] + TaxRate::forDropdown(),
            'accounts' => \App\Models\Account::forDropdown(),
            'paymentMethods' => collect(TransactionTypes::paymentMethods())
                ->map(fn ($key) => __($key))->all(),
            'statuses' => $this->statusOptions(),
            'prefix' => $this->prefix(),
            'lotTracking' => (bool) session('business.enable_lot_number'),
            'expiryTracking' => (bool) session('business.enable_product_expiry'),
        ];
    }
}
