<?php

namespace App\Services;

use App\Events\SellCreatedOrModified;
use App\Models\Contact;
use App\Models\Transaction;
use App\Models\TransactionSellLine;
use App\Models\Variation;
use App\Support\TransactionTypes;
use Illuminate\Support\Facades\DB;

/**
 * Sales: invoices, drafts, quotations, POS orders and returns.
 *
 * Every write runs in one DB transaction that covers the document, its lines,
 * the stock cache, the FIFO map and the payments — so a sale can never be
 * half-recorded.
 */
class SellService
{
    public function __construct(
        private StockService $stock,
        private PaymentService $payments,
        private ProductService $products,
        private ReferenceService $references,
        private FormattingService $format,
    ) {}

    /* ====================================================================
     | Create
     ==================================================================== */

    /**
     * Record a sale, quotation, draft or sales order.
     *
     * `$type` mirrors PurchaseService::create() so the sell-side documents that
     * differ only by type and status vocabulary share one code path. A
     * `sales_order` reserves nothing: Transaction::affectsStock() returns false
     * for it, and syncLines() consults that flag before touching stock.
     *
     * @param  array<string, mixed>  $data     document fields
     * @param  array<int, array<string, mixed>>  $lines    products sold
     * @param  array<int, array<string, mixed>>  $payments
     */
    public function create(
        array $data,
        array $lines,
        array $payments = [],
        string $type = TransactionTypes::SELL
    ): Transaction {
        return DB::transaction(function () use ($data, $lines, $payments, $type) {
            $status = $data['status'] ?? $this->defaultStatusFor($type);
            $isQuotation = (bool) ($data['is_quotation'] ?? false);

            $transaction = Transaction::create([
                'business_id' => $data['business_id'] ?? \App\Support\Tenancy::id(),
                'location_id' => $data['location_id'],
                'type' => $type,
                'sub_type' => $data['sub_type'] ?? null,
                'status' => $status,
                'is_quotation' => $isQuotation,
                'payment_status' => TransactionTypes::DUE,
                'contact_id' => $data['contact_id'],
                'customer_group_id' => $data['customer_group_id'] ?? null,
                'invoice_no' => $data['invoice_no'] ?? $this->documentNumberFor(
                    $type, (int) $data['location_id'], $isQuotation
                ),
                'ref_no' => $data['ref_no'] ?? null,
                'sales_order_ids' => $data['sales_order_ids'] ?? null,
                'transaction_date' => $data['transaction_date'] ?? now(),
                'selling_price_group_id' => $data['selling_price_group_id'] ?? null,
                'discount_type' => $data['discount_type'] ?? null,
                'discount_amount' => $this->format->numUf($data['discount_amount'] ?? 0),
                'tax_id' => $data['tax_id'] ?? null,
                'shipping_details' => $data['shipping_details'] ?? null,
                'shipping_address' => $data['shipping_address'] ?? null,
                'shipping_status' => $data['shipping_status'] ?? null,
                'delivered_to' => $data['delivered_to'] ?? null,
                'delivery_person' => $data['delivery_person'] ?? null,
                'delivery_date' => $data['delivery_date'] ?? null,
                'shipping_charges' => $this->format->numUf($data['shipping_charges'] ?? 0),
                'round_off_amount' => $this->format->numUf($data['round_off_amount'] ?? 0),
                'additional_notes' => $data['additional_notes'] ?? null,
                'staff_note' => $data['staff_note'] ?? null,
                'is_direct_sale' => $data['is_direct_sale'] ?? 0,
                'is_suspend' => $data['is_suspend'] ?? 0,
                'commission_agent' => $data['commission_agent'] ?? null,
                'pay_term_number' => $data['pay_term_number'] ?? null,
                'pay_term_type' => $data['pay_term_type'] ?? null,
                'exchange_rate' => $this->format->numUf($data['exchange_rate'] ?? 1) ?: 1,
                'invoice_token' => $this->references->token(),
                'created_by' => $data['created_by'] ?? auth()->id(),
                // Offline (PWA) provenance
                'offline_temp_id' => $data['offline_temp_id'] ?? null,
                'offline_invoice_no' => $data['offline_invoice_no'] ?? null,
                'offline_device_id' => $data['offline_device_id'] ?? null,
                'offline_created_at' => $data['offline_created_at'] ?? null,
                'final_total' => 0,
            ]);

            $this->syncLines($transaction, $lines);
            $this->recalculateTotals($transaction);

            foreach ($payments as $payment) {
                $this->payments->addPayment($transaction, $payment);
            }

            $this->payments->refreshPaymentStatus($transaction);
            $this->updateSourceOrders($transaction);

            event(new SellCreatedOrModified($transaction));

            return $transaction->fresh(['sell_lines', 'payment_lines']);
        });
    }

