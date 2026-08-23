<?php

namespace App\Http\Controllers;

use App\Models\BusinessLocation;
use App\Models\Contact;
use App\Models\CustomerGroup;
use App\Models\SellingPriceGroup;
use App\Models\TaxRate;
use App\Models\Transaction;
use App\Models\TransactionSellLine;
use App\Models\User;
use App\Services\FormattingService;
use App\Services\PaymentService;
use App\Services\SellService;
use App\Support\TransactionTypes;
use Illuminate\Http\Request;

/**
 * Sale invoices, and the shared behaviour for the sell-side documents (drafts,
 * quotations and sales orders) which differ only by `type`, the `is_quotation`
 * flag and their status vocabulary.
 *
 * Structured exactly like PurchaseController so the two sides of the ledger
 * read the same: subclasses override the small hooks at the bottom.
 */
class SellController extends Controller
{
    public function __construct(
        protected SellService $sells,
        protected PaymentService $payments,
        protected FormattingService $format,
    ) {}

    /** The document type this controller manages. */
    protected function type(): string
    {
        return TransactionTypes::SELL;
    }

    /** Route name prefix. */
    protected function prefix(): string
    {
        return 'sells';
    }

    /** Permission prefix. */
    protected function permission(): string
    {
        return 'sell';
    }

    /* ================================================================
     | Listing
     ================================================================ */

    public function index(Request $request)
    {
        return $this->listing($request, 'invoices');
    }

    /**
     * Unfinalised sales. A draft holds no stock, so it is listed apart from
     * real invoices rather than mixed into them.
     */
    public function drafts(Request $request)
    {
        $this->permit('draft.view_all', 'draft.view_own');

        return $this->listing($request, 'drafts');
    }

    public function quotations(Request $request)
    {
        $this->permit('quotation.view_all', 'quotation.view_own');

        return $this->listing($request, 'quotations');
    }

