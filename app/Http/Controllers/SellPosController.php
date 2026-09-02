<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\BusinessLocation;
use App\Models\Contact;
use App\Models\Product;
use App\Models\SellingPriceGroup;
use App\Models\TaxRate;
use App\Models\Transaction;
use App\Services\FormattingService;
use App\Services\PaymentService;
use App\Services\SellService;
use App\Support\TenantRules;
use App\Support\TransactionTypes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        private PaymentService $payments,
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
            /*
             * Which contacts cannot hold credit, so the tender dialog can leave
             * the option out rather than offer one the server will refuse. Sent
             * as a list rather than a single id because `is_default` is not
             * declared unique and a tenant that has somehow ended up with two
             * walk-in rows must have both treated as anonymous.
             */
            'sharedCustomers' => Contact::query()->where('is_default', 1)->pluck('id')->all(),
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
            'location_id' => ['required', 'integer', TenantRules::location()],
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
             * What to do with money tendered above the sale total.
             *
             * `refund` is cash back over the counter and records nothing beyond
             * the sale: it is the historic behaviour and stays the default, so a
             * terminal whose script did not run still rings up sales correctly.
             * `credit` keeps the excess on the customer's account.
             *
             * The cashier does not get asked when the customer already owes
             * money — the excess goes against the debt, which is neither of these
             * two answers and needs no field: see settleOverpayment().
             */
            'overpay_action' => 'nullable|in:refund,credit',

            /*
             * How much was handed over above the total.
             *
             * Sent as its own field rather than by letting `payments[0][amount]`
             * exceed the total, because that amount means one exact thing on both
             * roads into this application — the part of the tender that belongs to
             * *this* sale — and the offline queue posts it under the same name.
             * Widening its meaning on the online path only would leave the two
             * paths recording different sales from the same keystrokes.
             *
             * A cashier-asserted figure, like every payment amount the terminal
             * sends: the server cannot see the counter. What keeps it honest is
             * the drawer, where claiming 500 against 320 received shows up as a
             * shortfall at close.
             */
            'overpay_amount' => 'nullable|numeric|min:0',

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
                collect($validated)->except([
                    'lines', 'payments', 'overpay_action', 'overpay_amount',
                ])->all() + [
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

            /*
             * Credit spent and change owed are mutually exclusive by construction:
             * applyAdvanceBalance() needs the sale to still be owed something and
             * settleOverpayment() needs it settled in full, so at most one of the
             * two ever records anything. Both are called unconditionally rather
             * than branched on here, so neither has to restate the other's rule.
             */
            $credit = DB::transaction(fn () => $this->payments->applyAdvanceBalance($sale));

            $overpayment = $this->settleOverpayment($sale, $validated);
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
                'msg' => $this->completionMessage($overpayment, $credit),
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

    /* ================================================================
     | Overpayment
     ================================================================ */

    /**
     * Put money tendered above the sale total where it belongs.
     *
     * The excess is settled against the CONTACT, never against the sale. Adding
     * it to the sale's own payment rows would push `amount_paid` past
     * `final_total` and leave `refreshPaymentStatus()` deriving `paid` from a
     * figure that overstates the invoice — the document would carry a payment for
     * money that was not owed on it.
     *
     * Against the contact, one existing call covers both of the outcomes worth
     * having: `payContactDue()` allocates down the open documents oldest-first
     * and turns whatever is left into advance balance. So a customer who owes
     * money has the excess taken off that debt without anybody being asked, and a
     * customer who owes nothing has it kept as credit — same code path, and the
     * cashier's choice only decides whether it is called at all.
     *
     * Ordering matters and is the reason this runs after the sale rather than
     * before it: by now the sale's own payment row has settled it in full, so
     * `openDocumentsFor()` — which filters to `due` and `partial` — will not see
     * the sale that was just rung up and cannot allocate the excess straight back
     * onto it.
     *
     * @param  array<string, mixed>  $validated
     * @return array{applied: float, credited: float, documents: int}|null
     */
    protected function settleOverpayment(Transaction $sale, array $validated): ?array
    {
        $excess = $this->format->numUf($validated['overpay_amount'] ?? 0);

        if ($excess <= 0.0001) {
            return null;
        }

        // `refund` is cash over the counter: nothing to record beyond the sale.
        if (($validated['overpay_action'] ?? 'refund') !== 'credit') {
            return null;
        }

        $contact = $sale->contact;

        /*
         * The walk-in customer is one shared row every counter sale is filed
         * against, so credit stored on it belongs to nobody: the next cashier
         * would find a balance with no way to tell whose money it was, and could
         * spend it on a different person's sale. Cash back is the only honest
         * answer for an anonymous customer, and the terminal does not offer the
         * choice — this is the server refusing to be talked into it.
         */
        if (! empty($contact) && $contact->is_default) {
            return null;
        }

        /*
         * A tender the sale did not fully absorb has no excess by definition, so
         * a payload claiming both is contradictory — and acting on it would mint
         * customer credit out of a partly-unpaid invoice. The sale stands; the
         * claim is dropped.
         */
        if (empty($contact) || $this->payments->amountDue($sale) > 0.0001) {
            return null;
        }

        $result = DB::transaction(fn () => $this->payments->payContactDue($contact, [
            'amount' => $excess,
            'method' => $validated['payments'][0]['method'] ?? 'cash',
            'account_id' => $validated['payments'][0]['account_id'] ?? null,
            'due_type' => 'sell',
            'created_by' => auth()->id(),
            'note' => __('lang_v1.overpayment_from_sale', ['invoice' => $sale->invoice_no]),
        ]));

        return [
            'applied' => round($excess - $result['unallocated'], 4),
            'credited' => $result['unallocated'],
            'documents' => count($result['children']),
        ];
    }

    /**
     * What the terminal says after the sale.
     *
     * The two things a counter sale can do that the customer cannot see happening
     * are moving their change somewhere other than their hand, and drawing on money
     * they left earlier — so both have to be said out loud. A customer told "your
     * change is 180" who actually had it credited will come back for the cash; a
     * customer whose prepayment silently paid for the goods has no way to check the
     * balance they are still owed unless the terminal states what is left of it.
     *
     * At most one of the two blocks ever fires, for the reason given at the call
     * site, but they are written independently rather than as an either/or so that
     * neither has to be revisited if that ever stops being true.
     *
     * @param  array{applied: float, credited: float, documents: int}|null  $overpayment
     * @param  array{applied: float, remaining_credit: float, still_due: float}|null  $credit
     */
    protected function completionMessage(?array $overpayment, ?array $credit = null): string
    {
        $message = __('lang_v1.sale_completed');

        if (! empty($credit)) {
            $message .= ' — '.__('lang_v1.advance_applied_to_sale', [
                'amount' => $this->format->currencyF($credit['applied']),
            ]);

            /*
             * One or the other, never both: the balance is spent down as far as the
             * sale needs, so a shortfall means the credit is now empty and a
             * remaining credit means there was no shortfall.
             */
            $message .= ' — '.($credit['still_due'] > 0.0001
                ? __('lang_v1.advance_still_due', [
                    'amount' => $this->format->currencyF($credit['still_due']),
                ])
                : __('lang_v1.advance_credit_remaining', [
                    'amount' => $this->format->currencyF($credit['remaining_credit']),
                ]));
        }

        if (empty($overpayment)) {
            return $message;
        }

        if ($overpayment['applied'] > 0.0001) {
            $message .= ' — '.__('lang_v1.overpay_applied_to_due', [
                'amount' => $this->format->currencyF($overpayment['applied']),
                'count' => $overpayment['documents'],
            ]);
        }

        if ($overpayment['credited'] > 0.0001) {
            $message .= ' — '.__('lang_v1.overpay_kept_as_credit', [
                'amount' => $this->format->currencyF($overpayment['credited']),
            ]);
        }

        return $message;
    }
}