    /* ====================================================================
     | Update
     ==================================================================== */

    /**
     * Update a sale, re-deriving stock and FIFO from scratch.
     *
     * Replacing the mapping wholesale is deliberate: incremental diffing is
     * where the original implementation drifted.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function update(Transaction $transaction, array $data, array $lines): Transaction
    {
        return DB::transaction(function () use ($transaction, $data, $lines) {
            $wasAffectingStock = $transaction->affectsStock();

            $transaction->fill(array_filter([
                'contact_id' => $data['contact_id'] ?? null,
                'status' => $data['status'] ?? null,
                'is_quotation' => $data['is_quotation'] ?? null,
                'transaction_date' => $data['transaction_date'] ?? null,
                'discount_type' => $data['discount_type'] ?? null,
                'tax_id' => $data['tax_id'] ?? null,
                'shipping_details' => $data['shipping_details'] ?? null,
                'shipping_address' => $data['shipping_address'] ?? null,
                'shipping_status' => $data['shipping_status'] ?? null,
                'delivered_to' => $data['delivered_to'] ?? null,
                'delivery_person' => $data['delivery_person'] ?? null,
                'delivery_date' => $data['delivery_date'] ?? null,
                'additional_notes' => $data['additional_notes'] ?? null,
                'staff_note' => $data['staff_note'] ?? null,
                'pay_term_number' => $data['pay_term_number'] ?? null,
                'pay_term_type' => $data['pay_term_type'] ?? null,
                'selling_price_group_id' => $data['selling_price_group_id'] ?? null,
                'customer_group_id' => $data['customer_group_id'] ?? null,
                'commission_agent' => $data['commission_agent'] ?? null,
                'sales_order_ids' => $data['sales_order_ids'] ?? null,
            ], fn ($v) => ! is_null($v)));

            foreach (['discount_amount', 'shipping_charges', 'round_off_amount'] as $money) {
                if (array_key_exists($money, $data)) {
                    $transaction->{$money} = $this->format->numUf($data[$money]);
                }
            }

            $transaction->save();

            // Return all previously consumed stock, then re-consume.
            if ($wasAffectingStock) {
                $this->releaseAllLines($transaction);
            }

            $this->syncLines($transaction, $lines);
            $this->recalculateTotals($transaction);
            $this->payments->refreshPaymentStatus($transaction);
            $this->updateSourceOrders($transaction);

            event(new SellCreatedOrModified($transaction));

            return $transaction->fresh(['sell_lines', 'payment_lines']);
        });
    }

    /* ====================================================================
     | Delete
     ==================================================================== */

    /**
     * Delete a sale and undo its stock effect.
     */
    public function delete(Transaction $transaction): void
    {
        DB::transaction(function () use ($transaction) {
            $transaction->loadMissing(['sell_lines', 'payment_lines']);

            // Remember which sales-order lines this sale was fulfilling: their
            // invoiced totals can only be recomputed once the sale is gone.
            $orderLineIds = $transaction->sell_lines
                ->pluck('so_line_id')
                ->filter()
                ->unique()
                ->all();

            if ($transaction->affectsStock()) {
                $this->releaseAllLines($transaction);
            }

            foreach ($transaction->payment_lines as $payment) {
                $this->payments->deletePayment($payment);
            }

            // Cascades to sell_lines via the FK.
            $transaction->delete();

            $this->refreshOrderLines($orderLineIds);
        });
    }