    /**
     * One list screen, three flavours.
     */
    protected function listing(Request $request, string $variant)
    {
        if ($variant === 'invoices') {
            $this->permitView();
        }

        $documents = $this->baseQuery($variant)
            ->with(['contact', 'location'])
            ->when($request->filled('location_id'),
                fn ($q) => $q->where('location_id', $request->integer('location_id')))
            ->when($request->filled('contact_id'),
                fn ($q) => $q->where('contact_id', $request->integer('contact_id')))
            ->when($request->filled('status'),
                fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('payment_status'),
                fn ($q) => $q->where('payment_status', $request->string('payment_status')))
            ->when($request->filled('shipping_status'),
                fn ($q) => $q->where('shipping_status', $request->string('shipping_status')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search').'%';
                $query->where(fn ($q) => $q->where('invoice_no', 'like', $term)
                    ->orWhere('ref_no', 'like', $term));
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

        return view('sell.index', [
            'documents' => $documents,
            'totals' => $this->listTotals($request, $variant),
            'locations' => BusinessLocation::forDropdown(true),
            'customers' => ['' => __('lang_v1.all')] + Contact::customersForDropdown(),
            'statuses' => $this->statusOptions(),
            'variant' => $variant,
            'prefix' => $this->prefix(),
            'type' => $this->type(),
            'heading' => $this->headingFor($variant),
            'canCreate' => $this->allows($this->permission().'.create', 'direct_sell.access'),
            'canUpdate' => $this->allows($this->permission().'.update', 'direct_sell.update'),
            'canDelete' => $this->allows($this->permission().'.delete', 'direct_sell.delete'),
        ]);
    }

    public function create()
    {
        $this->permitCreate();

        return view('sell.create', $this->formData());
    }

    public function store(Request $request)
    {
        $this->permitCreate();

        $validated = $this->validateDocument($request);

        try {
            $document = $this->sells->create(
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
            'contact', 'location', 'sell_lines.variations.product', 'sell_lines.line_tax',
            'sell_lines.sub_unit', 'payment_lines.payment_account', 'tax', 'sales_person',
            'return_parent',
        ]);

        return view('sell.show', [
            'document' => $document,
            'paid' => $this->payments->amountPaid($document),
            'due' => $this->payments->amountDue($document),
            'shippingStatuses' => $this->shippingStatusOptions(),
            'deliveryPeople' => ['' => __('lang_v1.none')] + User::forDropdown(),
            'canShip' => $this->allows('access_shipping', 'access_own_shipping'),
            // Resolved here rather than with @can in the view: the ability is
            // `sell.update` for a sale and `so.update` for an order, and the view
            // should not have to re-derive which document it is looking at.
            'canUpdate' => $this->allows($this->permission().'.update'),
            // A sales order's own status is advanced from its document screen —
            // sales-order.updateStatus has nowhere else to be reached from.
            'statuses' => $this->statusOptions(),
            'prefix' => $this->prefix(),
        ]);
    }

    public function edit(int $id)
    {
        $this->permitUpdate();

        $document = $this->findDocument($id, ['sell_lines.variations.product']);

        if (! $document->canBeEdited()) {
            return redirect()->route($this->prefix().'.show', $document->id)
                ->with('status', ['success' => 0, 'msg' => __('lang_v1.edit_window_expired')]);
        }

        return view('sell.edit', $this->formData() + ['document' => $document]);
    }

    public function update(Request $request, int $id)
    {
        $this->permitUpdate();

        $document = $this->findDocument($id);
        $validated = $this->validateDocument($request, $document);

        try {
            $this->sells->update(
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
        $this->permitDelete();

        try {
            $this->sells->delete($this->findDocument($id));
            $output = $this->ok(__('lang_v1.deleted_successfully'));
        } catch (\Throwable $e) {
            $output = $this->failed($e);
        }

        return $request->ajax()
            ? response()->json($output)
            : $this->backToIndex($this->prefix().'.index', $output);
    }

    /* ================================================================
     | Draft / quotation → invoice
     ================================================================ */

    /**
     * Finalise a draft or quotation. This is the moment stock is consumed, so
     * it is a POST rather than a link.
     */
    public function convert(Request $request, int $id)
    {
        $this->permitCreate();

        $document = $this->findDocument($id, ['sell_lines']);

        if ($document->status === TransactionTypes::STATUS_FINAL && ! $document->is_quotation) {
            return back()->with('status', $this->failed(null, __('lang_v1.already_an_invoice')));
        }

        try {
            $shortfalls = $this->sells->findStockShortfalls(
                $document->location_id,
                $document->sell_lines->map(fn ($line) => [
                    'variation_id' => $line->variation_id,
                    'quantity' => $line->quantity,
                ])->all()
            );

            if (! empty($shortfalls)) {
                return back()->with('status', $this->failed(
                    null, $this->shortfallMessage($shortfalls)
                ));
            }

            $this->sells->convertToInvoice($document);

            $output = $this->ok(__('lang_v1.invoice_created'));
        } catch (\Throwable $e) {
            $output = $this->failed($e);
        }

        return redirect()->route($this->prefix().'.show', $document->id)->with('status', $output);
    }

    /* ================================================================
     | Shipping
     ================================================================ */

    /**
     * Advance a sale's shipping status. Shipping never touches stock — the
     * goods left when the invoice was finalised.
     */
    public function updateShipping(Request $request, int $id)
    {
        $this->permit('access_shipping', 'access_own_shipping');

        $validated = $request->validate([
            'shipping_status' => 'required|string|in:'.implode(',', array_keys(
                $this->shippingStatusOptions()
            )),
            'shipping_details' => 'nullable|string|max:255',
            'shipping_address' => 'nullable|string|max:2000',
            'shipping_charges' => 'nullable|numeric|min:0',
            'delivered_to' => 'nullable|string|max:255',
            'delivery_person' => 'nullable|integer|exists:users,id',
            'delivery_date' => 'nullable|date',
        ]);

        $document = $this->findDocument($id, ['sell_lines']);

        try {
            $this->sells->update(
                $document,
                $validated,
                $document->sell_lines->map(fn ($line) => $this->lineFrom($line))->all()
            );

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
     * Outstanding lines of a sales order, so an invoice can be raised from it.
     */
    public function orderLines(int $orderId)
    {
        $this->permitCreate();

        $order = Transaction::where('type', TransactionTypes::SALES_ORDER)
            ->with('sell_lines.variations.product')
            ->permittedLocations()
            ->findOrFail($orderId);

        return response()->json($order->sell_lines->map(function ($line) {
            $outstanding = round((float) $line->quantity - (float) $line->so_quantity_invoiced, 4);

            return [
                'so_line_id' => $line->id,
                'variation_id' => $line->variation_id,
                'text' => $line->variations->full_name,
                'sku' => $line->variations->sub_sku,
                'unit' => $line->variations->product->unit->short_name ?? '',
                'ordered' => (float) $line->quantity,
                'already_invoiced' => (float) $line->so_quantity_invoiced,
                // Pre-fill with what is still outstanding, never more.
                'quantity' => max(0, $outstanding),
                'selling_price' => (float) $line->unit_price,
                'tax_id' => $line->tax_id,
                'item_tax' => (float) $line->item_tax,
            ];
        })->filter(fn ($line) => $line['quantity'] > 0)->values());
    }

    /**
     * Open sales orders for a customer.
     */
    public function ordersForCustomer(int $contactId)
    {
        $this->permitCreate();

        $orders = Transaction::where('type', TransactionTypes::SALES_ORDER)
            ->where('contact_id', $contactId)
            ->whereIn('status', [TransactionTypes::STATUS_ORDERED, TransactionTypes::STATUS_PARTIAL])
            ->permittedLocations()
            ->get(['id', 'invoice_no', 'transaction_date', 'final_total', 'status']);

        return response()->json($orders->map(fn ($order) => [
            'id' => $order->id,
            'text' => $order->invoice_no.' — '.$this->format->formatDate($order->transaction_date),
            'status' => $order->status,
        ]));
    }

    /* ================================================================
     | Internals
     ================================================================ */

    protected function permitView(): void
    {
        $this->permit($this->permission().'.view', 'direct_sell.view', 'view_own_sell_only');
    }

    protected function permitCreate(): void
    {
        $this->permit($this->permission().'.create', 'direct_sell.access');
    }

    protected function permitUpdate(): void
    {
        $this->permit($this->permission().'.update', 'direct_sell.update');
    }

    protected function permitDelete(): void
    {
        $this->permit($this->permission().'.delete', 'direct_sell.delete');
    }

    /**
     * True when the user may only see documents they created.
     */
    protected function viewOwnOnly(): bool
    {
        return ! $this->allows($this->permission().'.view')
            && ! $this->allows('direct_sell.view')
            && $this->allows('view_own_sell_only');
    }

    /**
     * The list query before filters, narrowed to the requested flavour.
     *
     * `is_quotation` and `status` are what separate the three sell listings;
     * `type` alone would mix them together.
     */
    protected function baseQuery(string $variant = 'invoices'): \Illuminate\Database\Eloquent\Builder
    {
        $query = Transaction::where('type', $this->type())
            ->permittedLocations()
            ->when($this->viewOwnOnly(), fn ($q) => $q->where('created_by', auth()->id()));

        if ($this->type() !== TransactionTypes::SELL) {
            return $query;
        }

        return match ($variant) {
            'drafts' => $query->where('status', TransactionTypes::STATUS_DRAFT)
                ->where('is_quotation', 0),
            'quotations' => $query->where('is_quotation', 1),
            default => $query->where('status', TransactionTypes::STATUS_FINAL)
                ->where('is_quotation', 0),
        };
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
            'location_id' => 'required|integer|exists:business_locations,id',
            'contact_id' => 'required|integer|exists:contacts,id',
            'invoice_no' => 'nullable|string|max:255',
            'ref_no' => 'nullable|string|max:255',
            'transaction_date' => 'required|date',
            'status' => 'required|string|in:'.implode(',', array_keys($this->statusOptions())),
            'is_quotation' => 'nullable|boolean',
            'customer_group_id' => 'nullable|integer|exists:customer_groups,id',
            'selling_price_group_id' => 'nullable|integer|exists:selling_price_groups,id',
            'commission_agent' => 'nullable|integer|exists:users,id',
            'tax_id' => 'nullable|integer|exists:tax_rates,id',
            'discount_type' => 'nullable|in:fixed,percentage',
            'discount_amount' => 'nullable|numeric|min:0',
            'shipping_charges' => 'nullable|numeric|min:0',
            'shipping_details' => 'nullable|string|max:255',
            'shipping_address' => 'nullable|string|max:2000',
            'shipping_status' => 'nullable|string|in:'.implode(',', array_keys(
                $this->shippingStatusOptions()
            )),
            'delivered_to' => 'nullable|string|max:255',
            'delivery_person' => 'nullable|integer|exists:users,id',
            'delivery_date' => 'nullable|date',
            'round_off_amount' => 'nullable|numeric',
            'additional_notes' => 'nullable|string|max:2000',
            'staff_note' => 'nullable|string|max:2000',
            'pay_term_number' => 'nullable|integer|min:0',
            'pay_term_type' => 'nullable|in:days,months',
            'exchange_rate' => 'nullable|numeric|gt:0',
            'sales_order_ids' => 'nullable|array',
            'sales_order_ids.*' => 'integer|exists:transactions,id',

            'lines' => 'required|array|min:1',
            'lines.*.variation_id' => 'required|integer|exists:variations,id',
            'lines.*.quantity' => 'required|numeric|gt:0',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.line_discount_type' => 'nullable|in:fixed,percentage',
            'lines.*.line_discount_amount' => 'nullable|numeric|min:0',
            'lines.*.sell_line_note' => 'nullable|string|max:500',
            'lines.*.so_line_id' => 'nullable|integer|exists:transaction_sell_lines,id',

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

        // A quotation is never a stock movement, whatever status was posted.
        if (! empty($data['is_quotation'])) {
            $data['status'] = TransactionTypes::STATUS_DRAFT;
        }

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
     * Re-submit an existing line unchanged, for the actions that only touch the
     * document header (shipping, status) but go through the service so totals
     * and the FIFO map stay derived in one place.
     *
     * @return array<string, mixed>
     */
    protected function lineFrom(TransactionSellLine $line): array
    {
        return [
            'transaction_sell_lines_id' => $line->id,
            'variation_id' => $line->variation_id,
            'quantity' => $line->quantity,
            'unit_price' => $line->unit_price,
            'unit_price_before_discount' => $line->unit_price_before_discount,
            'unit_price_inc_tax' => $line->unit_price_inc_tax,
            'line_discount_type' => $line->line_discount_type,
            'line_discount_amount' => $line->line_discount_amount,
            'item_tax' => $line->item_tax,
            'tax_id' => $line->tax_id,
            'discount_id' => $line->discount_id,
            'lot_no_line_id' => $line->lot_no_line_id,
            'sub_unit_id' => $line->sub_unit_id,
            'sell_line_note' => $line->sell_line_note,
            'so_line_id' => $line->so_line_id,
        ];
    }

    /**
     * @param  array<int, array{name: string, requested: float, available: float}>  $shortfalls
     */
    protected function shortfallMessage(array $shortfalls): string
    {
        return __('lang_v1.stock_not_available').' — '.collect($shortfalls)
            ->map(fn ($row) => $row['name'].' ('.$this->format->quantity($row['available']).')')
            ->implode(', ');
    }

    /**
     * Headline totals for the filtered list.
     *
     * @return array<string, float>
     */
    protected function listTotals(Request $request, string $variant = 'invoices'): array
    {
        $query = $this->baseQuery($variant)
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
            TransactionTypes::STATUS_FINAL => __('lang_v1.final'),
            TransactionTypes::STATUS_DRAFT => __('lang_v1.draft'),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function shippingStatusOptions(): array
    {
        return collect(TransactionTypes::shippingStatuses())
            ->mapWithKeys(fn ($label, $value) => [$value => __($label)])
            ->all();
    }

    protected function headingFor(string $variant): string
    {
        return match ($variant) {
            'drafts' => __('lang_v1.drafts'),
            'quotations' => __('lang_v1.quotations'),
            default => __('lang_v1.all_sales'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function formData(): array
    {
        return [
            'locations' => BusinessLocation::forDropdown(),
            'customers' => Contact::customersForDropdown(),
            'taxes' => ['' => __('lang_v1.none')] + TaxRate::forDropdown(),
            'taxAmounts' => TaxRate::amountsById(),
            'accounts' => \App\Models\Account::forDropdown(),
            'customerGroups' => CustomerGroup::forDropdown(),
            'priceGroups' => ['' => __('lang_v1.none')] + SellingPriceGroup::forDropdown(),
            // Only users flagged `is_cmmsn_agnt` may earn commission; the
            // delivery person is any member of staff.
            'commissionAgents' => ['' => __('lang_v1.none')] + User::commissionAgentsForDropdown(),
            'deliveryPeople' => ['' => __('lang_v1.none')] + User::forDropdown(),
            'paymentMethods' => collect(TransactionTypes::paymentMethods())
                ->map(fn ($key) => __($key))->all(),
            'statuses' => $this->statusOptions(),
            'shippingStatuses' => ['' => __('lang_v1.none')] + $this->shippingStatusOptions(),
            'prefix' => $this->prefix(),
            'type' => $this->type(),
            'isSalesOrder' => $this->type() === TransactionTypes::SALES_ORDER,
            'lotTracking' => (bool) session('business.enable_lot_number'),
        ];
    }
}
