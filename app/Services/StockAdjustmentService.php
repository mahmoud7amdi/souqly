<?php

namespace App\Services;

use App\Models\StockAdjustmentLine;
use App\Models\Transaction;
use App\Models\Variation;
use App\Support\Tenancy;
use App\Support\TransactionTypes;
use Illuminate\Support\Facades\DB;

/**
 * Stock adjustments — goods leaving a location without a sale.
 *
 * Breakage, spoilage, theft, samples given away. The document exists so that the
 * loss is *recorded* rather than absorbed silently by the next stock count: it
 * consumes FIFO lots exactly as a sale does, so the units are traceable to the
 * purchase they came from and the write-off is valued at what those units
 * actually cost, not at a guessed average.
 *
 * Two decisions worth stating outright, because both are narrower than the
 * screen's name suggests:
 *
 * DECREASE ONLY. There is no way to adjust stock *upwards* here. An increase in
 * stock has a cost and therefore needs a lot to hang that cost on, and the
 * documents that create lots already exist — a purchase, or opening stock. An
 * "increase adjustment" would either invent units with no cost basis (and every
 * margin that touched them afterwards would be wrong) or silently borrow the
 * cost of a lot it did not come from. If a physical count comes out higher than
 * the books, the missing document is a purchase nobody recorded, and that is
 * what should be entered.
 *
 * NEVER PAST ZERO. `StockService::consume()` reports a shortfall rather than
 * refusing, because a POS sale must be allowed to oversell a mis-counted shelf
 * with a customer standing at the counter. An adjustment has no such excuse: it
 * is a deliberate act of bookkeeping, and writing off ten units when the system
 * holds three does not correct the discrepancy, it moves it somewhere harder to
 * find. So a shortfall aborts the whole document and says what was available.
 */
class StockAdjustmentService
{
    public function __construct(
        private StockService $stock,
        private ReferenceService $references,
        private FormattingService $format,
    ) {}

    /* ====================================================================
     | Create
     ==================================================================== */

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function create(array $data, array $lines): Transaction
    {
        return DB::transaction(function () use ($data, $lines) {
            $adjustment = Transaction::create([
                'business_id' => $data['business_id'] ?? Tenancy::id(),
                'location_id' => $data['location_id'],
                'type' => TransactionTypes::STOCK_ADJUSTMENT,
                // Received, not final: the stock has moved, and `affectsStock()`
                // treats every adjustment as effective regardless — the status
                // is here so the listings and the reports have one vocabulary.
                'status' => TransactionTypes::STATUS_RECEIVED,
                'adjustment_type' => $data['adjustment_type'] ?? 'normal',
                'ref_no' => ! empty($data['ref_no'])
                    ? $data['ref_no']
                    : $this->references->generate('stock_adjustment'),
                'transaction_date' => $data['transaction_date'] ?? now(),
                'total_amount_recovered' => $this->format->numUf($data['total_amount_recovered'] ?? 0),
                'additional_notes' => $data['additional_notes'] ?? null,
                'created_by' => $data['created_by'] ?? auth()->id(),
                'final_total' => 0,
            ]);

            $this->syncLines($adjustment, $lines);
            $this->recalculateTotals($adjustment);

            return $adjustment->fresh('stock_adjustment_lines');
        });
    }

    /* ====================================================================
     | Update
     ==================================================================== */

    /**
     * Rewrite an adjustment: reverse the whole document, then record it again.
     *
     * Not a per-line delta, deliberately. A purchase can compute deltas because
     * its lines *are* the lots; an adjustment's lines only point at lots, and by
     * the time somebody edits one those lots may have been consumed further by
     * sales, so "this line took 3 from lot 41" is no longer enough information
     * to take 2 instead. Releasing everything first puts the FIFO map back to a
     * known state and lets the same code path that created the document create
     * it again — one path to be correct instead of two.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function update(Transaction $adjustment, array $data, array $lines): Transaction
    {
        return DB::transaction(function () use ($adjustment, $data, $lines) {
            $this->releaseAllLines($adjustment);

            // Order matters: release() finds the map rows by line id, so the
            // lines cannot go first.
            $adjustment->stock_adjustment_lines()->delete();

            $adjustment->fill(array_filter([
                'location_id' => $data['location_id'] ?? null,
                'adjustment_type' => $data['adjustment_type'] ?? null,
                'ref_no' => $data['ref_no'] ?? null,
                'transaction_date' => $data['transaction_date'] ?? null,
                'additional_notes' => $data['additional_notes'] ?? null,
            ], fn ($value) => ! is_null($value)));

            if (array_key_exists('total_amount_recovered', $data)) {
                $adjustment->total_amount_recovered = $this->format->numUf(
                    $data['total_amount_recovered']
                );
            }

            $adjustment->save();

            $this->syncLines($adjustment, $lines);
            $this->recalculateTotals($adjustment);

            return $adjustment->fresh('stock_adjustment_lines');
        });
    }

    /* ====================================================================
     | Delete
     ==================================================================== */