    /* ====================================================================
     | Returns
     ==================================================================== */

    /**
     * Record a return against a sale.
     *
     * @param  array<int, array{sell_line_id: int, quantity: float}>  $returnLines
     */
    public function addReturn(Transaction $sale, array $returnLines, array $data = []): Transaction
    {
        return DB::transaction(function () use ($sale, $returnLines, $data) {
            $sale->loadMissing('return_parent');

            $return = $sale->return_parent ?? Transaction::create([
                'business_id' => $sale->business_id,
                'location_id' => $sale->location_id,
                'type' => TransactionTypes::SELL_RETURN,
                'status' => TransactionTypes::STATUS_FINAL,
                'payment_status' => TransactionTypes::DUE,
                'contact_id' => $sale->contact_id,
                'return_parent_id' => $sale->id,
                'invoice_no' => $this->references->generate('sell_return'),
                'transaction_date' => $data['transaction_date'] ?? now(),
                'created_by' => $data['created_by'] ?? auth()->id(),
                'final_total' => 0,
            ]);

            $total = 0.0;

            foreach ($returnLines as $line) {
                $sellLine = TransactionSellLine::where('transaction_id', $sale->id)
                    ->findOrFail($line['sell_line_id']);

                $quantity = $this->format->numUf($line['quantity']);
                $alreadyReturned = (float) $sellLine->quantity_returned;
                $returnable = round((float) $sellLine->quantity - $alreadyReturned, 4);

                if ($quantity <= 0) {
                    continue;
                }

                if ($quantity > $returnable + 0.0001) {
                    throw new \RuntimeException(__('lang_v1.return_exceeds_sold', [
                        'max' => $this->format->quantity($returnable),
                    ]));
                }

                // Credit the lots and the stock cache.
                $this->stock->returnToLots($sellLine->id, $quantity, 'sell');
                $this->stock->adjustCachedQuantity(
                    $sale->location_id, $sellLine->product_id, $sellLine->variation_id, $quantity
                );

                $sellLine->quantity_returned = round($alreadyReturned + $quantity, 4);
                $sellLine->save();

                $total += $quantity * (float) $sellLine->unit_price_inc_tax;
            }

            $return->final_total = round($total, 4);
            $return->save();

            $this->payments->refreshPaymentStatus($return);

            return $return->fresh();
        });
    }

    /* ====================================================================
     | Stock validation
     ==================================================================== */

    /**
     * Check every line has stock before committing, so the POS can warn
     * instead of overselling.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array{variation_id: int, name: string, requested: float, available: float}>
     */
    public function findStockShortfalls(int $locationId, array $lines): array
    {
        $shortfalls = [];

        foreach ($lines as $line) {
            $variation = Variation::with('product')->find($line['variation_id'] ?? null);

            if (empty($variation) || ! $variation->product->enable_stock) {
                continue;
            }

            $requested = $this->format->numUf($line['quantity'] ?? 0);

            $available = $variation->product->isCombo()
                ? $this->products->comboAvailableQuantity($variation, $locationId)
                : $this->stock->currentStock($variation->id, $locationId);

            if ($requested > $available + 0.0001) {
                $shortfalls[] = [
                    'variation_id' => $variation->id,
                    'name' => $variation->full_name,
                    'requested' => $requested,
                    'available' => $available,
                ];
            }
        }

        return $shortfalls;
    }

    /* ====================================================================
     | Internals
     ==================================================================== */

