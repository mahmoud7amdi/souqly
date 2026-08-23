<?php

namespace App\Services;

use App\Events\PurchaseCreatedOrModified;
use App\Models\PaymentTerm;
use App\Models\PurchaseLine;
use App\Models\Transaction;
use App\Models\Variation;
use App\Support\TransactionTypes;
use Illuminate\Support\Facades\DB;

/**
 * The purchase side of the P2P cycle: requisitions, orders, invoices and
 * returns, plus payment terms and purchase-driven price updates.
 */
class PurchaseService
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
     * Record a purchase, purchase order or requisition.
     *
     * Stock only moves for a `purchase` whose status is `received`; orders and
     * requisitions are commitments, not movements.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<int, array<string, mixed>>  $payments
     */
    public function create(
        array $data,
        array $lines,
        array $payments = [],
        string $type = TransactionTypes::PURCHASE
    ): Transaction {
        return DB::transaction(function () use ($data, $lines, $payments, $type) {
            $transaction = Transaction::create([
                'business_id' => $data['business_id'] ?? \App\Support\Tenancy::id(),
                'location_id' => $data['location_id'],
                'type' => $type,
                'status' => $data['status'] ?? $this->defaultStatusFor($type),
                'payment_status' => TransactionTypes::DUE,
                'contact_id' => $data['contact_id'] ?? null,
                'ref_no' => $data['ref_no'] ?? $this->references->generate($this->refTypeFor($type)),
                'transaction_date' => $data['transaction_date'] ?? now(),
                'tax_id' => $data['tax_id'] ?? null,
                'discount_type' => $data['discount_type'] ?? null,
                'discount_amount' => $this->format->numUf($data['discount_amount'] ?? 0),
                'shipping_details' => $data['shipping_details'] ?? null,
                'shipping_charges' => $this->format->numUf($data['shipping_charges'] ?? 0),
                'additional_notes' => $data['additional_notes'] ?? null,
                'pay_term_number' => $data['pay_term_number'] ?? null,
                'pay_term_type' => $data['pay_term_type'] ?? null,
                'exchange_rate' => $this->format->numUf($data['exchange_rate'] ?? 1) ?: 1,
                'purchase_order_ids' => $data['purchase_order_ids'] ?? null,
                'purchase_requisition_ids' => $data['purchase_requisition_ids'] ?? null,
                'delivery_date' => $data['delivery_date'] ?? null,
                'created_by' => $data['created_by'] ?? auth()->id(),
                'final_total' => 0,
            ]);

            // Additional expenses charged on the document (freight, customs…).
            for ($i = 1; $i <= 4; $i++) {
                if (! empty($data['additional_expense_key_'.$i])) {
                    $transaction->{'additional_expense_key_'.$i} = $data['additional_expense_key_'.$i];
                    $transaction->{'additional_expense_value_'.$i} = $this->format->numUf(
                        $data['additional_expense_value_'.$i] ?? 0
                    );
                }
            }
            $transaction->save();

            $this->syncLines($transaction, $lines);
            $this->recalculateTotals($transaction);
            $this->syncPaymentTerms($transaction, $data['terms'] ?? []);

            foreach ($payments as $payment) {
                $this->payments->addPayment($transaction, $payment);
            }

            $this->payments->refreshPaymentStatus($transaction);
            $this->updateSourceDocumentStatuses($transaction);

            event(new PurchaseCreatedOrModified($transaction));

            return $transaction->fresh(['purchase_lines', 'payment_lines', 'terms']);
        });
    }

    /* ====================================================================
     | Update
     ==================================================================== */

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function update(Transaction $transaction, array $data, array $lines): Transaction
    {
        return DB::transaction(function () use ($transaction, $data, $lines) {
            $wasReceived = $transaction->affectsStock();

            $transaction->fill(array_filter([
                'contact_id' => $data['contact_id'] ?? null,
                'status' => $data['status'] ?? null,
                'transaction_date' => $data['transaction_date'] ?? null,
                'ref_no' => $data['ref_no'] ?? null,
                'tax_id' => $data['tax_id'] ?? null,
                'discount_type' => $data['discount_type'] ?? null,
                'additional_notes' => $data['additional_notes'] ?? null,
                'pay_term_number' => $data['pay_term_number'] ?? null,
                'pay_term_type' => $data['pay_term_type'] ?? null,
            ], fn ($v) => ! is_null($v)));

            foreach (['discount_amount', 'shipping_charges', 'exchange_rate'] as $money) {
                if (array_key_exists($money, $data)) {
                    $transaction->{$money} = $this->format->numUf($data[$money]);
                }
            }

            $transaction->save();

            $isReceived = $transaction->affectsStock();

            $this->syncLines($transaction, $lines, $wasReceived, $isReceived);
            $this->recalculateTotals($transaction);
            $this->syncPaymentTerms($transaction, $data['terms'] ?? []);
            $this->payments->refreshPaymentStatus($transaction);
            $this->updateSourceDocumentStatuses($transaction);

            event(new PurchaseCreatedOrModified($transaction));

            return $transaction->fresh(['purchase_lines', 'payment_lines', 'terms']);
        });
    }

    /* ====================================================================
     | Delete
     ==================================================================== */

    public function delete(Transaction $transaction): void
    {
        DB::transaction(function () use ($transaction) {
            if ($transaction->affectsStock()) {
                foreach ($transaction->purchase_lines as $lot) {
                    $used = $this->stock->lotUsed($lot);

                    if ($used > 0.0001) {
                        throw new \RuntimeException(__('lang_v1.cannot_delete_purchase_stock_used', [
                            'qty' => $this->format->quantity($used),
                        ]));
                    }

                    $this->stock->adjustCachedQuantity(
                        $transaction->location_id,
                        $lot->product_id,
                        $lot->variation_id,
                        -(float) $lot->quantity
                    );
                }
            }

            foreach ($transaction->payment_lines as $payment) {
                $this->payments->deletePayment($payment);
            }

            $transaction->terms()->delete();
            $transaction->delete();
        });
    }

    /* ====================================================================
     | Returns
     ==================================================================== */

    /**
     * Return goods to a supplier.
     *
     * @param  array<int, array{purchase_line_id: int, quantity: float}>  $returnLines
     */
    public function addReturn(Transaction $purchase, array $returnLines, array $data = []): Transaction
    {
        return DB::transaction(function () use ($purchase, $returnLines, $data) {
            $return = $purchase->return_parent ?? Transaction::create([
                'business_id' => $purchase->business_id,
                'location_id' => $purchase->location_id,
                'type' => TransactionTypes::PURCHASE_RETURN,
                'status' => TransactionTypes::STATUS_FINAL,
                'payment_status' => TransactionTypes::DUE,
                'contact_id' => $purchase->contact_id,
                'return_parent_id' => $purchase->id,
                'ref_no' => $this->references->generate('purchase_return'),
                'transaction_date' => $data['transaction_date'] ?? now(),
                'created_by' => $data['created_by'] ?? auth()->id(),
                'final_total' => 0,
            ]);

            $total = 0.0;

            foreach ($returnLines as $input) {
                $lot = PurchaseLine::where('transaction_id', $purchase->id)
                    ->lockForUpdate()
                    ->findOrFail($input['purchase_line_id']);

                $quantity = $this->format->numUf($input['quantity']);

                if ($quantity <= 0) {
                    continue;
                }

                // Cannot return stock that has already been sold on.
                $available = $this->stock->lotRemaining($lot);

                if ($quantity > $available + 0.0001) {
                    throw new \RuntimeException(__('lang_v1.return_exceeds_available_lot', [
                        'max' => $this->format->quantity($available),
                    ]));
                }

                $lot->quantity_returned = round((float) $lot->quantity_returned + $quantity, 4);
                $lot->save();

                $this->stock->adjustCachedQuantity(
                    $purchase->location_id, $lot->product_id, $lot->variation_id, -$quantity
                );

                $total += $quantity * (float) $lot->purchase_price_inc_tax;
            }

            $return->final_total = round($total, 4);
            $return->save();

            $this->payments->refreshPaymentStatus($return);

            return $return->fresh();
        });
    }

    /* ====================================================================
     | Order status
     ==================================================================== */

    /**
     * Recompute a purchase order's fulfilment status from how much of it has
     * been invoiced.
     */
    public function refreshOrderStatus(Transaction $order): string
    {
        $lines = $order->purchase_lines;

        $ordered = (float) $lines->sum('quantity');
        $received = (float) $lines->sum('po_quantity_purchased');

        $status = match (true) {
            $received <= 0.0001 => TransactionTypes::STATUS_ORDERED,
            $received >= $ordered - 0.0001 => TransactionTypes::STATUS_COMPLETED,
            default => TransactionTypes::STATUS_PARTIAL,
        };

        if ($order->status !== $status) {
            $order->status = $status;
            $order->save();
        }

        return $status;
    }

    /* ====================================================================
     | Internals
     ==================================================================== */

    /**
     * Create/update the purchase lines (lots) and move stock as needed.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    protected function syncLines(
        Transaction $transaction,
        array $lines,
        bool $wasReceived = false,
        ?bool $isReceived = null
    ): void {
        $isReceived ??= $transaction->affectsStock();
        $keptIds = [];

        foreach ($lines as $input) {
            $variation = Variation::with('product')->findOrFail($input['variation_id']);

            $quantity = $this->format->numUf($input['quantity'] ?? 0);

            if ($quantity <= 0) {
                continue;
            }

            $existing = ! empty($input['purchase_line_id'])
                ? PurchaseLine::where('transaction_id', $transaction->id)
                    ->find($input['purchase_line_id'])
                : null;

            $previousQty = $existing ? (float) $existing->quantity : 0.0;

            $unitCost = $this->format->numUf($input['purchase_price'] ?? 0);
            $unitCostIncTax = $this->format->numUf($input['purchase_price_inc_tax'] ?? $unitCost);

            $attributes = [
                'product_id' => $variation->product_id,
                'variation_id' => $variation->id,
                'quantity' => $quantity,
                'secondary_unit_quantity' => $this->format->numUf($input['secondary_unit_quantity'] ?? 0),
                'pp_without_discount' => $this->format->numUf($input['pp_without_discount'] ?? $unitCost),
                'discount_percent' => $this->format->numUf($input['discount_percent'] ?? 0),
                'purchase_price' => $unitCost,
                'purchase_price_inc_tax' => $unitCostIncTax,
                'item_tax' => $this->format->numUf($input['item_tax'] ?? 0),
                'tax_id' => $input['tax_id'] ?? null,
                'lot_number' => $input['lot_number'] ?? null,
                'mfg_date' => $input['mfg_date'] ?? null,
                'exp_date' => $input['exp_date'] ?? null,
                'sub_unit_id' => $input['sub_unit_id'] ?? null,
                'purchase_order_line_id' => $input['purchase_order_line_id'] ?? null,
                'purchase_requisition_line_id' => $input['purchase_requisition_line_id'] ?? null,
            ];

            if ($existing) {
                // Refuse to shrink a lot below what has already gone out.
                if ($quantity < $previousQty) {
                    $this->stock->reduceLotQuantity($existing, $quantity);
                }

                $existing->fill($attributes)->save();
                $lot = $existing;
            } else {
                $lot = PurchaseLine::create(array_merge(
                    ['transaction_id' => $transaction->id],
                    $attributes
                ));
            }

            $keptIds[] = $lot->id;

            if (! $variation->product->enable_stock) {
                continue;
            }

            // Net stock delta for this line.
            $delta = match (true) {
                $isReceived && ! $wasReceived => $quantity,          // became received
                ! $isReceived && $wasReceived => -$previousQty,      // un-received
                $isReceived && $wasReceived => $quantity - $previousQty,
                default => 0.0,
            };

            if (abs($delta) > 0.0001) {
                $this->stock->adjustCachedQuantity(
                    $transaction->location_id, $variation->product_id, $variation->id, $delta
                );
            }

            if ($isReceived) {
                $existingStock = $this->stock->currentStock($variation->id, $transaction->location_id);

                $this->products->applyPurchasePrice(
                    $variation,
                    $unitCostIncTax,
                    max(0.0, $existingStock - $quantity),
                    $transaction->id
                );
            }
        }

        // Remove lines dropped by the edit.
        $removed = PurchaseLine::where('transaction_id', $transaction->id)
            ->when($keptIds, fn ($q) => $q->whereNotIn('id', $keptIds))
            ->get();

        foreach ($removed as $lot) {
            $used = $this->stock->lotUsed($lot);

            if ($used > 0.0001) {
                throw new \RuntimeException(__('lang_v1.cannot_remove_line_stock_used', [
                    'qty' => $this->format->quantity($used),
                ]));
            }

            if ($wasReceived) {
                $this->stock->adjustCachedQuantity(
                    $transaction->location_id,
                    $lot->product_id,
                    $lot->variation_id,
                    -(float) $lot->quantity
                );
            }

            $lot->delete();
        }
    }

    /**
     * Replace the document's payment schedule.
     *
     * @param  array<int, array{payment_term: mixed, due_date: string|null}>  $terms
     */
    protected function syncPaymentTerms(Transaction $transaction, array $terms): void
    {
        if (empty($terms)) {
            return;
        }

        $transaction->terms()->delete();

        $totalPercent = 0.0;

        foreach ($terms as $term) {
            $percent = $this->format->numUf($term['payment_term'] ?? 0);

            if ($percent <= 0) {
                continue;
            }

            $totalPercent += $percent;

            PaymentTerm::create([
                'purchase_transaction_id' => $transaction->id,
                'payment_term' => $percent,
                'due_date' => $term['due_date'] ?? null,
            ]);
        }

        if ($totalPercent > 100.0001) {
            throw new \RuntimeException(__('lang_v1.payment_terms_exceed_100', [
                'total' => $this->format->numF($totalPercent),
            ]));
        }
    }

    /**
     * Roll fulfilment progress back onto the source PO / requisition lines.
     */
    protected function updateSourceDocumentStatuses(Transaction $transaction): void
    {
        if ($transaction->type !== TransactionTypes::PURCHASE) {
            return;
        }

        $orderIds = [];

        foreach ($transaction->purchase_lines as $line) {
            if (empty($line->purchase_order_line_id)) {
                continue;
            }

            $orderLine = PurchaseLine::find($line->purchase_order_line_id);

            if (empty($orderLine)) {
                continue;
            }

            // Sum everything invoiced against this order line.
            $orderLine->po_quantity_purchased = (float) PurchaseLine::where(
                'purchase_order_line_id', $orderLine->id
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
     * Recompute totals from the lines plus document-level charges.
     */
    public function recalculateTotals(Transaction $transaction): Transaction
    {
        $lines = $transaction->purchase_lines;

        $totalBeforeTax = 0.0;
        $lineTax = 0.0;

        foreach ($lines as $line) {
            $totalBeforeTax += (float) $line->quantity * (float) $line->purchase_price;
            $lineTax += (float) $line->quantity * (float) $line->item_tax;
        }

        $discount = $transaction->discount_type === 'percentage'
            ? $this->format->calcPercentage($totalBeforeTax, (float) $transaction->discount_amount)
            : (float) $transaction->discount_amount;

        $afterDiscount = max(0, $totalBeforeTax - $discount);

        $orderTax = 0.0;

        if (! empty($transaction->tax_id)) {
            $orderTax = $this->format->calcPercentage(
                $afterDiscount,
                (float) ($transaction->tax->amount ?? 0)
            );
        }

        $additionalExpenses = 0.0;
        for ($i = 1; $i <= 4; $i++) {
            $additionalExpenses += (float) $transaction->{'additional_expense_value_'.$i};
        }

        $transaction->total_before_tax = round($totalBeforeTax, 4);
        $transaction->tax_amount = round($orderTax, 4);
        $transaction->final_total = round(
            $afterDiscount
            + $lineTax
            + $orderTax
            + (float) $transaction->shipping_charges
            + $additionalExpenses
            + (float) $transaction->round_off_amount,
            4
        );

        $transaction->save();

        return $transaction;
    }

    protected function defaultStatusFor(string $type): string
    {
        return match ($type) {
            TransactionTypes::PURCHASE => TransactionTypes::STATUS_RECEIVED,
            TransactionTypes::PURCHASE_ORDER,
            TransactionTypes::PURCHASE_REQUISITION => TransactionTypes::STATUS_ORDERED,
            default => TransactionTypes::STATUS_FINAL,
        };
    }

    protected function refTypeFor(string $type): string
    {
        return match ($type) {
            TransactionTypes::PURCHASE_ORDER => 'purchase_order',
            TransactionTypes::PURCHASE_REQUISITION => 'purchase_requisition',
            TransactionTypes::PURCHASE_RETURN => 'purchase_return',
            default => 'purchase',
        };
    }
}