    /**
     * Undo an adjustment: the written-off units go back to the lots they came
     * from, at the cost they were taken at.
     *
     * No guard is needed here, unlike deleting a purchase. Deleting a purchase
     * destroys lots that later documents may have consumed; deleting an
     * adjustment only *returns* quantity, which can never leave another
     * document referencing something that is gone.
     */
    public function delete(Transaction $adjustment): void
    {
        DB::transaction(function () use ($adjustment) {
            $this->releaseAllLines($adjustment);

            $adjustment->stock_adjustment_lines()->delete();
            $adjustment->delete();
        });
    }

    /* ====================================================================
     | Internals
     ==================================================================== */

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    protected function syncLines(Transaction $adjustment, array $lines): void
    {
        foreach ($lines as $input) {
            $variation = Variation::with('product')->findOrFail($input['variation_id']);

            $quantity = $this->format->numUf($input['quantity'] ?? 0);

            if ($quantity <= 0) {
                continue;
            }

            /*
             * A service or a non-stock product has no quantity to remove, so an
             * adjustment against it is a data-entry mistake rather than a small
             * one to round away. Refused loudly: silently dropping the line
             * would leave a document that says it wrote something off and did
             * not.
             */
            if (! $variation->product->enable_stock) {
                throw new \RuntimeException(__('lang_v1.cannot_adjust_untracked_product', [
                    'product' => $variation->full_name,
                ]));
            }

            $line = StockAdjustmentLine::create([
                'transaction_id' => $adjustment->id,
                'product_id' => $variation->product_id,
                'variation_id' => $variation->id,
                'quantity' => $quantity,
                'secondary_unit_quantity' => $this->format->numUf($input['secondary_unit_quantity'] ?? 0),
                'lot_no_line_id' => $input['lot_no_line_id'] ?? null,
                'unit_price' => 0,
            ]);

            $taken = $this->stock->consume(
                $variation->id,
                $adjustment->location_id,
                $quantity,
                $line->id,
                'stock_adjustment',
                $input['lot_no_line_id'] ?? null
            );

            // See the class comment: an adjustment never goes past zero. The
            // throw rolls back the surrounding transaction, so the lots this
            // call had already touched are restored with it.
            if ($taken['shortfall'] > 0.0001) {
                throw new \RuntimeException(__('lang_v1.adjustment_exceeds_stock', [
                    'product' => $variation->full_name,
                    'available' => $this->format->quantity($taken['allocated']),
                    'requested' => $this->format->quantity($quantity),
                ]));
            }

            /*
             * The FIFO cost of the units actually taken, per unit. The column's
             * schema comment calls it the last purchase price — this is a
             * deliberate improvement on that: a write-off of stock bought at
             * three different prices is worth what those specific units cost,
             * and the map rows consume() just wrote are what say so.
             */
            $line->unit_price = round($taken['cost'] / $quantity, 4);
            $line->save();

            $this->stock->adjustCachedQuantity(
                $adjustment->location_id, $variation->product_id, $variation->id, -$quantity
            );
        }
    }

    /**
     * Return every line's quantity to its lots.
     */
    protected function releaseAllLines(Transaction $adjustment): void
    {
        $adjustment->loadMissing('stock_adjustment_lines');

        foreach ($adjustment->stock_adjustment_lines as $line) {
            $released = $this->stock->release($line->id, 'stock_adjustment');

            if ($released > 0) {
                $this->stock->adjustCachedQuantity(
                    $adjustment->location_id, $line->product_id, $line->variation_id, $released
                );
            }
        }
    }

    /**
     * Value the document at what the written-off units cost.
     *
     * `total_amount_recovered` is not subtracted here. It is money coming back
     * from somewhere else — an insurer, a supplier credit, a staff deduction —
     * and netting it into `final_total` would hide the size of the loss behind
     * how well it was recovered. The two figures are shown side by side on the
     * screens instead.
     */
    public function recalculateTotals(Transaction $adjustment): Transaction
    {
        // A fresh query, not the loaded relation: update() releases (which loads
        // the relation), deletes the lines and writes new ones, so anything
        // cached on the model at this point describes the document as it was.
        $total = 0.0;

        foreach ($adjustment->stock_adjustment_lines()->get() as $line) {
            $total += (float) $line->quantity * (float) $line->unit_price;
        }

        $adjustment->total_before_tax = round($total, 4);
        $adjustment->final_total = round($total, 4);
        $adjustment->save();

        return $adjustment;
    }
}