    /**
     * Replace the sale's lines and re-consume stock FIFO.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    protected function syncLines(Transaction $transaction, array $lines): void
    {
        $keptIds = [];
        $affectsStock = $transaction->affectsStock();

        foreach ($lines as $input) {
            $variation = Variation::with('product')->findOrFail($input['variation_id']);

            $quantity = $this->format->numUf($input['quantity'] ?? 0);

            if ($quantity <= 0) {
                continue;
            }

            $unitPrice = $this->format->numUf($input['unit_price'] ?? 0);
            $unitPriceIncTax = $this->format->numUf(
                $input['unit_price_inc_tax'] ?? $unitPrice
            );

            $line = TransactionSellLine::updateOrCreate(
                [
                    'id' => $input['transaction_sell_lines_id'] ?? null,
                    'transaction_id' => $transaction->id,
                ],
                [
                    'product_id' => $variation->product_id,
                    'variation_id' => $variation->id,
                    'quantity' => $quantity,
                    'secondary_unit_quantity' => $this->format->numUf($input['secondary_unit_quantity'] ?? 0),
                    'unit_price_before_discount' => $this->format->numUf(
                        $input['unit_price_before_discount'] ?? $unitPrice
                    ),
                    'unit_price' => $unitPrice,
                    'line_discount_type' => $input['line_discount_type'] ?? null,
                    'line_discount_amount' => $this->format->numUf($input['line_discount_amount'] ?? 0),
                    'unit_price_inc_tax' => $unitPriceIncTax,
                    'item_tax' => $this->format->numUf($input['item_tax'] ?? 0),
                    'tax_id' => $input['tax_id'] ?? null,
                    'discount_id' => $input['discount_id'] ?? null,
                    'lot_no_line_id' => $input['lot_no_line_id'] ?? null,
                    'sub_unit_id' => $input['sub_unit_id'] ?? null,
                    'sell_line_note' => $input['sell_line_note'] ?? null,
                    'so_line_id' => $input['so_line_id'] ?? null,
                ]
            );

            $keptIds[] = $line->id;

            if (! $affectsStock || ! $variation->product->enable_stock) {
                continue;
            }

            if ($variation->product->isCombo()) {
                /*
                 * The child ids have to join `$keptIds`, or the sweep below —
                 * which deletes every line on the transaction that is not in it
                 * — deletes the component lines this call has just created, and
                 * releases the stock it has just consumed. See the note there.
                 */
                $keptIds = array_merge(
                    $keptIds,
                    $this->consumeComboComponents($transaction, $variation, $quantity, $line->id)
                );

