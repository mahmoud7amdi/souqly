<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\BusinessLocation;
use App\Models\Contact;
use App\Models\Product;
use App\Models\SellingPriceGroup;
use App\Models\TaxRate;
use App\Services\FormattingService;
use App\Services\SellService;
use App\Support\TransactionTypes;
use Illuminate\Http\Request;

/**
 * The POS terminal.
 *
 * Same service and same stock path as SellController — a POS sale is an ordinary
 * final sell, not a second kind of document. What differs is the screen: one
 * keyboard-first page that finalises in a single POST, because the person using
 * it has a customer standing in front of them.
 *
 * Deliberately not a subclass of SellController: it shares no listing, no edit
 * window and no multi-panel form, and inheriting all of that only to override
 * two methods would misrepresent the relationship.
 */
class SellPosController extends Controller
{
    public function __construct(
        private SellService $sells,
        private FormattingService $format,
    ) {}

    public function create()
    {
        $this->permit('sell.create', 'direct_sell.access');

        return view('pos.create', [
            'locations' => BusinessLocation::forDropdown(),
            'customers' => Contact::customersForDropdown(),
            'taxes' => ['' => __('lang_v1.none')] + TaxRate::forDropdown(),
            // The rates, not just the names: the terminal shows the total the
            // customer will be asked for before the sale is saved, so it has to
            // do the same arithmetic SellService::recalculateTotals() will.
            'taxAmounts' => TaxRate::amountsById(),
            'priceGroups' => ['' => __('lang_v1.none')] + SellingPriceGroup::forDropdown(),
            'accounts' => Account::forDropdown(),
            'paymentMethods' => collect(TransactionTypes::paymentMethods())
                ->map(fn ($key) => __($key))->all(),
            // The terminal opens on a walk-in sale; anything else is a deliberate
            // choice the cashier makes per sale.
            'defaultCustomer' => Contact::query()->where('is_default', 1)->value('id'),
            // Seeds the product grid's picture mode before the first fetch has
            // answered, so the skeleton is already the shape of the tiles that
            // will replace it. A hint, not the truth: this asks only whether a
            // filename is recorded, where the feed also checks the file is on
            // disk — the first response corrects the mode either way. EXISTS
            // rather than a count, because one photo settles the question.
            'hasProductImages' => Product::query()
                ->whereNotNull('image')->where('image', '!=', '')->exists(),
        ]);
    }

    /**
     * Finalise a counter sale.
     *
     * Always `final` and never a quotation: a POS sale is money in the drawer and
     * goods out of the door at the same instant. There is no draft state to be in.
     */
    public function store(Request $request)
    {
        $this->permit('sell.create', 'direct_sell.access');

        $validated = $request->validate([
            'location_id' => 'required|integer|exists:business_locations,id',
            'contact_id' => 'required|integer|exists:contacts,id',
            'transaction_date' => 'nullable|date',
            'tax_id' => 'nullable|integer|exists:tax_rates,id',
            'discount_type' => 'nullable|in:fixed,percentage',
            'discount_amount' => 'nullable|numeric|min:0',
            'selling_price_group_id' => 'nullable|integer|exists:selling_price_groups,id',
            'additional_notes' => 'nullable|string|max:2000',

            'lines' => 'required|array|min:1',
            'lines.*.variation_id' => 'required|integer|exists:variations,id',
            'lines.*.quantity' => 'required|numeric|gt:0',
            'lines.*.unit_price' => 'required|numeric|min:0',

            'payments' => 'nullable|array',
            'payments.*.amount' => 'nullable|numeric|min:0',
            'payments.*.method' => 'nullable|string|max:50',
            'payments.*.account_id' => 'nullable|integer|exists:accounts,id',

            /*
             * The terminal's write-ahead id, and the reason the online path and
             * the offline path cannot produce the same sale twice.
             *
             * The POS writes every sale into its local queue before it posts, so
             * a request that dies in flight leaves the money recorded somewhere.
             * Stamping the same id on the request means the copy on the device
             * and the row in the database are the same sale by identity, not by
             * resemblance — the queue drain later finds the id already present
             * and answers `duplicate` instead of ringing it up again.
             *
             * A client-supplied identifier is safe here because of the unique
             * index on (business_id, offline_temp_id): the worst a caller can do
             * by choosing one is have their own second submission refused, which
             * is also what happens when a cashier hits Back and re-posts — and
             * refusing that is the correct answer.
             */
            'offline_temp_id' => 'nullable|string|max:64',
            'offline_device_id' => 'nullable|string|max:64',
        ]);

        /*
         * A hidden field that was never filled in arrives as '', and '' is a
         * value: the unique index on (business_id, offline_temp_id) would accept
         * the first empty string and refuse every sale after it. NULL is the only
         * thing that index treats as "no id", so an empty one is dropped
         * altogether rather than passed along.
         */
        foreach (['offline_temp_id', 'offline_device_id'] as $key) {
            if (($validated[$key] ?? '') === '') {
                unset($validated[$key]);
            }
        }

        $lines = collect($validated['lines'])
            ->filter(fn ($line) => $this->format->numUf($line['quantity'] ?? 0) > 0)
            ->values()
            ->all();

        if (empty($lines)) {
            return back()->withInput()->with('status', [
                'success' => 0,
                'msg' => __('lang_v1.nothing_to_sell'),
            ]);
        }

        try {
            $sale = $this->sells->create(
                collect($validated)->except(['lines', 'payments'])->all() + [
                    'status' => TransactionTypes::STATUS_FINAL,
                    'transaction_date' => $this->format->ufDate(
                        $validated['transaction_date'] ?? null, true
                    ) ?? now(),
                    'created_by' => auth()->id(),
                ],
                $lines,
                collect($request->input('payments', []))
                    ->filter(fn ($payment) => $this->format->numUf($payment['amount'] ?? 0) > 0)
                    ->values()
                    ->all()
            );
        } catch (\Throwable $e) {
            return back()->withInput()->with('status', $this->failed($e));
        }

        /*
         * Back to an empty terminal rather than to the invoice: the next customer
         * is already at the counter. The receipt is reachable from the banner, so
         * the sale is not lost — it just is not what the screen becomes.
         *
         * The receipt link carries `auto=1` and opens in its own tab, so the one
         * gesture a clerk makes after ringing up a sale — hand over the paper —
         * is a single click that lands on a print dialog, and the terminal stays
         * where it is behind it.
         *
         * `offline_acknowledged` is the acknowledgement the terminal needs to
         * clear its write-ahead copy of this sale. Flashed separately rather than
         * folded into `status`: that key is read by the banner partial on every
         * screen in the application, and it is not the place to put a value only
         * one screen understands.
         */
        return redirect()->route('pos.create')
            ->with('offline_acknowledged', $sale->offline_temp_id)
            ->with('status', [
                'success' => 1,
                'msg' => __('lang_v1.sale_completed'),
                'links' => [
                    [
                        'url' => route('print.receipt', ['id' => $sale->id, 'auto' => 1]),
                        'label' => __('lang_v1.print_receipt'),
                        'blank' => true,
                    ],
                    [
                        'url' => route('sells.show', $sale->id),
                        'label' => $sale->invoice_no,
                    ],
                ],
            ]);
    }
}