                continue;
            }

            $this->stock->consume(
                $variation->id,
                $transaction->location_id,
                $quantity,
                $line->id,
                'sell',
                $input['lot_no_line_id'] ?? null
            );

            $this->stock->adjustCachedQuantity(
                $transaction->location_id, $variation->product_id, $variation->id, -$quantity
            );
        }

        /*
         * Drop lines removed by the edit, returning their stock first.
         *
         * This sweep is deliberately "everything not kept", which makes it the
         * one place a line can be deleted without anybody asking for it: any
         * line created during the loop above whose id does not reach `$keptIds`
         * is destroyed here, moments after being written. That is what happened
         * to combo component lines until the `array_merge` above — the sale kept
         * its parent line, lost its children, released the stock it had just
         * consumed, and left nothing in the FIFO map, so the profit report
         * showed every combo as pure margin at no cost.
         *
         * On an edit this is still correct and still what we want: the previous
         * save's children are not in `$keptIds`, so they are released and
         * deleted, while the ones just created are kept.
         */
        $removed = TransactionSellLine::where('transaction_id', $transaction->id)
            ->when($keptIds, fn ($q) => $q->whereNotIn('id', $keptIds))
            ->get();

        foreach ($removed as $line) {
            if ($affectsStock) {
                $released = $this->stock->release($line->id, 'sell');
                $this->stock->adjustCachedQuantity(
                    $transaction->location_id, $line->product_id, $line->variation_id, $released
                );
            }

            $line->delete();
        }
    }

    /**
     * A combo consumes its components, not itself.
     *
     * @return array<int, int> ids of the child lines created — the caller must
     *                         add these to its kept-line list, or the cleanup
     *                         sweep in {@see syncLines()} deletes them again
     */
    protected function consumeComboComponents(
        Transaction $transaction,
        Variation $combo,
        float $quantity,
        int $parentLineId
    ): array {
        $childIds = [];

        foreach ((array) $combo->combo_variations as $component) {
            $componentVariation = Variation::with('product')->find($component['variation_id']);

            if (empty($componentVariation)) {
                continue;
            }

            $needed = round($quantity * (float) $component['quantity'], 4);

            $childLine = TransactionSellLine::create([
                'transaction_id' => $transaction->id,
                'product_id' => $componentVariation->product_id,
                'variation_id' => $componentVariation->id,
                'quantity' => $needed,
                'unit_price' => 0,
                'unit_price_inc_tax' => 0,
                'item_tax' => 0,
                'parent_sell_line_id' => $parentLineId,
                'children_type' => 'combo',
            ]);

            $childIds[] = $childLine->id;

            $this->stock->consume(
                $componentVariation->id, $transaction->location_id, $needed, $childLine->id, 'sell'
            );

            $this->stock->adjustCachedQuantity(
                $transaction->location_id,
                $componentVariation->product_id,
                $componentVariation->id,
                -$needed
            );
        }

        return $childIds;
    }

    /**
     * Return every line's stock to its lots (used before an edit or delete).
     */
    protected function releaseAllLines(Transaction $transaction): void
    {
        $transaction->loadMissing('sell_lines');

        foreach ($transaction->sell_lines as $line) {
            $released = $this->stock->release($line->id, 'sell');

            if ($released > 0) {
                $this->stock->adjustCachedQuantity(
                    $transaction->location_id, $line->product_id, $line->variation_id, $released
                );
            }
        }
    }

    /* ====================================================================
     | Sales-order fulfilment
     ==================================================================== */

    /**
     * Roll fulfilment progress back onto the sales-order lines this document
     * invoices, then re-derive each order's status.
     */
    protected function updateSourceOrders(Transaction $transaction): void
    {
        if ($transaction->type !== TransactionTypes::SELL) {
            return;
        }

        $this->refreshOrderLines(
            TransactionSellLine::where('transaction_id', $transaction->id)
                ->whereNotNull('so_line_id')
                ->distinct()
                ->pluck('so_line_id')
                ->all()
        );
    }

    /**
     * Recompute `so_quantity_invoiced` for the given sales-order lines and
     * refresh the status of every order they belong to.
     *
     * @param  array<int, int|string>  $orderLineIds
     */
    protected function refreshOrderLines(array $orderLineIds): void
    {
        $orderIds = [];

        foreach ($orderLineIds as $orderLineId) {
            $orderLine = TransactionSellLine::find($orderLineId);

            if (empty($orderLine)) {
                continue;
            }

            // Sum every invoice line raised against this order line.
            $orderLine->so_quantity_invoiced = (float) TransactionSellLine::where(
                'so_line_id', $orderLine->id
            )->sum('quantity');
            $orderLine->save();

            $orderIds[$orderLine->transaction_id] = true;
        }

        foreach (array_keys($orderIds) as $orderId) {
            $order = Transaction::find($orderId);

            if (! empty($order)) {
                $this->refreshOrderStatus($order);
            }
        }
    }

    /**
     * Derive a sales order's status from how much of it has been invoiced:
     * ordered → partial → completed.
     */
    public function refreshOrderStatus(Transaction $order): Transaction
    {
        if ($order->type !== TransactionTypes::SALES_ORDER) {
            return $order;
        }

        $ordered = 0.0;
        $invoiced = 0.0;

        foreach ($order->sell_lines()->get() as $line) {
            $ordered += (float) $line->quantity;
            $invoiced += (float) $line->so_quantity_invoiced;
        }

        $order->status = match (true) {
            $invoiced <= 0.0001 => TransactionTypes::STATUS_ORDERED,
            $invoiced + 0.0001 >= $ordered => TransactionTypes::STATUS_COMPLETED,
            default => TransactionTypes::STATUS_PARTIAL,
        };

        $order->save();

        return $order;
    }

    /**
     * Recompute the document totals from its lines.
     */
    public function recalculateTotals(Transaction $transaction): Transaction
    {
        // Combo child lines carry no money — they would double-count.
        $lines = $transaction->sell_lines()->where('children_type', '!=', 'combo')->get();

        $totalBeforeTax = 0.0;
        $lineTax = 0.0;

        foreach ($lines as $line) {
            $totalBeforeTax += (float) $line->quantity * (float) $line->unit_price;
            $lineTax += (float) $line->quantity * (float) $line->item_tax;
        }

        $discount = $transaction->discount_type === 'percentage'
            ? $this->format->calcPercentage($totalBeforeTax, (float) $transaction->discount_amount)
            : (float) $transaction->discount_amount;

        $afterDiscount = max(0, $totalBeforeTax - $discount);

        $orderTax = 0.0;

        if (! empty($transaction->tax_id)) {
            // loadMissing, not `->tax`: on the update path the document was
            // fetched without eager loads and lazy loading is barred locally.
            $transaction->loadMissing('tax');

            $rate = (float) ($transaction->tax->amount ?? 0);
            $orderTax = $this->format->calcPercentage($afterDiscount, $rate);
        }

        $transaction->total_before_tax = round($totalBeforeTax, 4);
        $transaction->tax_amount = round($orderTax, 4);
        $transaction->final_total = round(
            $afterDiscount
            + $lineTax
            + $orderTax
            + (float) $transaction->shipping_charges
            + (float) $transaction->round_off_amount
            - (float) $transaction->rp_redeemed_amount,
            4
        );

        $transaction->save();

        return $transaction;
    }

    /**
     * Turn a draft or quotation into a final invoice, consuming stock now.
     */
    public function convertToInvoice(Transaction $draft): Transaction
    {
        return DB::transaction(function () use ($draft) {
            $draft->status = TransactionTypes::STATUS_FINAL;
            $draft->is_quotation = 0;
            $draft->invoice_no = $this->references->invoiceNumber($draft->location_id);
            $draft->save();

            $draft->loadMissing('sell_lines');

            // Drafts never held stock — consume it now.
            foreach ($draft->sell_lines as $line) {
                $variation = Variation::with('product')->find($line->variation_id);

                if (empty($variation) || ! $variation->product->enable_stock) {
                    continue;
                }

                $this->stock->consume(
                    $line->variation_id, $draft->location_id, (float) $line->quantity, $line->id, 'sell'
                );

                $this->stock->adjustCachedQuantity(
                    $draft->location_id, $line->product_id, $line->variation_id, -(float) $line->quantity
                );
            }

            $this->payments->refreshPaymentStatus($draft);

            event(new SellCreatedOrModified($draft));

            return $draft->fresh();
        });
    }

    /**
     * Credit limit check — returns the amount by which a sale would breach it.
     */
    public function creditLimitExceededBy(Contact $contact, float $saleTotal, float $paying = 0): float
    {
        if (empty($contact->credit_limit)) {
            return 0.0;
        }

        $outstanding = (float) Transaction::where('contact_id', $contact->id)
            ->whereIn('type', [TransactionTypes::SELL, TransactionTypes::OPENING_BALANCE])
            ->whereIn('payment_status', [TransactionTypes::DUE, TransactionTypes::PARTIAL])
            ->sum('final_total');

        $paid = (float) \App\Models\TransactionPayment::where('payment_for', $contact->id)
            ->where('is_return', 0)
            ->sum('amount');

        $projected = round($outstanding - $paid + $saleTotal - $paying, 4);

        return max(0.0, round($projected - (float) $contact->credit_limit, 4));
    }

    /* ====================================================================
     | Type vocabulary
     ==================================================================== */

    /**
     * A sale is final unless told otherwise; a sales order starts as ordered.
     */
    protected function defaultStatusFor(string $type): string
    {
        return match ($type) {
            TransactionTypes::SALES_ORDER => TransactionTypes::STATUS_ORDERED,
            default => TransactionTypes::STATUS_FINAL,
        };
    }

    /**
     * Sales, drafts and quotations draw from the location's invoice scheme so
     * numbering stays continuous when a draft becomes an invoice. Sales orders
     * are not invoices, so they take the tenant's `SO` counter instead.
     */
    protected function documentNumberFor(string $type, int $locationId, bool $isQuotation): string
    {
        return match ($type) {
            TransactionTypes::SALES_ORDER => $this->references->generate('sales_order'),
            default => $this->references->invoiceNumber($locationId, $isQuotation),
        };
    }
}
